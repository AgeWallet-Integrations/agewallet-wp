<?php
/**
 * WooCommerce integration for the AgeWallet plugin.
 *
 * Adds an opt-in "always gate the checkout page" rule plus per-checkout
 * metadata that round-trips through the AgeWallet verification flow.
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
		 * Names of the age-restricted items WooCommerce dropped from the cart in this request
		 * (remember_removed_cart_item()).
		 *
		 * @var string[]
		 */
		private static $removed_in_request = array();

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
		 * WooCommerce session key for the age-restricted items taken out of the shopper's cart.
		 * Value: array of array( 'product_id' => int, 'variation_id' => int, 'variation' => array ).
		 */
		const SESSION_KEY_REMOVED_ITEMS = 'agewallet_removed_items';

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
			//    or an order (add to cart, cart and checkout, the cart API, payment plugins' AJAX
			//    calls). Those requests always carry the verification cookie, even on hosts whose
			//    page cache strips it from ordinary page loads.
			add_filter( 'woocommerce_is_purchasable', array( $this, 'filter_purchasable' ), 20, 2 );
			add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'filter_purchasable' ), 20, 2 );

			// 2. The product page is the same for every visitor, so it can be cached. The buy area
			//    (WooCommerce's add-to-cart template, where payment plugins put their express
			//    buttons) is wrapped, and the browser shows it or a Verify my age notice, from the
			//    same cookie check the gate uses.
			add_action( 'woocommerce_before_template_part', array( $this, 'open_buy_area' ), 1, 1 );
			add_action( 'woocommerce_after_template_part', array( $this, 'close_buy_area' ), 999, 1 );

			// 3. WooCommerce's add-to-cart refusal says why, for age-restricted products only. When
			//    such an item drops out of the cart while an order is being placed, the order is
			//    refused with the reason; WooCommerce's cart-removal message is otherwise left out
			//    for them and section 4 lists the item.
			add_filter( 'woocommerce_cart_product_cannot_be_purchased_message', array( $this, 'filter_cannot_be_purchased_message' ), 10, 2 );
			add_filter( 'woocommerce_cart_item_removed_message', array( $this, 'filter_cart_item_removed_message' ), 10, 2 );
			add_action( 'woocommerce_store_api_cart_errors', array( $this, 'refuse_store_api_order' ) );

			// 4. Age-restricted items taken out of an unverified shopper's cart (by WooCommerce in
			//    any request, or here when the cart or checkout page loads) are listed at the top
			//    of the cart or checkout page, each linked to its product page.
			add_action( 'woocommerce_remove_cart_item_from_session', array( $this, 'remember_removed_cart_item' ), 10, 2 );
			add_action( 'template_redirect', array( $this, 'remove_refused_cart_items' ), 5 );
			add_filter( 'render_block_woocommerce/cart', array( $this, 'prepend_removed_items_notice' ) );
			add_filter( 'render_block_woocommerce/checkout', array( $this, 'prepend_removed_items_notice' ) );
			add_action( 'woocommerce_before_cart', array( $this, 'print_removed_items_notice' ), 1 );
			add_action( 'woocommerce_cart_is_empty', array( $this, 'print_removed_items_notice' ), 1 );
			add_action( 'woocommerce_before_checkout_form', array( $this, 'print_removed_items_notice' ), 1 );

			// 5. Server-side backstops: checkout validation (classic and cart API), and any new
			//    order line, for payment plugins that build orders themselves.
			add_action( 'woocommerce_check_cart_items', array( $this, 'block_unverified_checkout' ) );
			add_action( 'woocommerce_before_order_item_object_save', array( $this, 'guard_unverified_order_item' ), 5 );
		}

		/**
		 * True when the current cart must be age-verified before it can be ordered, and the
		 * visitor is not yet verified. Mirrors the page-gate decision in the gating manager:
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
		 * True if the visitor holds a valid (HMAC + unexpired) verification cookie.
		 * Uses the same signed-cookie check as the page gate. Checked once per request. A cookie
		 * that fails the check is expired in the browser by this response
		 * (AgeWallet_Helpers::clear_verified_cookie()).
		 */
		public static function visitor_is_verified() {
			static $verified = null;
			if ( null === $verified ) {
				$verified = class_exists( 'AgeWallet_Helpers' )
					&& null !== AgeWallet_Helpers::instance()->get_verified_cookie_payload();
				if ( ! $verified && class_exists( 'AgeWallet_Helpers' ) ) {
					AgeWallet_Helpers::instance()->clear_verified_cookie();
				}
			}
			return $verified;
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
			if ( ! $purchasable || self::is_page_render() || ! self::refuses( $product ) ) {
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
			wp_print_inline_script_tag(
				'(function(){var m=document.cookie.match(/(?:^|; )agewallet_verified=([^;]+)/);'
				. 'if(!m||!/^[a-zA-Z0-9+\/=]+\.[a-f0-9]{64}$/.test(decodeURIComponent(m[1]))){return;}'
				. 'var a=document.querySelectorAll(".agewallet-buy-area"),i;for(i=0;i<a.length;i++){a[i].style.display="";}'
				. 'var n=document.querySelectorAll(".agewallet-verify-to-buy");for(i=0;i<n.length;i++){n[i].style.display="none";}})();'
			);
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
		 * Classic add to cart refused by our rule.
		 *
		 * @param string     $message
		 * @param WC_Product $product
		 * @return string
		 */
		public function filter_cannot_be_purchased_message( $message, $product ) {
			if ( ! self::refuses( $product ) ) {
				return $message;
			}
			return __( 'This item requires age verification. Please verify your age on the product page first.', 'agewallet-oidc-client' );
		}

		/**
		 * woocommerce_cart_item_removed_message, for an age-restricted item this visitor can't buy:
		 *   - on the classic checkout's submit (is_classic_order_request()): the reason, as an error
		 *     notice that refuses the order and is shown on the checkout; the checkout's order
		 *     summary is redrawn (WooCommerce's refresh_totals session flag);
		 *   - otherwise: an empty message, so WooCommerce adds no notice (wc_add_notice() skips
		 *     empty messages). The cart API's checkout is refused by refuse_store_api_order().
		 * Either way remember_removed_cart_item() lists the item for the cart and checkout pages.
		 *
		 * @param string     $message
		 * @param WC_Product $product
		 * @return string
		 */
		public function filter_cart_item_removed_message( $message, $product ) {
			if ( ! self::refuses( $product ) ) {
				return $message;
			}
			if ( ! self::is_classic_order_request() ) {
				return '';
			}
			if ( WC()->session ) {
				WC()->session->set( 'refresh_totals', true );
			}
			return self::removed_from_cart_message( $product->get_name() );
		}

		/**
		 * woocommerce_store_api_cart_errors: on the cart API's checkout (Place order in the
		 * Checkout block), one error per age-restricted item dropped from the cart in this request,
		 * so the order is refused (409) with the reason.
		 *
		 * @param WP_Error $errors Cart errors; any error stops the order.
		 */
		public function refuse_store_api_order( $errors ) {
			if ( empty( self::$removed_in_request ) || ! self::is_store_api_order_request() ) {
				return;
			}
			foreach ( array_unique( self::$removed_in_request ) as $name ) {
				$errors->add( 'agewallet_age_verification_required', self::removed_from_cart_message( $name ) );
			}
		}

		/**
		 * The reason an age-restricted item left the cart, for a refused order.
		 *
		 * @param string $name Product name.
		 * @return string
		 */
		private static function removed_from_cart_message( $name ) {
			/* translators: %s: product name */
			return sprintf( __( '%s was removed from your cart because it requires age verification. Please verify your age on the product page, then add it again.', 'agewallet-oidc-client' ), $name );
		}

		/**
		 * True for the classic checkout's submit: ?wc-ajax=checkout, or the checkout form posted
		 * without JavaScript.
		 */
		private static function is_classic_order_request() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads which request this is.
			$ajax = isset( $_GET['wc-ajax'] ) ? sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only checks the field exists.
			return 'checkout' === $ajax || isset( $_POST['woocommerce_checkout_place_order'] );
		}

		/**
		 * True for the cart API's checkout: POST /wc/store/v1/checkout (also /wc/store/checkout).
		 */
		private static function is_store_api_order_request() {
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
			$route  = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
			return 'POST' === $method && (bool) preg_match( '#^/wc/store(?:/v\d+)?/checkout/?$#', $route );
		}

		/**
		 * woocommerce_remove_cart_item_from_session: WooCommerce dropped an item while loading the
		 * cart. An age-restricted item this visitor can't buy goes on the removed-items list, and
		 * its name on self::$removed_in_request.
		 *
		 * @param string $key    Cart item key.
		 * @param array  $values Cart item: product_id, variation_id, variation (the chosen options), quantity.
		 */
		public function remember_removed_cart_item( $key, $values ) {
			$product = wc_get_product( $values['variation_id'] ? $values['variation_id'] : $values['product_id'] );
			if ( self::refuses( $product ) ) {
				self::add_removed_item( $values );
				self::$removed_in_request[] = $product->get_name();
			}
		}

		/**
		 * template_redirect, priority 5 (before WooCommerce's empty-checkout redirect to the cart at
		 * 10): on the cart and checkout pages, takes out the items this shopper can't buy yet and
		 * puts each on the removed-items list. Updates the session cart and a logged-in customer's
		 * saved cart.
		 */
		public function remove_refused_cart_items() {
			if ( ! self::is_cart_or_checkout_page() || ! WC()->cart || WC()->cart->is_empty() ) {
				return;
			}
			$removed = false;
			foreach ( WC()->cart->get_cart() as $key => $item ) {
				if ( empty( $item['data'] ) || ! self::refuses( $item['data'] ) ) {
					continue;
				}
				self::add_removed_item( $item );
				WC()->cart->remove_cart_item( $key );
				$removed = true;
			}
			if ( $removed ) {
				WC()->cart->calculate_totals();
			}
		}

		/**
		 * Adds a cart item to the removed-items list in the shopper's WooCommerce session
		 * (SESSION_KEY_REMOVED_ITEMS), once per product and chosen options.
		 *
		 * @param array $values Cart item: product_id, variation_id, variation.
		 */
		private static function add_removed_item( $values ) {
			if ( ! function_exists( 'WC' ) || ! WC()->session ) {
				return;
			}
			$entry = array(
				'product_id'   => absint( $values['product_id'] ?? 0 ),
				'variation_id' => absint( $values['variation_id'] ?? 0 ),
				'variation'    => isset( $values['variation'] ) && is_array( $values['variation'] ) ? $values['variation'] : array(),
			);
			$items = WC()->session->get( self::SESSION_KEY_REMOVED_ITEMS );
			$items = is_array( $items ) ? $items : array();
			if ( in_array( $entry, $items, true ) ) {
				return;
			}
			$items[] = $entry;
			WC()->session->set( self::SESSION_KEY_REMOVED_ITEMS, $items );
		}

		/**
		 * render_block_woocommerce/cart, render_block_woocommerce/checkout: the removed-items notice
		 * above the Cart or Checkout block.
		 *
		 * @param string $block_content The block's HTML.
		 * @return string
		 */
		public function prepend_removed_items_notice( $block_content ) {
			return self::removed_items_notice_html() . $block_content;
		}

		/**
		 * woocommerce_before_cart, woocommerce_cart_is_empty, woocommerce_before_checkout_form: the
		 * removed-items notice at the top of the classic cart (full or empty) and checkout.
		 */
		public function print_removed_items_notice() {
			echo self::removed_items_notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in removed_items_notice_html().
		}

		/**
		 * The removed-items notice, on the cart or checkout page, once per request, then the list is
		 * emptied. Each product name links to its product page (a variation's link carries the chosen
		 * options). Items back in the cart are left out. Returns '' when there is nothing to show.
		 *
		 * @return string HTML.
		 */
		private static function removed_items_notice_html() {
			static $shown = false;
			if ( $shown || ! self::is_cart_or_checkout_page() || ! WC()->session ) {
				return '';
			}
			$items = WC()->session->get( self::SESSION_KEY_REMOVED_ITEMS );
			if ( empty( $items ) || ! is_array( $items ) ) {
				return '';
			}
			$shown = true;
			WC()->session->set( self::SESSION_KEY_REMOVED_ITEMS, null );

			$in_cart = array();
			foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $cart_item ) {
				$in_cart[] = absint( $cart_item['product_id'] ) . ':' . absint( $cart_item['variation_id'] );
			}
			$links = array();
			foreach ( $items as $item ) {
				if ( in_array( $item['product_id'] . ':' . $item['variation_id'], $in_cart, true ) ) {
					continue;
				}
				$product = wc_get_product( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
				if ( ! $product instanceof WC_Product ) {
					continue;
				}
				$url     = $product instanceof WC_Product_Variation ? $product->get_permalink( $item ) : $product->get_permalink();
				$links[] = '<strong><a href="' . esc_url( $url ) . '">' . esc_html( $product->get_name() ) . '</a></strong>';
			}

			$count = count( $links );
			if ( 0 === $count ) {
				return '';
			}
			if ( 1 === $count ) {
				/* translators: %s: product name, linked to the product's page */
				$body = '<p style="margin:0;">' . sprintf( esc_html__( '%s was removed from your cart because it requires age verification. Verify your age on its page, then add it again.', 'agewallet-oidc-client' ), $links[0] ) . '</p>';
			} else {
				$body = '<p style="margin:0;">' . esc_html( _n( 'This item was removed from your cart because it requires age verification:', 'These items were removed from your cart because they require age verification:', $count, 'agewallet-oidc-client' ) ) . '</p>'
					. '<ul style="margin:8px 0 8px 1.25em;padding:0;"><li>' . implode( '</li><li>', $links ) . '</li></ul>'
					. '<p style="margin:0;">' . esc_html( _n( 'Verify your age on its page, then add it again.', 'Verify your age on any of these pages, then add them again. One verification covers them all.', $count, 'agewallet-oidc-client' ) ) . '</p>';
			}
			return '<div class="agewallet-removed-items" style="margin:12px 0;padding:12px 14px;border:1px solid currentColor;border-radius:6px;">' . $body . '</div>';
		}

		/**
		 * True for the cart page or the checkout page, not the checkout's order-pay and
		 * order-received pages.
		 */
		private static function is_cart_or_checkout_page() {
			if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_wc_endpoint_url' ) ) {
				return false;
			}
			if ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) {
				return false;
			}
			return is_cart() || self::is_checkout_request();
		}

		/**
		 * The cart API's "not available for purchase" wording, only once our rule has refused a
		 * product in this request (the filter is added at that point).
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
			return $translation;
		}

		/**
		 * woocommerce_check_cart_items: fires in both classic checkout validation and the
		 * Store API cart validation (block checkout / express-pay). Adds a blocking error
		 * when the cart needs verification. Skipped on the cart page so it doesn't nag
		 * before the shopper reaches checkout, and on plain page loads other than the checkout page
		 * (WooCommerce's blocks run this action while building any page).
		 */
		public function block_unverified_checkout() {
			if ( function_exists( 'is_cart' ) && is_cart() ) {
				return;
			}
			if ( self::is_page_render() && ! self::is_checkout_request() ) {
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
			if ( ! class_exists( 'AgeWallet_Gating_Manager' ) || ! class_exists( 'AgeWallet_Helpers' ) ) {
				return;
			}
			$launch = AgeWallet_Gating_Manager::get_verify_url( $return_url );
			?>
			<div class="agewallet-verify-to-buy" style="margin:12px 0;padding:12px 14px;border:1px solid currentColor;border-radius:6px;">
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
		 * Used by `should_restrict_content()` in the conditional-on-cart branch so the
		 * checkout gate fires only when a regulated item is present. Short-circuits if
		 * WC is missing or the cart is empty.
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
			// Only fire when the verification originated from the WC checkout page.
			// AgeWallet_OIDC_Handler sets the origin marker from the signed `agewallet_origin`
			// query param on /agewallet/launch — that marker is added by the gating
			// manager when rendering the gate on a page where is_checkout_request()
			// is true. Cannot use is_checkout_request() directly here because we're
			// inside /agewallet/launch at this point, not on the checkout page.
			if ( ! class_exists( 'AgeWallet_OIDC_Handler' )
				|| 'checkout' !== AgeWallet_OIDC_Handler::get_request_origin() ) {
				return $existing;
			}

			$cart_array = $this->build_checkout_metadata_array();

			// When the gate fired because of regulated cart contents, attach the trigger IDs
			// to the metadata as an audit trail so merchants can prove which item(s) led to
			// each verification. Only meaningful in conditional-on-cart mode — in force-always
			// mode every checkout gates, so this field would just be noise.
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
