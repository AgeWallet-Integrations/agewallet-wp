<?php
/**
 * WooCommerce integration for the AgeWallet plugin.
 *
 * Adds the opt-in age-verification rule for buying products: the cart and checkout pages'
 * Verify my age message (with the express checkout buttons and Place order hidden until the
 * shopper verifies), the server-side refusal of orders placed by unverified shoppers, the
 * verification record on orders, the mask on listing cards of products whose page is gated,
 * and per-checkout metadata that round-trips through the AgeWallet verification flow.
 *
 * Loaded only when WooCommerce is active (gated in agewallet-oidc-client.php bootstrap).
 *
 * @package AgeWalletOIDCClient
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'AgeWallet_WooCommerce' ) ) {

	final class AgeWallet_WooCommerce {

		private static $instance = null;

		/**
		 * True between open_checkout_actions() / open_mini_cart_actions() and close_order_actions().
		 *
		 * @var bool
		 */
		private static $order_actions_open = false;

		/**
		 * True while the request is POST /wc/store/v1/checkout (flag_store_api_checkout()).
		 *
		 * @var bool
		 */
		private static $store_api_checkout = false;

		/**
		 * Orders given a verification record before their first save (guard_unverified_order()),
		 * keyed by spl_object_id(); the agewallet_wc_verified_order hook fires for them after the save.
		 *
		 * @var array<int, bool>
		 */
		private static $record_pending = array();

		/**
		 * Post meta key for per-product regulated status.
		 * Values: 'not_regulated' | 'regulated' | 'override_not_regulated' | (absent — treated as 'not_regulated')
		 */
		const META_KEY_PRODUCT_REGULATED_STATUS = '_agewallet_regulated_status';

		/**
		 * Term meta key for category/tag-level regulated flag.
		 * Value: '1' if regulated, absent/'' otherwise.
		 */
		const META_KEY_TERM_REGULATED = 'agewallet_regulated';

		/**
		 * Default per-product regulated status when no post meta is set.
		 */
		const PRODUCT_REGULATED_STATUS_DEFAULT = 'not_regulated';

		/**
		 * Order meta key for the verification record: the UTC time (Y-m-d H:i:s) the order was
		 * placed by a verified shopper. Absent on orders without a record.
		 */
		const META_KEY_ORDER_VERIFIED_AT = '_agewallet_verified_at';

		/**
		 * Order meta key set to '1' once monitor_order_status() has noted, logged and emailed that
		 * the order has no verification record.
		 */
		const META_KEY_ORDER_NO_RECORD_NOTIFIED = '_agewallet_no_record_notified';

		/**
		 * The Store API checkout route that places an order: POST /wc/store/v1/checkout
		 * (also /wc/store/checkout), matched in any letter case, as WordPress matches REST routes.
		 */
		const STORE_API_CHECKOUT_ROUTE = '#^/wc/store(?:/v\d+)?/checkout/?$#i';

		/**
		 * The Cart and Checkout blocks' express payment area as WooCommerce draws it in the browser:
		 * the cart's payment options, the express payment buttons, and the "Or" rule under them.
		 */
		const BLOCK_EXPRESS_PAYMENT_SELECTORS = array(
			'.wc-block-cart__payment-options',
			'.wc-block-components-express-payment',
			'.wc-block-components-express-payment-continue-rule',
		);

		/**
		 * The Checkout block's order buttons as WooCommerce draws them in the browser: everything in
		 * the Checkout Actions block's actions row except the Return to cart link, so Place order or
		 * the button the chosen payment method draws in its place. The block's other children, the
		 * Order summary it draws above the row on checkouts up to 920px wide and its notices area,
		 * are not matched.
		 */
		const BLOCK_PLACE_ORDER_SELECTOR = '.wp-block-woocommerce-checkout-actions-block .wc-block-checkout__actions_row > :not(.wc-block-components-checkout-return-to-cart-button)';

		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_filter( 'agewallet_metadata', array( $this, 'inject_checkout_metadata' ) );

			// The Age Restriction box leaves product edit screens: a product's viewing rule is part
			// of its Age verification choice (AgeWallet_Product_Flags).
			add_filter( 'agewallet_meta_box_post_types', array( $this, 'drop_product_meta_box' ) );

			// 1. Listing cards of products whose page is gated are masked (classic loops, the grid
			//    blocks and the Product Collection block). Listings are the same for every visitor,
			//    so they can be cached; the browser reveals the card from the verification cookie.
			add_action( 'woocommerce_before_shop_loop_item', array( $this, 'open_listing_card' ), PHP_INT_MIN );
			add_action( 'woocommerce_after_shop_loop_item', array( $this, 'close_listing_card' ), PHP_INT_MAX );
			add_filter( 'woocommerce_blocks_product_grid_item_html', array( $this, 'mask_product_grid_item' ), 20, 3 );
			add_filter( 'render_block_woocommerce/product-template', array( $this, 'mask_product_template_block' ), 20 );

			// 2. Cart, checkout and mini-cart, when the cart needs verification: the Verify my age
			//    message, with the express checkout buttons hidden (and Place order on the checkout).
			//    These aren't page-cached, so the server decides.
			add_action( 'woocommerce_proceed_to_checkout', array( $this, 'print_cart_notice' ), 1 );
			add_action( 'woocommerce_before_checkout_form', array( $this, 'print_checkout_notice' ), 1 );
			add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'open_checkout_actions' ), PHP_INT_MIN );
			add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'close_order_actions' ), 9 );
			add_action( 'woocommerce_review_order_before_submit', array( $this, 'open_checkout_actions' ), 1 );
			add_action( 'woocommerce_review_order_after_submit', array( $this, 'close_order_actions' ), 999 );
			add_action( 'woocommerce_review_order_after_payment', array( $this, 'open_checkout_actions' ), 1 );
			add_action( 'woocommerce_review_order_after_payment', array( $this, 'close_order_actions' ), 999 );
			add_action( 'woocommerce_widget_shopping_cart_after_buttons', array( $this, 'open_mini_cart_actions' ), PHP_INT_MIN );
			add_action( 'woocommerce_widget_shopping_cart_after_buttons', array( $this, 'close_order_actions' ), PHP_INT_MAX );
			add_filter( 'render_block_woocommerce/cart', array( $this, 'prepend_cart_block_notice' ) );
			add_filter( 'render_block_woocommerce/checkout', array( $this, 'prepend_checkout_block_notice' ) );

			// 3. Orders placed by unverified shoppers are refused on the server: at the classic
			//    checkout's validation, at the Store API's checkout (POST only), and, for payment
			//    plugins that build orders themselves, before the order or any of its lines is saved.
			add_filter( 'rest_request_before_callbacks', array( $this, 'flag_store_api_checkout' ), 10, 3 );
			add_action( 'woocommerce_after_checkout_validation', array( $this, 'refuse_classic_checkout' ), 10, 2 );
			add_action( 'woocommerce_check_cart_items', array( $this, 'block_unverified_checkout' ) );
			add_action( 'woocommerce_before_order_object_save', array( $this, 'guard_unverified_order' ), 5 );
			add_action( 'woocommerce_before_order_item_object_save', array( $this, 'guard_unverified_order_item' ), 5 );

			// 4. The verification record on orders placed by verified shoppers, and the check of
			//    paid orders without one.
			add_action( 'woocommerce_checkout_order_processed', array( $this, 'record_classic_order' ), 10, 3 );
			add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'record_verification' ) );
			add_action( 'woocommerce_after_order_object_save', array( $this, 'fire_pending_record_hook' ) );
			add_action( 'woocommerce_order_status_changed', array( $this, 'monitor_order_status' ), 10, 4 );

			// 5. The order edit screen shows the verification record under the order details.
			add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'show_order_verification' ) );
		}

		/**
		 * agewallet_meta_box_post_types: the Age Restriction meta box is not added for products.
		 *
		 * @param string[] $post_types
		 * @return string[]
		 */
		public function drop_product_meta_box( $post_types ) {
			return array_values( array_diff( (array) $post_types, array( 'product' ) ) );
		}

		/**
		 * True when the current cart must be age-verified before it can be ordered, and the
		 * visitor is not yet verified. Decides checkout validation and the cart and checkout
		 * pages' Verify my age message:
		 *   - off                 → never
		 *   - force-always        → the cart holds a product not set to Never require verification
		 *   - conditional-on-cart → the cart holds a regulated item
		 */
		public static function cart_requires_verification() {
			$mode = self::checkout_gating_mode();
			if ( AgeWalletOIDCClientPro::WC_GATE_MODE_OFF === $mode ) {
				return false;
			}
			if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
				return false;
			}
			if ( self::visitor_is_verified() ) {
				return false;
			}
			if ( AgeWalletOIDCClientPro::WC_GATE_MODE_FORCE_ALWAYS === $mode ) {
				foreach ( WC()->cart->get_cart() as $item ) {
					if ( ! empty( $item['data'] ) && self::product_requires_verification( $item['data'] ) ) {
						return true;
					}
				}
				return false;
			}
			// conditional-on-cart
			return self::cart_contains_regulated_items();
		}

		/**
		 * True if the visitor counts as age-verified (AgeWallet_Helpers::visitor_is_verified()): a
		 * valid (HMAC + unexpired) verification cookie, or what the agewallet_visitor_is_verified
		 * filter returns. A cookie that fails the check is expired in the browser by this response
		 * (AgeWallet_Helpers::clear_verified_cookie()), once per request.
		 */
		public static function visitor_is_verified() {
			if ( ! class_exists( 'AgeWallet_Helpers' ) ) {
				return false;
			}
			static $cookie_checked = false;
			if ( ! $cookie_checked ) {
				$cookie_checked = true;
				if ( ! AgeWallet_Helpers::instance()->has_valid_verified_cookie() ) {
					AgeWallet_Helpers::instance()->clear_verified_cookie();
				}
			}
			return AgeWallet_Helpers::instance()->visitor_is_verified();
		}

		/**
		 * True when this visitor can't have this product in an order yet: a shopper (not staff,
		 * wp-admin, cron or scheduled tasks) who hasn't verified, and the product needs
		 * verification.
		 *
		 * @param mixed $product
		 * @return bool
		 */
		public static function refuses( $product ) {
			return self::product_requires_verification( $product )
				&& self::is_shopper_request()
				&& ! self::visitor_is_verified();
		}

		// --- Listing cards ---

		/**
		 * woocommerce_before_shop_loop_item (classic product loops: shop, category, tag, search,
		 * related, upsells, cross-sells), lowest priority: for a product whose page is gated,
		 * prints the mask (card_mask_html()) and opens the hidden wrapper around the card.
		 */
		public function open_listing_card() {
			global $product;
			if ( ! self::card_is_masked( $product ) ) {
				return;
			}
			echo self::card_mask_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in card_mask_html().
			echo '<div class="agewallet-card-content" style="display:none;">';
		}

		/**
		 * woocommerce_after_shop_loop_item, highest priority: closes the wrapper opened by
		 * open_listing_card() and reveals the card (hiding the mask) when the visitor has a
		 * verification cookie.
		 */
		public function close_listing_card() {
			global $product;
			if ( ! self::card_is_masked( $product ) ) {
				return;
			}
			echo '</div>';
			wp_print_inline_script_tag( self::reveal_script() );
		}

		/**
		 * woocommerce_blocks_product_grid_item_html (the grid blocks: Handpicked Products, Best
		 * Selling Products, Products by Category and the others drawn from AbstractProductGrid):
		 * the card's content masked when the product's page is gated.
		 *
		 * @param string     $html    The <li class="wc-block-grid__product"> element.
		 * @param object     $data    The card's parts (image, title, price, badge, button, permalink).
		 * @param WC_Product $product
		 * @return string
		 */
		public function mask_product_grid_item( $html, $data, $product ) {
			if ( ! self::card_is_masked( $product ) ) {
				return $html;
			}
			return self::mask_list_item( $html );
		}

		/**
		 * render_block_woocommerce/product-template (the Product Collection block and the Products
		 * block built on it): each <li class="wc-block-product" data-wp-key="product-item-<id>">
		 * whose product's page is gated has its content masked.
		 *
		 * @param string $block_content The block's HTML: a <ul> of product <li> elements.
		 * @return string
		 */
		public function mask_product_template_block( $block_content ) {
			if ( false === strpos( $block_content, 'product-item-' ) ) {
				return $block_content;
			}
			$out    = '';
			$offset = 0;
			$length = strlen( $block_content );
			while ( $offset < $length ) {
				$start = self::find_list_item_start( $block_content, $offset );
				if ( false === $start ) {
					break;
				}
				$end = self::find_list_item_end( $block_content, $start );
				if ( false === $end ) {
					break;
				}
				$item = substr( $block_content, $start, $end - $start );
				$out .= substr( $block_content, $offset, $start - $offset );
				if ( preg_match( '/data-wp-key="product-item-(\d+)"/', $item, $m )
					&& self::card_is_masked( wc_get_product( (int) $m[1] ) ) ) {
					$item = self::mask_list_item( $item );
				}
				$out   .= $item;
				$offset = $end;
			}
			return $out . substr( $block_content, $offset );
		}

		/**
		 * The position of the next top-level <li in $html at or after $offset, or false.
		 *
		 * @param string $html
		 * @param int    $offset
		 * @return int|false
		 */
		private static function find_list_item_start( $html, $offset ) {
			return preg_match( '/<li[\s>]/i', $html, $m, PREG_OFFSET_CAPTURE, $offset ) ? $m[0][1] : false;
		}

		/**
		 * The position just after the </li> that closes the <li starting at $start, counting
		 * nested lists, or false.
		 *
		 * @param string $html
		 * @param int    $start
		 * @return int|false
		 */
		private static function find_list_item_end( $html, $start ) {
			$depth  = 0;
			$offset = $start;
			while ( preg_match( '/<(\/?)li[\s>\/]/i', $html, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
				$offset = $m[0][1] + strlen( $m[0][0] );
				$depth += '/' === $m[1][0] ? -1 : 1;
				if ( 0 === $depth ) {
					$close = strpos( $html, '>', $m[0][1] );
					return false === $close ? false : $close + 1;
				}
			}
			return false;
		}

		/**
		 * A product <li> with its content replaced by the mask and the hidden wrapper around the
		 * content, followed by the reveal script. An <li> already masked is returned as it is.
		 *
		 * @param string $item The <li ...>...</li> element.
		 * @return string
		 */
		private static function mask_list_item( $item ) {
			if ( false !== strpos( $item, 'agewallet-card-content' ) ) {
				return $item;
			}
			$open  = strpos( $item, '>' );
			$close = strrpos( $item, '</li' );
			if ( false === $open || false === $close || $close <= $open ) {
				return $item;
			}
			return substr( $item, 0, $open + 1 )
				. self::card_mask_html()
				. '<div class="agewallet-card-content" style="display:none;">' . substr( $item, $open + 1, $close - $open - 1 ) . '</div>'
				. wp_get_inline_script_tag( self::reveal_script() )
				. substr( $item, $close );
		}

		/**
		 * True when a listing card of this product is masked: the product's page is gated by the
		 * page protection rules (AgeWallet_Gating_Manager::should_restrict_content() for the product,
		 * which depends on the product and the rules, not on the visitor), and the visitor isn't
		 * staff who can edit content (never gated). Decided once per product per request.
		 *
		 * @param mixed $product
		 * @return bool
		 */
		private static function card_is_masked( $product ) {
			if ( ! $product instanceof WC_Product || ! class_exists( 'AgeWallet_Gating_Manager' ) ) {
				return false;
			}
			if ( is_user_logged_in() && current_user_can( 'edit_others_posts' ) ) {
				return false;
			}
			static $decided = array();
			$lookup_id = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();
			if ( ! isset( $decided[ $lookup_id ] ) ) {
				$decided[ $lookup_id ] = (bool) AgeWallet_Gating_Manager::instance()->should_restrict_content( $lookup_id, false );
			}
			return $decided[ $lookup_id ];
		}

		/**
		 * The mask shown in place of a listing card: the text and a Verify my age button that
		 * brings the visitor back to this page. Built once per request.
		 *
		 * @return string HTML.
		 */
		private static function card_mask_html() {
			static $html = null;
			if ( null === $html ) {
				$button = class_exists( 'AgeWallet_Helpers' ) ? self::verify_button_html( AgeWallet_Helpers::instance()->get_current_url() ) : '';
				$html   = '<div class="agewallet-card-mask" style="padding:12px 14px;border:1px solid currentColor;border-radius:6px;">'
					. '<p class="agewallet-card-mask__text" style="margin:0 0 8px;">' . esc_html__( 'Verify your age to view this product.', 'agewallet-oidc-client' ) . '</p>'
					. $button
					. '</div>';
			}
			return $html;
		}

		/**
		 * The script that, for a visitor with a verification cookie, shows every masked listing
		 * card on the page so far and hides its mask.
		 *
		 * @return string JavaScript.
		 */
		private static function reveal_script() {
			$cookie = class_exists( 'AgeWallet_Gating_Manager' ) ? AgeWallet_Gating_Manager::VERIFIED_COOKIE_NAME : 'agewallet_verified';
			return '(function(){var m=document.cookie.match(new RegExp("(?:^|; )"+' . wp_json_encode( $cookie ) . '+"=([^;]+)"));'
				. 'if(!m||!/^[a-zA-Z0-9+\/=]+\.[a-f0-9]{64}$/.test(decodeURIComponent(m[1]))){return;}'
				. 'var a=document.querySelectorAll(".agewallet-card-content"),i;for(i=0;i<a.length;i++){a[i].style.display="";}'
				. 'var n=document.querySelectorAll(".agewallet-card-mask");for(i=0;i<n.length;i++){n[i].style.display="none";}})();';
		}

		/**
		 * A listing's Verify my age button: posts to the launch address and brings the visitor back
		 * to $return_url. Built once per request for each return address. The launch's metadata
		 * describes the page being viewed (on a single page or product, that page), not the loop's
		 * current product.
		 *
		 * @param string $return_url Where the visitor comes back to.
		 * @return string HTML, or '' when the launch address can't be built.
		 */
		private static function verify_button_html( $return_url ) {
			static $built = array();
			if ( ! isset( $built[ $return_url ] ) ) {
				$launch               = class_exists( 'AgeWallet_Gating_Manager' ) ? self::page_verify_url( $return_url ) : '';
				$built[ $return_url ] = '' === $launch ? '' :
					'<form class="agewallet-verify-to-buy agewallet-verify-to-buy--loop" method="post" action="' . esc_url( $launch ) . '" style="margin:0;">'
					. '<button type="submit" class="button agewallet-verify-to-buy__btn">' . esc_html__( 'Verify my age', 'agewallet-oidc-client' ) . '</button>'
					. '</form>';
			}
			return $built[ $return_url ];
		}

		/**
		 * AgeWallet_Gating_Manager::get_verify_url() with the global post set to the page being
		 * viewed while the metadata is built, then put back. Inside a product loop the global post
		 * is the loop's product; on a single page or product the Auto metadata (post_id, post_slug,
		 * post_type) reads the queried page instead. Other pages are unchanged.
		 *
		 * @param string $return_url Where the visitor comes back to.
		 * @return string The launch URL, or '' if it can't be determined.
		 */
		private static function page_verify_url( $return_url ) {
			$page = is_singular() ? get_queried_object() : null;
			if ( ! $page instanceof WP_Post ) {
				return AgeWallet_Gating_Manager::get_verify_url( $return_url );
			}
			$loop_post       = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
			$GLOBALS['post'] = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- put back below.
			$url             = AgeWallet_Gating_Manager::get_verify_url( $return_url );
			$GLOBALS['post'] = $loop_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return $url;
		}

		// --- Cart, checkout and mini-cart ---

		/**
		 * True when the cart and checkout pages show the Verify my age message: the cart needs
		 * verification (cart_requires_verification()). Never on the checkout's order-pay and
		 * order-received pages.
		 */
		private static function order_needs_verification() {
			if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) ) {
				return false;
			}
			return self::cart_requires_verification();
		}

		/**
		 * The cart page's message. Verify my age brings the shopper back to the cart, with the
		 * cart details in the verification's metadata (inject_checkout_metadata()).
		 *
		 * @return string HTML.
		 */
		private static function cart_notice_html() {
			return self::notice_html(
				__( 'Your order contains items that require age verification.', 'agewallet-oidc-client' ),
				wc_get_cart_url(),
				'cart',
				'agewallet-verify-to-buy--order'
			);
		}

		/**
		 * The checkout page's message, worded for the store rule. Verify my age brings the shopper
		 * back to the checkout, with the cart details in the verification's metadata
		 * (inject_checkout_metadata()).
		 *
		 * @return string HTML.
		 */
		private static function checkout_notice_html() {
			$text = AgeWalletOIDCClientPro::WC_GATE_MODE_FORCE_ALWAYS === self::checkout_gating_mode()
				? __( 'Age verification is required before you can place an order.', 'agewallet-oidc-client' )
				: __( 'Your cart contains regulated items that require age verification.', 'agewallet-oidc-client' );
			return self::notice_html( $text, wc_get_checkout_url(), 'checkout', 'agewallet-verify-to-buy--order' );
		}

		/**
		 * woocommerce_proceed_to_checkout, priority 1 (the classic cart's checkout area, where
		 * payment plugins draw their express buttons before Proceed to checkout at 20): the cart's
		 * message, and a style that hides everything else in the area except Proceed to checkout.
		 */
		public function print_cart_notice() {
			if ( ! self::order_needs_verification() ) {
				return;
			}
			echo '<style>.wc-proceed-to-checkout > :not(a.checkout-button):not(.agewallet-verify-to-buy):not(:has(a.checkout-button)){display:none!important;}</style>';
			echo self::cart_notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in notice_html().
		}

		/**
		 * woocommerce_before_checkout_form, priority 1: the checkout's message at the top of the
		 * classic checkout, and a style that hides WooCommerce's own pay buttons area
		 * (wc_get_pay_buttons(), drawn above the customer details).
		 */
		public function print_checkout_notice() {
			if ( ! self::order_needs_verification() ) {
				return;
			}
			echo '<style>.woocommerce-pay-buttons{display:none!important;}</style>';
			echo self::checkout_notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in notice_html().
		}

		/**
		 * Opens a hidden wrapper on three classic checkout places, each closed by
		 * close_order_actions():
		 *   - woocommerce_checkout_before_customer_details, lowest priority to 9 (payment plugins'
		 *     express buttons above the customer details);
		 *   - woocommerce_review_order_before_submit 1 to woocommerce_review_order_after_submit 999
		 *     (Place order and anything payment plugins put beside it, redrawn on every order
		 *     review update);
		 *   - woocommerce_review_order_after_payment 1 to 999 (the buttons payment plugins put
		 *     under the payment area, drawn once with the page).
		 * The message is at the top of the page (print_checkout_notice()).
		 */
		public function open_checkout_actions() {
			if ( self::order_needs_verification() ) {
				self::open_order_actions();
			}
		}

		/**
		 * woocommerce_widget_shopping_cart_after_buttons, lowest priority (the classic mini-cart,
		 * under its View cart and Checkout buttons): a style that hides everything inside the
		 * buttons area except those two, and a hidden wrapper around what payment plugins draw
		 * after them, closed by close_order_actions() at the highest priority.
		 */
		public function open_mini_cart_actions() {
			if ( ! self::order_needs_verification() ) {
				return;
			}
			echo '<style>p.woocommerce-mini-cart__buttons > :not(a.wc-forward){display:none!important;}</style>';
			self::open_order_actions();
		}

		/** Opens the hidden wrapper around the checkout's or mini-cart's order buttons. */
		private static function open_order_actions() {
			self::$order_actions_open = true;
			echo '<div class="agewallet-order-actions" style="display:none;">';
		}

		/**
		 * Closes the wrapper opened by open_checkout_actions() or open_mini_cart_actions().
		 */
		public function close_order_actions() {
			if ( self::$order_actions_open ) {
				self::$order_actions_open = false;
				echo '</div>';
			}
		}

		/**
		 * render_block_woocommerce/cart: the cart's message above the Cart block, and its Express
		 * Checkout area hidden (order_actions_style()). The Cart block changes the cart without
		 * reloading the page, so the page reloads when an item is removed or added
		 * (cart_block_reload_script()), and the message is decided again.
		 *
		 * @param string $block_content The block's HTML.
		 * @return string
		 */
		public function prepend_cart_block_notice( $block_content ) {
			if ( ! self::order_needs_verification() ) {
				return $block_content;
			}
			return self::order_actions_style( array( 'cart-express-payment-block' ) )
				. self::cart_notice_html()
				. wp_get_inline_script_tag( self::cart_block_reload_script() )
				. $block_content;
		}

		/**
		 * The script that reloads the cart page when the Cart block's items change. It reads the
		 * cart from WooCommerce's cart data store (wc/store/cart) once that has loaded, and
		 * compares the item keys; a quantity change keeps the same keys.
		 *
		 * @return string JavaScript.
		 */
		private static function cart_block_reload_script() {
			return 'window.addEventListener("load",function(){var w=window.wp;if(!w||!w.data||!w.data.select("wc/store/cart")){return;}'
				. 'var keys=null;w.data.subscribe(function(){var s=w.data.select("wc/store/cart");'
				. 'if(!s.hasFinishedResolution||!s.hasFinishedResolution("getCartData")){return;}var d=s.getCartData();if(!d||!d.items){return;}'
				. 'var k=d.items.map(function(i){return i.key;}).sort().join(",");'
				. 'if(null===keys){keys=k;}else if(k!==keys){keys=k;window.location.reload();}});});';
		}

		/**
		 * render_block_woocommerce/checkout: the checkout's message above the Checkout block, and
		 * its Express Checkout area and order buttons (BLOCK_PLACE_ORDER_SELECTOR) hidden
		 * (order_actions_style()).
		 *
		 * @param string $block_content The block's HTML.
		 * @return string
		 */
		public function prepend_checkout_block_notice( $block_content ) {
			if ( ! self::order_needs_verification() ) {
				return $block_content;
			}
			return self::order_actions_style( array( 'checkout-express-payment-block' ), array( self::BLOCK_PLACE_ORDER_SELECTOR ) )
				. self::checkout_notice_html() . $block_content;
		}

		/**
		 * A style that hides the given Cart or Checkout inner blocks by their block class
		 * (wp-block-woocommerce-<name>), the given selectors, and the express payment area by the
		 * classes WooCommerce draws it with (BLOCK_EXPRESS_PAYMENT_SELECTORS). WooCommerce draws
		 * these blocks in the browser even when their markup is left out of the page. Every express
		 * payment method is drawn in the Express Checkout block, and the order buttons in the
		 * Checkout Actions block's actions row (BLOCK_PLACE_ORDER_SELECTOR).
		 *
		 * @param string[] $block_names Inner block names without the woocommerce/ prefix.
		 * @param string[] $selectors   CSS selectors, hidden as given.
		 * @return string HTML.
		 */
		private static function order_actions_style( $block_names, $selectors = array() ) {
			$selectors = array_merge( self::BLOCK_EXPRESS_PAYMENT_SELECTORS, $selectors );
			foreach ( $block_names as $name ) {
				$selectors[] = '.wp-block-woocommerce-' . sanitize_html_class( $name );
			}
			return '<style>' . implode( ',', $selectors ) . '{display:none!important;}</style>';
		}

		// --- Refusing unverified orders ---

		/**
		 * rest_request_before_callbacks (runs for every REST request, each request of a batch
		 * included): marks the request that places an order through the Store API, POST
		 * /wc/store/v1/checkout, for block_unverified_checkout().
		 *
		 * @param mixed           $response
		 * @param array           $handler
		 * @param WP_REST_Request $request
		 * @return mixed $response, unchanged.
		 */
		public function flag_store_api_checkout( $response, $handler, $request ) {
			self::$store_api_checkout = $request instanceof WP_REST_Request
				&& 'POST' === $request->get_method()
				&& (bool) preg_match( self::STORE_API_CHECKOUT_ROUTE, (string) $request->get_route() );
			return $response;
		}

		/**
		 * woocommerce_after_checkout_validation (the classic checkout's submit, whoever calls
		 * WC_Checkout::process_checkout()): the refusal as a checkout error, so no order is created.
		 *
		 * @param array    $data   The posted checkout fields.
		 * @param WP_Error $errors The checkout's errors.
		 */
		public function refuse_classic_checkout( $data, $errors ) {
			if ( $errors instanceof WP_Error && self::cart_requires_verification() ) {
				$errors->add( 'agewallet_age_verification_required', self::refusal_message() );
			}
		}

		/**
		 * woocommerce_check_cart_items, only in the Store API's checkout request (POST
		 * /wc/store/v1/checkout, flag_store_api_checkout()): the refusal as a cart error, which the
		 * Store API returns as a 409 before the order is placed. Cart reads, the Checkout block's
		 * updates and the classic pages run this action too and are left alone.
		 */
		public function block_unverified_checkout() {
			if ( self::$store_api_checkout && self::cart_requires_verification() ) {
				wc_add_notice( self::refusal_message(), 'error' );
			}
		}

		/** The refusal shoppers see at checkout. */
		public static function refusal_message() {
			return __( 'Age verification is required before you can place this order. Please verify your age at checkout.', 'agewallet-oidc-client' );
		}

		/**
		 * woocommerce_before_order_object_save: the backstop, for payment plugins that build orders
		 * themselves. A new order (no id yet, not the Checkout block's draft) in an unverified
		 * shopper's request is refused when it holds a line that needs verification, or, when it
		 * has no lines yet (wc_create_order() saves an empty order first), when the cart needs
		 * verification. The request is ended before anything is written (end_request()). A new
		 * order in a verified shopper's request gets the verification record (record_verification()).
		 * Existing orders are never touched.
		 *
		 * @param WC_Order $order The order being saved.
		 */
		public function guard_unverified_order( $order ) {
			if ( ! $order instanceof WC_Order || $order->get_id() || 'checkout-draft' === $order->get_status() ) {
				return;
			}
			if ( ! self::is_shopper_request() || ! self::checkout_gating_enabled() ) {
				return;
			}
			if ( self::visitor_is_verified() ) {
				if ( '' === (string) $order->get_meta( self::META_KEY_ORDER_VERIFIED_AT ) ) {
					$order->update_meta_data( self::META_KEY_ORDER_VERIFIED_AT, self::now() );
					self::$record_pending[ spl_object_id( $order ) ] = true;
				}
				return;
			}
			$needs = count( $order->get_items() ) > 0
				? self::order_holds_product_requiring_verification( $order )
				: self::cart_requires_verification();
			if ( $needs ) {
				self::end_request( 'Refused a new order for an unverified visitor.' );
			}
		}

		/**
		 * woocommerce_before_order_item_object_save: a new product line (not on the Checkout
		 * block's draft order) of a product that needs verification, saved in an unverified
		 * shopper's request, ends the request (end_request()). Lines already saved are left alone.
		 *
		 * WooCommerce catches and only logs exceptions thrown while an order saves its lines, so
		 * throwing here wouldn't stop anything.
		 *
		 * @param WC_Order_Item $item The line being saved.
		 */
		public function guard_unverified_order_item( $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || $item->get_id() ) {
				return;
			}
			if ( ! self::refuses( $item->get_product() ) ) {
				return;
			}
			$order = $item->get_order_id() ? wc_get_order( $item->get_order_id() ) : null;
			if ( $order instanceof WC_Order && 'checkout-draft' === $order->get_status() ) {
				return;
			}
			self::end_request( 'Refused a new order line for an unverified visitor (order ' . (int) $item->get_order_id() . ').' );
		}

		/**
		 * Ends the request with the refusal: a JSON 403 for AJAX, JSON and REST requests, a
		 * wp_die() page otherwise.
		 *
		 * @param string $log_line What was refused, for the debug log.
		 */
		private static function end_request( $log_line ) {
			agewallet_debug_log( '[AgeWallet WC] ' . $log_line );
			$message = self::refusal_message();
			if ( wp_doing_ajax() || wp_is_json_request() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				wp_send_json_error( array( 'message' => $message, 'code' => 'agewallet_age_verification_required' ), 403 );
			}
			wp_die( esc_html( $message ), esc_html__( 'Age verification required', 'agewallet-oidc-client' ), array( 'response' => 403, 'back_link' => true ) );
		}

		/**
		 * True for a request made by a shopper: not wp-admin screens, cron, WP-CLI, scheduled
		 * actions (subscription renewals run there) or anyone who can manage orders.
		 */
		private static function is_shopper_request() {
			if ( ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				return false;
			}
			if ( did_action( 'action_scheduler_begin_execute' ) ) {
				return false;
			}
			return ! current_user_can( 'edit_shop_orders' );
		}

		/**
		 * True when the order holds a product line whose product needs verification
		 * (product_requires_verification()).
		 *
		 * @param WC_Order $order
		 * @return bool
		 */
		private static function order_holds_product_requiring_verification( $order ) {
			foreach ( $order->get_items() as $item ) {
				if ( $item instanceof WC_Order_Item_Product && self::product_requires_verification( $item->get_product() ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * True when buying this product needs verification, for any visitor who hasn't verified:
		 * checkout gating is on, and either it's Force always and the product isn't set to Never
		 * require verification, or it's Conditional on cart and the product is regulated. Doesn't
		 * look at the visitor.
		 *
		 * @param mixed $product
		 * @return bool
		 */
		public static function product_requires_verification( $product ) {
			if ( ! $product instanceof WC_Product ) {
				return false;
			}
			$mode = self::checkout_gating_mode();
			if ( AgeWalletOIDCClientPro::WC_GATE_MODE_OFF === $mode ) {
				return false;
			}
			if ( AgeWalletOIDCClientPro::WC_GATE_MODE_FORCE_ALWAYS === $mode ) {
				return 'override_not_regulated' !== self::product_regulated_status( $product );
			}
			return self::product_is_regulated( $product );
		}

		// --- Verification record and monitor ---

		/**
		 * woocommerce_checkout_order_processed (the classic checkout, after the order is created):
		 * the verification record (record_verification()).
		 *
		 * @param int      $order_id
		 * @param array    $posted_data
		 * @param WC_Order $order
		 */
		public function record_classic_order( $order_id, $posted_data, $order ) {
			self::record_verification( $order instanceof WC_Order ? $order : wc_get_order( $order_id ) );
		}

		/**
		 * Writes the verification record on an order placed in a verified visitor's request: the
		 * time in META_KEY_ORDER_VERIFIED_AT, once, then the agewallet_wc_verified_order action.
		 * Also hooked to woocommerce_store_api_checkout_order_processed (the Store API's checkout,
		 * after the order is placed).
		 *
		 * @param WC_Order|mixed $order
		 */
		public static function record_verification( $order ) {
			if ( ! $order instanceof WC_Order || ! self::visitor_is_verified() ) {
				return;
			}
			if ( '' !== (string) $order->get_meta( self::META_KEY_ORDER_VERIFIED_AT ) ) {
				return;
			}
			$verified_at = self::now();
			$order->update_meta_data( self::META_KEY_ORDER_VERIFIED_AT, $verified_at );
			if ( $order->get_id() ) {
				$order->save_meta_data();
			}
			self::fire_verified_order_hook( $order, $verified_at );
		}

		/**
		 * woocommerce_after_order_object_save: fires the agewallet_wc_verified_order action for an
		 * order whose record was written before its first save (guard_unverified_order()).
		 *
		 * @param WC_Order|mixed $order
		 */
		public function fire_pending_record_hook( $order ) {
			if ( ! $order instanceof WC_Order || empty( self::$record_pending[ spl_object_id( $order ) ] ) ) {
				return;
			}
			unset( self::$record_pending[ spl_object_id( $order ) ] );
			self::fire_verified_order_hook( $order, (string) $order->get_meta( self::META_KEY_ORDER_VERIFIED_AT ) );
		}

		/**
		 * Fires agewallet_wc_verified_order.
		 *
		 * @param WC_Order $order       The order, saved.
		 * @param string   $verified_at The record's time (UTC, Y-m-d H:i:s).
		 */
		private static function fire_verified_order_hook( $order, $verified_at ) {
			/**
			 * An order was placed by an age-verified shopper and carries the verification record.
			 *
			 * @param WC_Order $order       The order.
			 * @param string   $verified_at When the record was written (UTC, Y-m-d H:i:s).
			 */
			do_action( 'agewallet_wc_verified_order', $order, $verified_at );
		}

		/** The current UTC time as Y-m-d H:i:s. */
		private static function now() {
			return gmdate( 'Y-m-d H:i:s' );
		}

		/**
		 * woocommerce_admin_order_data_after_order_details (the order edit screen's General
		 * column, under the date, status and customer): the verification record, as a line with
		 * the record's time in the site's timezone and date and time formats. Orders without a
		 * record get no line.
		 *
		 * @param WC_Order|mixed $order
		 */
		public function show_order_verification( $order ) {
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$verified_at = (string) $order->get_meta( self::META_KEY_ORDER_VERIFIED_AT );
			if ( '' === $verified_at ) {
				return;
			}
			try {
				$time  = new WC_DateTime( $verified_at, new DateTimeZone( 'UTC' ) );
				$time->setTimezone( new DateTimeZone( wc_timezone_string() ) );
				$shown = wc_format_datetime( $time, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
			} catch ( Exception $e ) {
				$shown = $verified_at . ' UTC';
			}
			echo '<p class="form-field form-field-wide agewallet-order-verification"><strong>'
				. esc_html__( 'Age verification:', 'agewallet-oidc-client' ) . '</strong> '
				/* translators: %s: the date and time the shopper's verification was recorded on the order */
				. esc_html( sprintf( __( 'Verified with AgeWallet on %s.', 'agewallet-oidc-client' ), $shown ) )
				. '</p>';
		}

		/**
		 * woocommerce_order_status_changed: a paid order (processing or completed) that holds a
		 * product needing verification but carries no verification record gets an order note, a
		 * line in the WooCommerce log (source "agewallet") and one email to the site's admin
		 * address, once (META_KEY_ORDER_NO_RECORD_NOTIFIED). Orders created in the admin, and
		 * status changes made by cron or scheduled actions, are left alone.
		 *
		 * @param int      $order_id
		 * @param string   $from  The previous status.
		 * @param string   $to    The new status.
		 * @param WC_Order $order
		 */
		public function monitor_order_status( $order_id, $from, $to, $order ) {
			if ( ! in_array( $to, array( 'processing', 'completed' ), true ) || ! self::checkout_gating_enabled() ) {
				return;
			}
			if ( ! $order instanceof WC_Order ) {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof WC_Order ) {
					return;
				}
			}
			if ( '' !== (string) $order->get_meta( self::META_KEY_ORDER_VERIFIED_AT )
				|| '1' === (string) $order->get_meta( self::META_KEY_ORDER_NO_RECORD_NOTIFIED ) ) {
				return;
			}
			if ( 'admin' === $order->get_created_via() || wp_doing_cron() || did_action( 'action_scheduler_begin_execute' ) ) {
				return;
			}
			if ( ! self::order_holds_product_requiring_verification( $order ) ) {
				return;
			}

			$method = $order->get_payment_method_title();
			if ( '' === $method ) {
				$method = $order->get_payment_method();
			}
			if ( '' === $method ) {
				$method = __( 'none', 'agewallet-oidc-client' );
			}
			/* translators: %s: payment method name */
			$note = sprintf( __( 'This order has no age verification record. Payment method: %s. Please check the customer\'s age before shipping.', 'agewallet-oidc-client' ), $method );

			$order->add_order_note( $note );
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning(
					sprintf( 'Order #%d has no age verification record (status %s, payment method: %s).', $order->get_id(), $to, $method ),
					array( 'source' => 'agewallet' )
				);
			}
			wp_mail(
				get_option( 'admin_email' ),
				/* translators: 1: site name, 2: order number */
				sprintf( __( '[%1$s] Order #%2$s has no age verification record', 'agewallet-oidc-client' ), wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ), $order->get_order_number() ),
				$note . "\n\n" . $order->get_edit_order_url() . "\n"
			);
			$order->update_meta_data( self::META_KEY_ORDER_NO_RECORD_NOTIFIED, '1' );
			$order->save_meta_data();
		}

		// --- Notices ---

		/**
		 * The verify notice's HTML: the text, and a Verify my age button that posts to the launch
		 * address with the same metadata as the gate's I Agree button, and brings the visitor back
		 * to $return_url.
		 *
		 * @param string $text       The notice text.
		 * @param string $return_url Where the visitor comes back to.
		 * @param string $origin     The verification's origin marker (AgeWallet_Gating_Manager::get_verify_url()):
		 *                           'cart' or 'checkout' adds the cart details to its metadata; '' decides from the page.
		 * @param string $modifier   An extra class on the notice.
		 * @return string HTML.
		 */
		private static function notice_html( $text, $return_url, $origin = '', $modifier = '' ) {
			if ( ! class_exists( 'AgeWallet_Gating_Manager' ) || ! class_exists( 'AgeWallet_Helpers' ) ) {
				return '';
			}
			$launch = AgeWallet_Gating_Manager::get_verify_url( $return_url, $origin );
			ob_start();
			?>
			<div class="<?php echo esc_attr( trim( 'agewallet-verify-to-buy ' . $modifier ) ); ?>" style="margin:12px 0;padding:12px 14px;border:1px solid currentColor;border-radius:6px;">
				<p class="agewallet-verify-to-buy__text" style="margin:0 0 8px;"><?php echo esc_html( $text ); ?></p>
				<form method="post" action="<?php echo esc_url( $launch ); ?>" style="margin:0;">
					<button type="submit" class="button agewallet-verify-to-buy__btn"><?php esc_html_e( 'Verify my age', 'agewallet-oidc-client' ); ?></button>
				</form>
				<p class="agewallet-verify-to-buy__note" style="margin:6px 0 0;font-size:0.85em;opacity:0.8;">
					<?php
					/* translators: %s: Verification partner name ("AgeWallet™"). */
					echo esc_html( sprintf( __( 'By proceeding you agree to allow %s to verify your age.', 'agewallet-oidc-client' ), 'AgeWallet™' ) );
					?>
				</p>
			</div>
			<?php
			return (string) ob_get_clean();
		}

		// --- Settings and product flags ---

		/**
		 * Returns the configured WC checkout-gate mode: one of
		 * AgeWalletOIDCClientPro::WC_GATE_MODE_OFF, _FORCE_ALWAYS, or _CONDITIONAL_CART.
		 *
		 * Normalizes legacy values: integer/string 1 → 'force-always', 0/'' → 'off'.
		 * Unknown values default to 'off'.
		 */
		public static function checkout_gating_mode() {
			$raw = get_option( AgeWalletOIDCClientPro::OPT_WC_GATE_CHECKOUT, AgeWalletOIDCClientPro::WC_GATE_MODE_OFF );

			// Legacy normalization.
			if ( 1 === $raw || '1' === $raw || true === $raw ) {
				return AgeWalletOIDCClientPro::WC_GATE_MODE_FORCE_ALWAYS;
			}
			if ( 0 === $raw || '0' === $raw || '' === $raw || false === $raw || null === $raw ) {
				return AgeWalletOIDCClientPro::WC_GATE_MODE_OFF;
			}

			$allowed = array(
				AgeWalletOIDCClientPro::WC_GATE_MODE_OFF,
				AgeWalletOIDCClientPro::WC_GATE_MODE_FORCE_ALWAYS,
				AgeWalletOIDCClientPro::WC_GATE_MODE_CONDITIONAL_CART,
			);
			return in_array( $raw, $allowed, true ) ? $raw : AgeWalletOIDCClientPro::WC_GATE_MODE_OFF;
		}

		/**
		 * True if WC checkout gating is enabled in any mode (force-always or conditional-on-cart).
		 * Kept for callers that just want to know "is this opt-in turned on at all."
		 */
		public static function checkout_gating_enabled() {
			return AgeWalletOIDCClientPro::WC_GATE_MODE_OFF !== self::checkout_gating_mode();
		}

		/**
		 * True if the current request is the WC checkout page.
		 *
		 * Compares the current queried page against whatever WC has configured
		 * as its checkout page in Settings → Advanced → Page setup. Reading
		 * from WC's own source-of-truth (the `woocommerce_checkout_page_id`
		 * option) avoids the edge cases where `is_checkout()` silently returns
		 * false (Blocks-based checkout, plugin shims hooking
		 * `woocommerce_is_checkout`, endpoint-detection drift, etc.).
		 */
		public static function is_checkout_request() {
			if ( ! function_exists( 'wc_get_page_id' ) || ! function_exists( 'is_page' ) ) {
				return false;
			}
			$checkout_id = wc_get_page_id( 'checkout' );
			return $checkout_id > 0 && is_page( $checkout_id );
		}

		/**
		 * True for WooCommerce pages, which always use Standard mode (the live page with the gate
		 * overlay), even when Strict mode is on. Strict mode serves a shared copy of the page built
		 * without the shopper's session, which leaves out their cart, WooCommerce's messages and
		 * anything else that belongs to them. Covers the shop, product, product category and tag
		 * pages, cart, checkout and account pages, and any page whose content has WooCommerce
		 * product blocks or shortcodes.
		 *
		 * @since 1.4.0
		 * @return bool
		 */
		public static function is_dynamic_wc_page() {
			if ( ! class_exists( 'WooCommerce' ) ) {
				return false;
			}
			if ( ( function_exists( 'is_woocommerce' ) && is_woocommerce() )
				|| ( function_exists( 'is_checkout' ) && is_checkout() )
				|| ( function_exists( 'is_cart' ) && is_cart() )
				|| ( function_exists( 'is_account_page' ) && is_account_page() ) ) {
				return true;
			}
			$post = is_singular() ? get_post() : null;
			if ( ! $post ) {
				return false;
			}
			if ( false !== strpos( $post->post_content, '<!-- wp:woocommerce/' ) ) {
				return true;
			}
			$shortcodes = array( 'products', 'product', 'product_page', 'product_category', 'product_categories', 'add_to_cart', 'recent_products', 'featured_products', 'sale_products', 'best_selling_products', 'top_rated_products' );
			foreach ( $shortcodes as $shortcode ) {
				if ( has_shortcode( $post->post_content, $shortcode ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Default set of cart-context fields to include in metadata.
		 */
		public static function default_metadata_fields() {
			return array( 'cart_hash', 'cart_total', 'currency' );
		}

		/**
		 * Allowed metadata field keys.
		 */
		public static function allowed_metadata_fields() {
			return array( 'cart_hash', 'cart_total', 'currency', 'customer_id', 'billing_country', 'line_item_count' );
		}

		/**
		 * True if the current cart contains at least one item flagged as regulated.
		 *
		 * Used by `cart_requires_verification()` in the conditional-on-cart branch, so
		 * checkout validation and the cart and checkout pages' Verify my age message apply
		 * only when a regulated item is present. Short-circuits if WC is missing or the
		 * cart is empty.
		 *
		 * Final result is passed through the `agewallet_cart_has_regulated_items` filter
		 * so site developers can override the decision (e.g. "regulated if cart total > X",
		 * "regulated if shipping to a specific country", etc.).
		 *
		 * Filter signature: apply_filters( 'agewallet_cart_has_regulated_items', bool $is_regulated, array $triggers, ?WC_Cart $cart )
		 */
		public static function cart_contains_regulated_items() {
			if ( ! function_exists( 'WC' ) ) {
				return apply_filters( 'agewallet_cart_has_regulated_items', false, array(), null );
			}
			$cart = WC()->cart ?? null;
			if ( ! $cart || $cart->is_empty() ) {
				return apply_filters( 'agewallet_cart_has_regulated_items', false, array(), $cart );
			}

			$triggers     = self::get_regulated_triggers();
			$is_regulated = ! empty( $triggers['products'] )
				|| ! empty( $triggers['product_cats'] )
				|| ! empty( $triggers['product_tags'] );

			return apply_filters( 'agewallet_cart_has_regulated_items', $is_regulated, $triggers, $cart );
		}

		/**
		 * Collect the IDs of products, categories, and tags in the current cart that
		 * caused the regulated-status check to fire. Used both by the gating decision
		 * and by the metadata audit trail (`cart_triggers` field) so merchants can prove
		 * which item(s) led to a given verification.
		 *
		 * Final array is passed through the `agewallet_regulated_cart_triggers` filter.
		 *
		 * @return array Shaped as: array( 'products' => int[], 'product_cats' => int[], 'product_tags' => int[] ).
		 */
		public static function get_regulated_triggers() {
			$triggers = array(
				'products'     => array(),
				'product_cats' => array(),
				'product_tags' => array(),
			);

			if ( ! function_exists( 'WC' ) ) {
				return apply_filters( 'agewallet_regulated_cart_triggers', $triggers, null );
			}
			$cart = WC()->cart ?? null;
			if ( ! $cart || $cart->is_empty() ) {
				return apply_filters( 'agewallet_regulated_cart_triggers', $triggers, $cart );
			}

			foreach ( $cart->get_cart() as $item ) {
				if ( empty( $item['data'] ) || ! ( $item['data'] instanceof WC_Product ) ) {
					continue;
				}
				$product   = $item['data'];
				$lookup_id = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();

				if ( ! self::product_is_regulated( $product ) ) {
					continue;
				}

				if ( ! in_array( $lookup_id, $triggers['products'], true ) ) {
					$triggers['products'][] = $lookup_id;
				}

				$cat_terms = wp_get_post_terms( $lookup_id, 'product_cat', array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $cat_terms ) ) {
					foreach ( $cat_terms as $term_id ) {
						if ( '1' === get_term_meta( $term_id, self::META_KEY_TERM_REGULATED, true )
							 && ! in_array( $term_id, $triggers['product_cats'], true ) ) {
							$triggers['product_cats'][] = (int) $term_id;
						}
					}
				}

				$tag_terms = wp_get_post_terms( $lookup_id, 'product_tag', array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $tag_terms ) ) {
					foreach ( $tag_terms as $term_id ) {
						if ( '1' === get_term_meta( $term_id, self::META_KEY_TERM_REGULATED, true )
							 && ! in_array( $term_id, $triggers['product_tags'], true ) ) {
							$triggers['product_tags'][] = (int) $term_id;
						}
					}
				}
			}

			return apply_filters( 'agewallet_regulated_cart_triggers', $triggers, $cart );
		}

		/**
		 * The product's own regulated status (META_KEY_PRODUCT_REGULATED_STATUS): 'regulated',
		 * 'override_not_regulated' or 'not_regulated' (the default when nothing is saved).
		 * Variations read their parent's.
		 *
		 * @param WC_Product|int $product A product, or a product id.
		 * @return string
		 */
		public static function product_regulated_status( $product ) {
			if ( $product instanceof WC_Product ) {
				$lookup_id = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();
			} else {
				$lookup_id = (int) $product;
			}
			$status = $lookup_id ? get_post_meta( $lookup_id, self::META_KEY_PRODUCT_REGULATED_STATUS, true ) : '';
			return in_array( $status, array( 'regulated', 'override_not_regulated' ), true ) ? $status : self::PRODUCT_REGULATED_STATUS_DEFAULT;
		}

		/**
		 * True if the given product is regulated under the current rules.
		 *
		 * Per-product flag wins over category/tag flags:
		 *   - 'regulated'              → returns true
		 *   - 'override_not_regulated' → returns false (forces unregulated even if cat/tag says yes)
		 *   - anything else            → falls through to category + tag check; any category OR tag
		 *                                 carrying the `agewallet_regulated` term meta = '1' returns true.
		 *
		 * Variations inherit their parent's flag (`get_parent_id() > 0 ? parent : self`).
		 *
		 * @param WC_Product $product
		 * @return bool
		 */
		public static function product_is_regulated( $product ) {
			if ( ! $product instanceof WC_Product ) {
				return false;
			}
			$lookup_id = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();

			$status = self::product_regulated_status( $product );
			if ( 'regulated' === $status ) {
				return true;
			}
			if ( 'override_not_regulated' === $status ) {
				return false;
			}

			// Fall through to category + tag inheritance.
			$cat_terms = wp_get_post_terms( $lookup_id, 'product_cat', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $cat_terms ) ) {
				foreach ( $cat_terms as $term_id ) {
					if ( '1' === get_term_meta( $term_id, self::META_KEY_TERM_REGULATED, true ) ) {
						return true;
					}
				}
			}

			$tag_terms = wp_get_post_terms( $lookup_id, 'product_tag', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $tag_terms ) ) {
				foreach ( $tag_terms as $term_id ) {
					if ( '1' === get_term_meta( $term_id, self::META_KEY_TERM_REGULATED, true ) ) {
						return true;
					}
				}
			}

			return false;
		}

		// --- Checkout metadata ---

		/**
		 * Build the per-checkout cart context as an associative array.
		 *
		 * @return array Associative array of resolved cart fields; empty if no WC cart.
		 */
		public function build_checkout_metadata_array() {
			if ( ! function_exists( 'WC' ) ) {
				return array();
			}
			$cart = WC()->cart ?? null;
			if ( ! $cart ) {
				return array();
			}

			$selected = get_option( AgeWalletOIDCClientPro::OPT_WC_METADATA_FIELDS, self::default_metadata_fields() );
			if ( ! is_array( $selected ) ) {
				$selected = self::default_metadata_fields();
			}

			$data = array();
			foreach ( $selected as $field ) {
				switch ( $field ) {
					case 'cart_hash':
						$data['cart_hash'] = $cart->get_cart_hash();
						break;
					case 'cart_total':
						$data['cart_total'] = $cart->get_total( 'edit' );
						break;
					case 'currency':
						$data['currency'] = get_woocommerce_currency();
						break;
					case 'customer_id':
						$data['customer_id'] = get_current_user_id();
						break;
					case 'billing_country':
						$customer = WC()->customer ?? null;
						if ( $customer ) {
							$data['billing_country'] = $customer->get_billing_country();
						}
						break;
					case 'line_item_count':
						$data['line_item_count'] = $cart->get_cart_contents_count();
						break;
				}
			}

			return $data;
		}

		/**
		 * Filter callback for `agewallet_metadata`. On the checkout page, merges
		 * the WC cart context into whatever the site-level builder produced — never overrides.
		 *
		 * @param string|null $existing The site-level builder output (null/static text/auto JSON).
		 * @return string|null
		 */
		public function inject_checkout_metadata( $existing ) {
			if ( ! self::checkout_gating_enabled() ) {
				return $existing;
			}
			// Only fire when the verification originated from the WC cart or checkout page.
			// AgeWallet_OIDC_Handler sets the origin marker from the signed `agewallet_origin`
			// query param on /agewallet/launch — that marker is added by
			// AgeWallet_Gating_Manager::get_verify_url(): 'checkout' on the checkout page,
			// 'cart' or 'checkout' for the cart and checkout pages' Verify my age message.
			// Cannot use is_checkout_request() directly here because we're inside
			// /agewallet/launch at this point, not on the checkout page.
			if ( ! class_exists( 'AgeWallet_OIDC_Handler' )
				|| ! in_array( AgeWallet_OIDC_Handler::get_request_origin(), array( 'checkout', 'cart' ), true ) ) {
				return $existing;
			}

			$cart_array = $this->build_checkout_metadata_array();

			// When verification was needed because of regulated cart contents, attach the trigger
			// IDs to the metadata as an audit trail so merchants can prove which item(s) led to
			// each verification. Only meaningful in conditional-on-cart mode — in force-always
			// mode every cart needs verification, so this field would just be noise.
			if ( AgeWalletOIDCClientPro::WC_GATE_MODE_CONDITIONAL_CART === self::checkout_gating_mode() ) {
				$triggers = self::get_regulated_triggers();
				if ( ! empty( $triggers['products'] )
					 || ! empty( $triggers['product_cats'] )
					 || ! empty( $triggers['product_tags'] ) ) {
					$cart_array['cart_triggers'] = $triggers;
				}
			}

			if ( empty( $cart_array ) ) {
				return $existing;
			}

			// Compose: site-level metadata wins on its own keys, cart fields are added (and win on collision).
			if ( null === $existing || '' === $existing ) {
				$final = $cart_array;
			} else {
				$decoded = json_decode( (string) $existing, true );
				if ( is_array( $decoded ) ) {
					// Site is auto-JSON: merge keys (cart fields override on collision since they're per-order).
					$final = array_merge( $decoded, $cart_array );
				} else {
					// Site is static text: wrap as a sibling key.
					$final = array_merge( array( 'site_metadata' => (string) $existing ), $cart_array );
				}
			}

			$json = wp_json_encode( $final );

			/**
			 * Filter the per-checkout metadata blob before it's attached to the verification.
			 *
			 * @param string   $json  JSON-encoded composed metadata (site + cart).
			 * @param WC_Cart  $cart  The current WC cart.
			 */
			$json = apply_filters( 'agewallet_wc_checkout_metadata', $json, WC()->cart );

			return is_string( $json ) && '' !== $json ? $json : $existing;
		}
	}
}
