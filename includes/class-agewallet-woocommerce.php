<?php
/**
 * WooCommerce integration for the AgeWallet plugin.
 *
 * Adds the opt-in age-verification rule for buying products, the cart and checkout pages'
 * Verify my age message, and per-checkout metadata that round-trips through the AgeWallet
 * verification flow.
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
		 * True while keep_cart_item() asks a product whether it can be bought without our rule.
		 *
		 * @var bool
		 */
		private static $skip_purchase_rule = false;

		/**
		 * The age-restricted product an add-to-cart link asked for in this request, when our rule
		 * refused it (redirect_refused_add_to_cart_link()).
		 *
		 * @var WC_Product|null
		 */
		private static $refused_link_product = null;

		/**
		 * True between open_cart_actions() / open_checkout_actions() and close_order_actions().
		 *
		 * @var bool
		 */
		private static $order_actions_open = false;

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
		 * The Cart and Checkout blocks' express payment area as WooCommerce draws it in the browser:
		 * the cart's payment options, the express payment buttons, and the "Or" rule under them.
		 */
		const BLOCK_EXPRESS_PAYMENT_SELECTORS = array(
			'.wc-block-cart__payment-options',
			'.wc-block-components-express-payment',
			'.wc-block-components-express-payment-continue-rule',
		);

		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_filter( 'agewallet_metadata', array( $this, 'inject_checkout_metadata' ) );

			// 1. Age-restricted products can't be bought until the visitor has verified. This is
			//    WooCommerce's own purchasability rule, applied wherever a product goes into a cart
			//    or an order (add to cart, checkout, the cart API, payment plugins' AJAX calls).
			//    Those requests always carry the verification cookie, even on hosts whose page
			//    cache strips it from ordinary page loads. Items already in the cart stay there.
			add_filter( 'woocommerce_is_purchasable', array( $this, 'filter_purchasable' ), 20, 2 );
			add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'filter_purchasable' ), 20, 2 );
			add_filter( 'woocommerce_cart_item_is_purchasable', array( $this, 'keep_cart_item' ), 20, 4 );

			// 2. Product and listing pages are the same for every visitor, so they can be cached.
			//    The product page's buy area (WooCommerce's add-to-cart template, where payment
			//    plugins put their express buttons) and a listing's add-to-cart button (classic
			//    loops and the Product Button block) are wrapped, and the browser shows them or a
			//    Verify my age button, from the same cookie check the gate uses.
			add_action( 'woocommerce_before_template_part', array( $this, 'open_buy_area' ), 1, 1 );
			add_action( 'woocommerce_after_template_part', array( $this, 'close_buy_area' ), 999, 1 );
			add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'filter_loop_add_to_cart_link' ), 20, 2 );

			// 3. WooCommerce's add-to-cart refusal says why, for age-restricted products only. A
			//    refused add-to-cart link goes to the product's page.
			add_filter( 'woocommerce_cart_product_cannot_be_purchased_message', array( $this, 'filter_cannot_be_purchased_message' ), 10, 2 );
			add_action( 'wp_loaded', array( $this, 'redirect_refused_add_to_cart_link' ), 21 );

			// 4. Cart and checkout pages, when the cart needs verification: a Verify my age message,
			//    with Proceed to Checkout, Place order and the express checkout buttons hidden (the
			//    Cart and Checkout blocks' areas for them, and WooCommerce's classic places for
			//    them). These pages aren't page-cached, so the server decides.
			add_action( 'woocommerce_proceed_to_checkout', array( $this, 'open_cart_actions' ), 1 );
			add_action( 'woocommerce_proceed_to_checkout', array( $this, 'close_order_actions' ), 999 );
			add_action( 'woocommerce_before_checkout_form', array( $this, 'print_checkout_notice' ), 1 );
			add_action( 'woocommerce_review_order_before_submit', array( $this, 'open_checkout_actions' ), 1 );
			add_action( 'woocommerce_review_order_after_submit', array( $this, 'close_order_actions' ), 999 );
			add_action( 'woocommerce_review_order_after_payment', array( $this, 'open_checkout_actions' ), 1 );
			add_action( 'woocommerce_review_order_after_payment', array( $this, 'close_order_actions' ), 999 );
			add_filter( 'render_block_woocommerce/cart', array( $this, 'prepend_cart_block_notice' ) );
			add_filter( 'render_block_woocommerce/checkout', array( $this, 'prepend_checkout_block_notice' ) );

			// 5. Server-side backstops: checkout validation (classic and cart API), and any new
			//    order line, for payment plugins that build orders themselves.
			add_action( 'woocommerce_check_cart_items', array( $this, 'block_unverified_checkout' ) );
			add_action( 'woocommerce_before_order_item_object_save', array( $this, 'guard_unverified_order_item' ), 5 );
		}

		/**
		 * True when the current cart must be age-verified before it can be ordered, and the
		 * visitor is not yet verified. Decides checkout validation and the cart and checkout
		 * pages' Verify my age message:
		 *   - off                 → never
		 *   - force-always        → any non-empty cart
		 *   - conditional-on-cart → only when the cart holds a regulated item
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
				return true;
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
		 * True when this visitor can't have this product in a cart or an order yet: a shopper (not
		 * staff, wp-admin, cron or scheduled tasks) who hasn't verified, and the product needs
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

		/**
		 * True for an ordinary page load (GET, not AJAX or the REST API, not an add-to-cart link).
		 * Page caches may strip the verification cookie from these, so they must not decide who
		 * can buy; the browser does that on the page itself.
		 */
		private static function is_page_render() {
			if ( wp_doing_ajax() || wp_is_json_request() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return false;
			}
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
			if ( 'GET' !== $method && 'HEAD' !== $method ) {
				return false;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only checks the parameter exists.
			return ! isset( $_GET['add-to-cart'] );
		}

		/**
		 * woocommerce_is_purchasable / woocommerce_variation_is_purchasable.
		 *
		 * @param bool       $purchasable
		 * @param WC_Product $product
		 * @return bool
		 */
		public function filter_purchasable( $purchasable, $product ) {
			if ( self::$skip_purchase_rule || ! $purchasable || self::is_page_render() || ! self::refuses( $product ) ) {
				return $purchasable;
			}
			// The cart API's refusal ("... is not available for purchase") has no filter of its
			// own, so its wording is replaced through the translation filter, from now until the
			// end of this request only.
			if ( ! has_filter( 'gettext_woocommerce', array( $this, 'filter_store_api_message' ) ) ) {
				add_filter( 'gettext_woocommerce', array( $this, 'filter_store_api_message' ), 10, 2 );
			}
			return false;
		}

		/**
		 * woocommerce_cart_item_is_purchasable: while WooCommerce loads the cart from the
		 * shopper's session, keeps an item that only our rule refuses, so age-restricted items
		 * stay in the cart until the shopper verifies. Adding to a cart and placing an order are
		 * still refused (filter_purchasable(), block_unverified_checkout(),
		 * guard_unverified_order_item()).
		 *
		 * @param bool       $purchasable The product's is_purchasable().
		 * @param string     $key         Cart item key.
		 * @param array      $values      Cart item: product_id, variation_id, variation, quantity.
		 * @param WC_Product $product
		 * @return bool
		 */
		public function keep_cart_item( $purchasable, $key, $values, $product ) {
			if ( $purchasable || ! self::refuses( $product ) ) {
				return $purchasable;
			}
			self::$skip_purchase_rule = true;
			$purchasable              = $product->is_purchasable();
			self::$skip_purchase_rule = false;
			return $purchasable;
		}

		/**
		 * woocommerce_before_template_part: before the product page's add-to-cart template (any
		 * product type) of an age-restricted product, print the Verify my age notice and open the
		 * wrapper. Both start hidden or shown for an unverified visitor; the script in
		 * close_buy_area() swaps them for a verified one.
		 *
		 * @param string $template_name Template name, relative to WooCommerce's templates folder.
		 */
		public function open_buy_area( $template_name ) {
			if ( ! self::is_buy_area( $template_name ) ) {
				return;
			}
			global $product;
			self::render_notice( self::verify_to_buy_text(), get_permalink( $product->get_id() ) );
			echo '<div class="agewallet-buy-area" style="display:none;">';
		}

		/**
		 * woocommerce_after_template_part: close the wrapper, and reveal the buy area (hiding the
		 * notice) when the visitor has a verification cookie. It runs straight away, before
		 * payment plugins draw their buttons.
		 *
		 * @param string $template_name Template name, relative to WooCommerce's templates folder.
		 */
		public function close_buy_area( $template_name ) {
			if ( ! self::is_buy_area( $template_name ) ) {
				return;
			}
			echo '</div>';
			wp_print_inline_script_tag( self::reveal_script() );
		}

		/**
		 * The script that, for a visitor with a verification cookie, shows every buy area on the
		 * page so far and hides the Verify my age notices and buttons beside them. The cart and
		 * checkout pages' message (agewallet-verify-to-buy--order) is left alone.
		 *
		 * @return string JavaScript.
		 */
		private static function reveal_script() {
			return '(function(){var m=document.cookie.match(/(?:^|; )agewallet_verified=([^;]+)/);'
				. 'if(!m||!/^[a-zA-Z0-9+\/=]+\.[a-f0-9]{64}$/.test(decodeURIComponent(m[1]))){return;}'
				. 'var a=document.querySelectorAll(".agewallet-buy-area"),i;for(i=0;i<a.length;i++){a[i].style.display="";}'
				. 'var n=document.querySelectorAll(".agewallet-verify-to-buy:not(.agewallet-verify-to-buy--order)");for(i=0;i<n.length;i++){n[i].style.display="none";}})();';
		}

		/**
		 * True for the product page's add-to-cart template of a product that needs verification.
		 *
		 * @param string $template_name
		 */
		private static function is_buy_area( $template_name ) {
			$template_name = (string) $template_name;
			// The whole add-to-cart template (simple.php, variable.php, grouped.php, external.php, or
			// another product type's), not the pieces it includes.
			if ( 0 !== strpos( $template_name, 'single-product/add-to-cart/' )
				|| false !== strpos( $template_name, '-button' )
				|| 'single-product/add-to-cart/variation.php' === $template_name ) {
				return false;
			}
			global $product;
			// Staff are never asked to verify. Logged-in pages aren't page-cached and always carry
			// the login cookie, so this is safe to decide on the server.
			return $product instanceof WC_Product && self::product_requires_verification( $product ) && self::is_shopper_request();
		}

		/** The text shown in place of the buy area. */
		public static function verify_to_buy_text() {
			return __( 'Age verification is required before you can buy this item.', 'agewallet-oidc-client' );
		}

		/**
		 * woocommerce_loop_add_to_cart_link (classic product loops and the Product Button block):
		 * for an age-restricted product whose button adds it to the cart, a Verify my age button
		 * that brings the visitor back to this page, then the add-to-cart button, wrapped and
		 * hidden. The script after them swaps the two for a visitor with a verification cookie.
		 * Buttons that link to the product's page (Select options, Read more) are left as they are.
		 *
		 * @param string     $html    The add-to-cart button's HTML.
		 * @param WC_Product $product
		 * @return string
		 */
		public function filter_loop_add_to_cart_link( $html, $product ) {
			if ( ! $product instanceof WC_Product
				|| false === strpos( (string) $product->add_to_cart_url(), 'add-to-cart=' )
				|| ! self::product_requires_verification( $product )
				|| ! self::is_shopper_request()
				|| ! class_exists( 'AgeWallet_Helpers' ) ) {
				return $html;
			}
			$button = self::verify_button_html( AgeWallet_Helpers::instance()->get_current_url() );
			if ( '' === $button ) {
				return $html;
			}
			return $button
				. '<div class="agewallet-buy-area" style="display:none;">' . $html . '</div>'
				. wp_get_inline_script_tag( self::reveal_script() );
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

		/**
		 * Classic add to cart refused by our rule. For an add-to-cart link or form (not AJAX), the
		 * product is kept for redirect_refused_add_to_cart_link().
		 *
		 * @param string     $message
		 * @param WC_Product $product
		 * @return string
		 */
		public function filter_cannot_be_purchased_message( $message, $product ) {
			if ( ! self::refuses( $product ) ) {
				return $message;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only checks the parameter exists.
			if ( isset( $_REQUEST['add-to-cart'] ) && ! wp_doing_ajax() && ! wp_is_json_request() ) {
				self::$refused_link_product = $product;
			}
			return self::cannot_be_purchased_text();
		}

		/** The classic add-to-cart refusal for an age-restricted product. */
		private static function cannot_be_purchased_text() {
			return __( 'This item requires age verification. Please verify your age on the product page first.', 'agewallet-oidc-client' );
		}

		/**
		 * wp_loaded, priority 21 (after WooCommerce's add-to-cart handler at 20): when our rule
		 * refused an add-to-cart link or form in this request, sends the visitor to the product's
		 * page, whose notice says why and has the Verify my age button. The refusal notice is
		 * dropped, since that page says the same.
		 */
		public function redirect_refused_add_to_cart_link() {
			$product = self::$refused_link_product;
			if ( ! $product instanceof WC_Product ) {
				return;
			}
			self::$refused_link_product = null;

			$notices = wc_get_notices();
			if ( ! empty( $notices['error'] ) ) {
				$refusal          = self::cannot_be_purchased_text();
				$notices['error'] = array_values(
					array_filter(
						$notices['error'],
						function ( $notice ) use ( $refusal ) {
							return ! isset( $notice['notice'] ) || $refusal !== $notice['notice'];
						}
					)
				);
				wc_set_notices( $notices );
			}
			wp_safe_redirect( $product->get_permalink() );
			exit;
		}

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
		 * The checkout page's message, worded for Conditional on cart or Force always. Verify my age
		 * brings the shopper back to the checkout, with the cart details in the verification's
		 * metadata (inject_checkout_metadata()).
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
		 * woocommerce_proceed_to_checkout, priority 1 (the classic cart, before WooCommerce's
		 * express buttons at 10 and Proceed to Checkout at 20): the cart's message, then a hidden
		 * wrapper around the rest of the area, closed by close_order_actions() at 999.
		 */
		public function open_cart_actions() {
			if ( ! self::order_needs_verification() ) {
				return;
			}
			echo self::cart_notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in notice_html().
			self::open_order_actions();
		}

		/**
		 * Priority 1 on two classic checkout places, each closed by close_order_actions() at 999:
		 *   - woocommerce_review_order_before_submit (the payment area, redrawn on every order
		 *     review update): a hidden wrapper around Place order and anything payment plugins put
		 *     beside it, closed on woocommerce_review_order_after_submit;
		 *   - woocommerce_review_order_after_payment (under the payment area, drawn once with the
		 *     page): a hidden wrapper around the buttons payment plugins put there, such as PayPal
		 *     Payments' PayPal and Google Pay buttons.
		 * The message is at the top of the page (print_checkout_notice()).
		 */
		public function open_checkout_actions() {
			if ( self::order_needs_verification() ) {
				self::open_order_actions();
			}
		}

		/** Opens the hidden wrapper around the cart's or checkout's order buttons. */
		private static function open_order_actions() {
			self::$order_actions_open = true;
			echo '<div class="agewallet-order-actions" style="display:none;">';
		}

		/**
		 * woocommerce_proceed_to_checkout, woocommerce_review_order_after_submit and
		 * woocommerce_review_order_after_payment, priority 999: closes the wrapper opened by
		 * open_cart_actions() or open_checkout_actions().
		 */
		public function close_order_actions() {
			if ( self::$order_actions_open ) {
				self::$order_actions_open = false;
				echo '</div>';
			}
		}

		/**
		 * woocommerce_before_checkout_form, priority 1: the checkout's message at the top of the
		 * classic checkout.
		 */
		public function print_checkout_notice() {
			if ( self::order_needs_verification() ) {
				echo self::checkout_notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in notice_html().
			}
		}

		/**
		 * render_block_woocommerce/cart: the cart's message above the Cart block, and its Proceed
		 * to Checkout and Express Checkout areas hidden (order_actions_style()). The Cart block
		 * changes the cart without reloading the page, so the page reloads when an item is removed
		 * or added (cart_block_reload_script()), and the message is decided again.
		 *
		 * @param string $block_content The block's HTML.
		 * @return string
		 */
		public function prepend_cart_block_notice( $block_content ) {
			if ( ! self::order_needs_verification() ) {
				return $block_content;
			}
			return self::order_actions_style( array( 'proceed-to-checkout-block', 'cart-express-payment-block' ) )
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
		 * its Express Checkout and actions (Place order) areas hidden (order_actions_style()).
		 *
		 * @param string $block_content The block's HTML.
		 * @return string
		 */
		public function prepend_checkout_block_notice( $block_content ) {
			if ( ! self::order_needs_verification() ) {
				return $block_content;
			}
			return self::order_actions_style( array( 'checkout-actions-block', 'checkout-express-payment-block' ) )
				. self::checkout_notice_html() . $block_content;
		}

		/**
		 * A style that hides the given Cart or Checkout inner blocks by their block class
		 * (wp-block-woocommerce-<name>), and the express payment area by the classes WooCommerce
		 * draws it with (BLOCK_EXPRESS_PAYMENT_SELECTORS). WooCommerce draws these blocks in the
		 * browser even when their markup is left out of the page. Every express payment method is
		 * drawn in the Express Checkout block, and Place order in the Checkout Actions block.
		 *
		 * @param string[] $block_names Inner block names without the woocommerce/ prefix.
		 * @return string HTML.
		 */
		private static function order_actions_style( $block_names ) {
			$selectors = self::BLOCK_EXPRESS_PAYMENT_SELECTORS;
			foreach ( $block_names as $name ) {
				$selectors[] = '.wp-block-woocommerce-' . sanitize_html_class( $name );
			}
			return '<style>' . implode( ',', $selectors ) . '{display:none!important;}</style>';
		}

		/**
		 * The cart API's wording, only once our rule has refused a product in this request (the
		 * filter is added at that point):
		 *   - "... is not available for purchase": its add-to-cart refusal;
		 *   - "... cannot be purchased. Please remove it (them) from your cart.": its check of the
		 *     cart, shown by the Cart and Checkout blocks, which have the Verify my age message.
		 *
		 * @param string $translation
		 * @param string $text
		 * @return string
		 */
		public function filter_store_api_message( $translation, $text ) {
			if ( '&quot;%s&quot; is not available for purchase.' === $text ) {
				/* translators: %s: product name */
				return __( '&quot;%s&quot; requires age verification. Please verify your age on the product page first.', 'agewallet-oidc-client' );
			}
			if ( 'This item is not available for purchase.' === $text ) {
				return __( 'This item requires age verification. Please verify your age on the product page first.', 'agewallet-oidc-client' );
			}
			if ( '%s cannot be purchased. Please remove it from your cart.' === $text ) {
				/* translators: %s: product name */
				return __( '%s requires age verification. Please use the Verify my age button on this page.', 'agewallet-oidc-client' );
			}
			if ( '%s cannot be purchased. Please remove them from your cart.' === $text ) {
				/* translators: %s: product names */
				return __( '%s require age verification. Please use the Verify my age button on this page.', 'agewallet-oidc-client' );
			}
			return $translation;
		}

		/**
		 * woocommerce_check_cart_items: fires in both classic checkout validation and the
		 * Store API cart validation (block checkout / express-pay). Adds a blocking error
		 * when the cart needs verification. Skipped on the cart page so it doesn't nag
		 * before the shopper reaches checkout, and on plain page loads: the cart and checkout
		 * pages show the Verify my age message instead (an error here would replace the classic
		 * checkout form), and WooCommerce's blocks run this action while building any page.
		 */
		public function block_unverified_checkout() {
			if ( function_exists( 'is_cart' ) && is_cart() ) {
				return;
			}
			if ( self::is_page_render() ) {
				return;
			}
			if ( self::cart_requires_verification() ) {
				wc_add_notice( self::refusal_message(), 'error' );
			}
		}

		/** The refusal shoppers see at checkout. */
		public static function refusal_message() {
			return __( 'Age verification required before you can place this order.', 'agewallet-oidc-client' );
		}

		/**
		 * woocommerce_before_order_item_object_save: the backstop. Runs whenever a new product
		 * line is saved to an order, whoever builds the order, so it also covers payment plugins
		 * that create orders themselves (they save the lines before taking payment).
		 *
		 * WooCommerce catches and only logs exceptions thrown while an order saves its lines, so
		 * throwing here wouldn't stop anything. The request is ended instead. Staff, scheduled
		 * tasks (e.g. subscription renewals) and wp-admin are left alone, as are lines already saved.
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

			agewallet_debug_log( '[AgeWallet WC] Refused a new order line for an unverified visitor (order ' . (int) $item->get_order_id() . ').' );
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
		 * True when buying this product needs verification, for any visitor who hasn't verified:
		 * checkout gating is on, and it's Force always or the product is regulated. Doesn't look
		 * at the visitor.
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
			return AgeWalletOIDCClientPro::WC_GATE_MODE_FORCE_ALWAYS === $mode || self::product_is_regulated( $product );
		}

		/**
		 * Prints the verify notice: the text, and a Verify my age button that posts to the launch
		 * address with the same metadata as the gate's I Agree button, and brings the visitor back to $return_url.
		 *
		 * @param string $text       The notice text.
		 * @param string $return_url Where the visitor comes back to.
		 */
		public static function render_notice( $text, $return_url ) {
			echo self::notice_html( $text, $return_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in notice_html().
		}

		/**
		 * The verify notice's HTML (render_notice()).
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

			$status = get_post_meta( $lookup_id, self::META_KEY_PRODUCT_REGULATED_STATUS, true );
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
