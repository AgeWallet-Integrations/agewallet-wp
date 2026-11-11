<?php
/**
 * The WooCommerce purchase rules (AgeWallet_WooCommerce): the checkout gating mode, which
 * products are regulated and which need verification, what the cart needs, the three order
 * refusals, the verification record and the check of paid orders without one, the per-checkout
 * metadata, and the masks on product listings.
 */

namespace AgeWallet\Tests\Unit;

use AgeWallet\Tests\Support\JsonResponseException;
use AgeWallet\Tests\Support\WP;
use AgeWallet\Tests\Support\WPDieException;
use AgeWallet\Tests\TestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Mockery;

class WooCommerceRulesTest extends TestCase {

	const STATUS   = \AgeWallet_WooCommerce::META_KEY_PRODUCT_REGULATED_STATUS;
	const TERM     = \AgeWallet_WooCommerce::META_KEY_TERM_REGULATED;
	const VERIFIED = \AgeWallet_WooCommerce::META_KEY_ORDER_VERIFIED_AT;
	const NOTIFIED = \AgeWallet_WooCommerce::META_KEY_ORDER_NO_RECORD_NOTIFIED;
	const OFF      = \AgeWalletOIDCClientPro::WC_GATE_MODE_OFF;
	const ALWAYS   = \AgeWalletOIDCClientPro::WC_GATE_MODE_FORCE_ALWAYS;
	const CART     = \AgeWalletOIDCClientPro::WC_GATE_MODE_CONDITIONAL_CART;

	private function mode( $mode ) {
		WP::$options[ \AgeWalletOIDCClientPro::OPT_WC_GATE_CHECKOUT ] = $mode;
	}

	/**
	 * A product registered for wc_get_product(), with its regulated status saved when given.
	 *
	 * @param int         $id
	 * @param string|null $status
	 * @param int         $parent_id
	 * @return \WC_Product
	 */
	private function product( $id, $status = null, $parent_id = 0 ) {
		$product = $parent_id ? new \WC_Product_Variation( $id, $parent_id ) : new \WC_Product( $id );
		if ( null !== $status ) {
			WP::$post_meta[ $id ][ self::STATUS ] = $status;
		}
		WP::$products[ $id ] = $product;
		return $product;
	}

	private function verify() {
		$_COOKIE['agewallet_verified'] = $this->helpers()->generate_signed_cookie( 'n' );
	}

	private function order( array $fields = array() ) {
		return new \WC_Order( $fields );
	}

	// --- The checkout gating mode ---

	/**
	 * @dataProvider gateModes
	 */
	public function test_the_checkout_gating_mode_is_normalised( $stored, $expected ) {
		if ( 'unset' !== $stored ) {
			$this->mode( $stored );
		}

		$this->assertSame( $expected, \AgeWallet_WooCommerce::checkout_gating_mode() );
		$this->assertSame( self::OFF !== $expected, \AgeWallet_WooCommerce::checkout_gating_enabled() );
	}

	public static function gateModes() {
		return array(
			'never saved'           => array( 'unset', self::OFF ),
			'legacy checked'        => array( 1, self::ALWAYS ),
			'legacy checked text'   => array( '1', self::ALWAYS ),
			'legacy true'           => array( true, self::ALWAYS ),
			'legacy unchecked'      => array( 0, self::OFF ),
			'legacy unchecked text' => array( '0', self::OFF ),
			'empty'                 => array( '', self::OFF ),
			'false'                 => array( false, self::OFF ),
			'null'                  => array( null, self::OFF ),
			'off'                   => array( self::OFF, self::OFF ),
			'force always'          => array( self::ALWAYS, self::ALWAYS ),
			'conditional on cart'   => array( self::CART, self::CART ),
			'unknown'               => array( 'sometimes', self::OFF ),
		);
	}

	// --- Regulated products ---

	public function test_a_product_without_a_saved_status_is_not_regulated() {
		$product = $this->product( 5 );

		$this->assertSame( 'not_regulated', \AgeWallet_WooCommerce::product_regulated_status( $product ) );
		$this->assertSame( 'not_regulated', \AgeWallet_WooCommerce::product_regulated_status( 5 ) );
		$this->assertSame( 'not_regulated', \AgeWallet_WooCommerce::product_regulated_status( 0 ) );
		$this->assertFalse( \AgeWallet_WooCommerce::product_is_regulated( $product ) );
	}

	public function test_the_saved_status_is_read_by_product_or_by_id_and_only_the_two_known_values_count() {
		$regulated = $this->product( 5, 'regulated' );
		$override  = $this->product( 6, 'override_not_regulated' );
		$other     = $this->product( 7, 'yes' );

		$this->assertSame( 'regulated', \AgeWallet_WooCommerce::product_regulated_status( $regulated ) );
		$this->assertSame( 'regulated', \AgeWallet_WooCommerce::product_regulated_status( 5 ) );
		$this->assertSame( 'override_not_regulated', \AgeWallet_WooCommerce::product_regulated_status( $override ) );
		$this->assertSame( 'not_regulated', \AgeWallet_WooCommerce::product_regulated_status( $other ) );

		$this->assertTrue( \AgeWallet_WooCommerce::product_is_regulated( $regulated ) );
		$this->assertFalse( \AgeWallet_WooCommerce::product_is_regulated( $override ) );
		$this->assertFalse( \AgeWallet_WooCommerce::product_is_regulated( $other ) );
	}

	public function test_a_variation_reads_its_parents_status() {
		$this->product( 5, 'regulated' );
		$variation = $this->product( 51, null, 5 );

		$this->assertSame( 'regulated', \AgeWallet_WooCommerce::product_regulated_status( $variation ) );
		$this->assertTrue( \AgeWallet_WooCommerce::product_is_regulated( $variation ) );
	}

	public function test_a_regulated_category_or_tag_regulates_the_products_in_it() {
		$in_category = $this->product( 5 );
		$in_tag      = $this->product( 6 );
		$in_neither  = $this->product( 7 );
		WP::terms( 5, 'product_cat', array( 3 ) );
		WP::terms( 6, 'product_tag', array( 9 ) );
		WP::terms( 7, 'product_cat', array( 4 ) );
		WP::$term_meta[3][ self::TERM ] = '1';
		WP::$term_meta[9][ self::TERM ] = '1';
		WP::$term_meta[4][ self::TERM ] = '0';

		$this->assertTrue( \AgeWallet_WooCommerce::product_is_regulated( $in_category ) );
		$this->assertTrue( \AgeWallet_WooCommerce::product_is_regulated( $in_tag ) );
		$this->assertFalse( \AgeWallet_WooCommerce::product_is_regulated( $in_neither ) );
	}

	public function test_the_products_own_setting_wins_over_its_categories() {
		$override = $this->product( 5, 'override_not_regulated' );
		WP::terms( 5, 'product_cat', array( 3 ) );
		WP::$term_meta[3][ self::TERM ] = '1';

		$this->assertFalse( \AgeWallet_WooCommerce::product_is_regulated( $override ) );
	}

	public function test_a_variation_inherits_its_parents_categories() {
		$this->product( 5 );
		$variation = $this->product( 51, null, 5 );
		WP::terms( 5, 'product_cat', array( 3 ) );
		WP::$term_meta[3][ self::TERM ] = '1';

		$this->assertTrue( \AgeWallet_WooCommerce::product_is_regulated( $variation ) );
	}

	public function test_something_that_is_not_a_product_is_never_regulated() {
		$this->assertFalse( \AgeWallet_WooCommerce::product_is_regulated( 5 ) );
		$this->assertFalse( \AgeWallet_WooCommerce::product_is_regulated( null ) );
		$this->assertFalse( \AgeWallet_WooCommerce::product_requires_verification( 'product' ) );
	}

	// --- Which products need verification ---

	public function test_no_product_needs_verification_while_checkout_gating_is_off() {
		$this->assertFalse( \AgeWallet_WooCommerce::product_requires_verification( $this->product( 5, 'regulated' ) ) );
	}

	public function test_force_always_needs_verification_for_every_product_except_those_set_to_never() {
		$this->mode( self::ALWAYS );

		$this->assertTrue( \AgeWallet_WooCommerce::product_requires_verification( $this->product( 5 ) ) );
		$this->assertTrue( \AgeWallet_WooCommerce::product_requires_verification( $this->product( 6, 'regulated' ) ) );
		$this->assertFalse( \AgeWallet_WooCommerce::product_requires_verification( $this->product( 7, 'override_not_regulated' ) ) );
	}

	public function test_conditional_on_cart_needs_verification_only_for_regulated_products() {
		$this->mode( self::CART );

		$this->assertFalse( \AgeWallet_WooCommerce::product_requires_verification( $this->product( 5 ) ) );
		$this->assertTrue( \AgeWallet_WooCommerce::product_requires_verification( $this->product( 6, 'regulated' ) ) );
		$this->assertFalse( \AgeWallet_WooCommerce::product_requires_verification( $this->product( 7, 'override_not_regulated' ) ) );
	}

	// --- The cart ---

	public function test_an_empty_or_missing_cart_has_no_regulated_items_and_no_triggers() {
		$empty = array( 'products' => array(), 'product_cats' => array(), 'product_tags' => array() );

		$this->assertSame( $empty, \AgeWallet_WooCommerce::get_regulated_triggers() );
		$this->assertFalse( \AgeWallet_WooCommerce::cart_contains_regulated_items() );

		WP::cart( array() );
		$this->assertSame( $empty, \AgeWallet_WooCommerce::get_regulated_triggers() );
		$this->assertFalse( \AgeWallet_WooCommerce::cart_contains_regulated_items() );
	}

	public function test_the_triggers_name_each_regulated_product_category_and_tag_once() {
		$regulated = $this->product( 5, 'regulated' );
		$variation = $this->product( 51, null, 5 );
		$plain     = $this->product( 6 );
		WP::terms( 5, 'product_cat', array( 3, 4 ) );
		WP::terms( 5, 'product_tag', array( 9 ) );
		WP::terms( 6, 'product_cat', array( 4 ) );
		WP::$term_meta[3][ self::TERM ] = '1';
		WP::$term_meta[9][ self::TERM ] = '1';
		WP::cart( array( $regulated, $variation, $plain, $regulated ) );

		$this->assertSame(
			array( 'products' => array( 5 ), 'product_cats' => array( 3 ), 'product_tags' => array( 9 ) ),
			\AgeWallet_WooCommerce::get_regulated_triggers()
		);
		$this->assertTrue( \AgeWallet_WooCommerce::cart_contains_regulated_items() );
	}

	public function test_a_product_regulated_only_by_its_category_is_a_trigger_with_the_category() {
		$product = $this->product( 5 );
		WP::terms( 5, 'product_cat', array( 3 ) );
		WP::$term_meta[3][ self::TERM ] = '1';
		WP::cart( array( $product ) );

		$this->assertSame(
			array( 'products' => array( 5 ), 'product_cats' => array( 3 ), 'product_tags' => array() ),
			\AgeWallet_WooCommerce::get_regulated_triggers()
		);
	}

	public function test_the_triggers_and_the_regulated_answer_pass_through_their_filters() {
		$cart = WP::cart( array( $this->product( 6 ) ) );
		Filters\expectApplied( 'agewallet_regulated_cart_triggers' )->once()->with( Mockery::type( 'array' ), $cart )->andReturnUsing(
			static function ( $triggers ) {
				$triggers['products'][] = 77;
				return $triggers;
			}
		);
		Filters\expectApplied( 'agewallet_cart_has_regulated_items' )->once()->with( true, Mockery::type( 'array' ), $cart )->andReturn( false );

		$this->assertFalse( \AgeWallet_WooCommerce::cart_contains_regulated_items() );
	}

	public function test_the_cart_never_needs_verification_while_gating_is_off_or_the_cart_is_empty() {
		WP::cart( array( $this->product( 5, 'regulated' ) ) );
		$this->assertFalse( \AgeWallet_WooCommerce::cart_requires_verification() );

		$this->mode( self::CART );
		WP::$wc = null;
		$this->assertFalse( \AgeWallet_WooCommerce::cart_requires_verification() );

		WP::cart( array() );
		$this->assertFalse( \AgeWallet_WooCommerce::cart_requires_verification() );
	}

	public function test_force_always_needs_the_cart_verified_unless_every_item_is_set_to_never() {
		$this->mode( self::ALWAYS );

		WP::cart( array( $this->product( 5, 'override_not_regulated' ) ) );
		$this->assertFalse( \AgeWallet_WooCommerce::cart_requires_verification() );

		WP::cart( array( $this->product( 5, 'override_not_regulated' ), $this->product( 6 ) ) );
		$this->assertTrue( \AgeWallet_WooCommerce::cart_requires_verification() );
	}

	public function test_conditional_on_cart_needs_the_cart_verified_only_with_a_regulated_item() {
		$this->mode( self::CART );

		WP::cart( array( $this->product( 6 ) ) );
		$this->assertFalse( \AgeWallet_WooCommerce::cart_requires_verification() );

		WP::cart( array( $this->product( 6 ), $this->product( 5, 'regulated' ) ) );
		$this->assertTrue( \AgeWallet_WooCommerce::cart_requires_verification() );
	}

	public function test_a_verified_visitors_cart_needs_nothing() {
		$this->mode( self::ALWAYS );
		$this->verify();
		WP::cart( array( $this->product( 5, 'regulated' ) ) );

		$this->assertFalse( \AgeWallet_WooCommerce::cart_requires_verification() );
	}

	public function test_the_verified_filter_counts_for_the_cart_too() {
		$this->mode( self::ALWAYS );
		WP::cart( array( $this->product( 5 ) ) );
		Filters\expectApplied( 'agewallet_visitor_is_verified' )->once()->with( false )->andReturn( true );

		$this->assertFalse( \AgeWallet_WooCommerce::cart_requires_verification() );
	}

	// --- Who is refused ---

	public function test_an_unverified_shopper_is_refused_a_product_that_needs_verification() {
		$this->mode( self::ALWAYS );

		$this->assertTrue( \AgeWallet_WooCommerce::refuses( $this->product( 5 ) ) );
		$this->assertFalse( \AgeWallet_WooCommerce::refuses( $this->product( 6, 'override_not_regulated' ) ) );
	}

	public function test_a_verified_shopper_is_never_refused() {
		$this->mode( self::ALWAYS );
		$this->verify();

		$this->assertFalse( \AgeWallet_WooCommerce::refuses( $this->product( 5 ) ) );
	}

	/**
	 * @dataProvider notShopperRequests
	 */
	public function test_staff_wp_admin_cron_and_scheduled_tasks_are_never_refused( $tag, $cap, $scheduled ) {
		$this->mode( self::ALWAYS );
		if ( $tag ) {
			WP::$is[ $tag ] = true;
		}
		if ( $cap ) {
			WP::$user_id = 2;
			WP::$caps    = array( $cap );
		}
		if ( $scheduled ) {
			do_action( 'action_scheduler_begin_execute', 1, 'WP Cron' );
		}

		$this->assertFalse( \AgeWallet_WooCommerce::refuses( $this->product( 5 ) ) );
	}

	public static function notShopperRequests() {
		return array(
			'wp-admin'           => array( 'admin', null, false ),
			'cron'               => array( 'doing_cron', null, false ),
			'can edit orders'    => array( null, 'edit_shop_orders', false ),
			'a scheduled action' => array( null, null, true ),
		);
	}

	public function test_an_ajax_request_from_wp_admin_is_a_shoppers_request() {
		$this->mode( self::ALWAYS );
		WP::$is['admin']      = true;
		WP::$is['doing_ajax'] = true;

		$this->assertTrue( \AgeWallet_WooCommerce::refuses( $this->product( 5 ) ) );
	}

	// --- The Store API checkout ---

	/**
	 * @dataProvider restRequests
	 */
	public function test_only_a_post_to_the_store_api_checkout_route_is_flagged( $method, $route, $flagged ) {
		$response = array( 'untouched' => true );

		$this->assertSame( $response, $this->woocommerce()->flag_store_api_checkout( $response, array(), new \WP_REST_Request( $method, $route ) ) );
		$this->assertSame( $flagged, self::getStatic( 'AgeWallet_WooCommerce', 'store_api_checkout' ) );
	}

	public static function restRequests() {
		return array(
			'POST checkout v1'   => array( 'POST', '/wc/store/v1/checkout', true ),
			'POST checkout'      => array( 'POST', '/wc/store/checkout', true ),
			'POST checkout/'     => array( 'POST', '/wc/store/v1/checkout/', true ),
			'GET checkout'       => array( 'GET', '/wc/store/v1/checkout', false ),
			'POST cart'          => array( 'POST', '/wc/store/v1/cart', false ),
			'POST another route' => array( 'POST', '/wp/v2/posts', false ),
		);
	}

	public function test_a_request_that_is_not_a_rest_request_clears_the_flag() {
		self::setStatic( 'AgeWallet_WooCommerce', 'store_api_checkout', true );

		$this->woocommerce()->flag_store_api_checkout( null, array(), null );

		$this->assertFalse( self::getStatic( 'AgeWallet_WooCommerce', 'store_api_checkout' ) );
	}

	public function test_the_store_api_checkout_gets_the_refusal_as_a_cart_error() {
		$this->mode( self::ALWAYS );
		WP::cart( array( $this->product( 5 ) ) );
		self::setStatic( 'AgeWallet_WooCommerce', 'store_api_checkout', true );

		$this->woocommerce()->block_unverified_checkout();

		$this->assertSame( array( array( \AgeWallet_WooCommerce::refusal_message(), 'error' ) ), WP::$notices );
	}

	public function test_cart_checks_outside_the_store_api_checkout_add_no_error() {
		$this->mode( self::ALWAYS );
		WP::cart( array( $this->product( 5 ) ) );

		$this->woocommerce()->block_unverified_checkout();

		$this->assertSame( array(), WP::$notices );
	}

	public function test_a_verified_shoppers_store_api_checkout_adds_no_error() {
		$this->mode( self::ALWAYS );
		$this->verify();
		WP::cart( array( $this->product( 5 ) ) );
		self::setStatic( 'AgeWallet_WooCommerce', 'store_api_checkout', true );

		$this->woocommerce()->block_unverified_checkout();

		$this->assertSame( array(), WP::$notices );
	}

	// --- The classic checkout ---

	public function test_the_classic_checkout_gets_the_refusal_as_a_checkout_error() {
		$this->mode( self::ALWAYS );
		WP::cart( array( $this->product( 5 ) ) );
		$errors = new \WP_Error();

		$this->woocommerce()->refuse_classic_checkout( array(), $errors );

		$this->assertSame( array( 'agewallet_age_verification_required' ), $errors->get_error_codes() );
		$this->assertSame( array( \AgeWallet_WooCommerce::refusal_message() ), $errors->get_error_messages() );
	}

	public function test_the_classic_checkout_is_left_alone_when_the_cart_needs_nothing() {
		$this->mode( self::CART );
		WP::cart( array( $this->product( 5 ) ) );
		$errors = new \WP_Error();

		$this->woocommerce()->refuse_classic_checkout( array(), $errors );
		$this->woocommerce()->refuse_classic_checkout( array(), null );

		$this->assertFalse( $errors->has_errors() );
	}

	// --- The backstop on orders ---

	public function test_existing_and_draft_orders_are_never_touched() {
		$this->mode( self::ALWAYS );
		$existing = $this->order( array( 'id' => 5, 'items' => array( new \WC_Order_Item_Product( $this->product( 5 ) ) ) ) );
		$draft    = $this->order( array( 'status' => 'checkout-draft', 'items' => array( new \WC_Order_Item_Product( $this->product( 5 ) ) ) ) );

		$this->woocommerce()->guard_unverified_order( $existing );
		$this->woocommerce()->guard_unverified_order( $draft );
		$this->woocommerce()->guard_unverified_order( 'not an order' );

		$this->assertSame( array(), $existing->meta );
		$this->assertSame( array(), $draft->meta );
	}

	public function test_a_new_order_is_left_alone_while_gating_is_off_or_outside_a_shoppers_request() {
		$order = $this->order( array( 'items' => array( new \WC_Order_Item_Product( $this->product( 5 ) ) ) ) );

		$this->woocommerce()->guard_unverified_order( $order );

		$this->mode( self::ALWAYS );
		WP::$caps = array( 'edit_shop_orders' );
		$this->woocommerce()->guard_unverified_order( $order );

		$this->assertSame( array(), $order->meta );
	}

	public function test_a_new_order_with_a_line_needing_verification_ends_the_request_with_a_403() {
		$this->mode( self::ALWAYS );
		$order = $this->order( array( 'items' => array( new \WC_Order_Item_Product( $this->product( 5 ) ) ) ) );

		try {
			$this->woocommerce()->guard_unverified_order( $order );
			$this->fail( 'The request was not ended' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->status() );
			$this->assertSame( \AgeWallet_WooCommerce::refusal_message(), $e->getMessage() );
			$this->assertSame( 'Age verification required', $e->title );
			$this->assertTrue( $e->args['back_link'] );
		}
		$this->assertSame( array(), $order->meta );
	}

	public function test_a_new_order_whose_lines_need_nothing_is_saved() {
		$this->mode( self::CART );
		WP::cart( array( $this->product( 5, 'regulated' ) ) );
		$order = $this->order( array( 'items' => array( new \WC_Order_Item_Product( $this->product( 6 ) ) ) ) );

		$this->woocommerce()->guard_unverified_order( $order );

		$this->assertSame( array(), $order->meta );
	}

	public function test_a_new_order_without_lines_is_judged_by_the_cart() {
		$this->mode( self::CART );
		$order = $this->order();

		WP::cart( array( $this->product( 6 ) ) );
		$this->woocommerce()->guard_unverified_order( $order );

		WP::cart( array( $this->product( 5, 'regulated' ) ) );
		$this->expectException( WPDieException::class );
		$this->woocommerce()->guard_unverified_order( $order );
	}

	/**
	 * @dataProvider jsonRequests
	 */
	public function test_ajax_and_json_requests_get_the_refusal_as_a_json_403( $tag ) {
		$this->mode( self::ALWAYS );
		WP::$is[ $tag ] = true;
		$order          = $this->order( array( 'items' => array( new \WC_Order_Item_Product( $this->product( 5 ) ) ) ) );

		try {
			$this->woocommerce()->guard_unverified_order( $order );
			$this->fail( 'The request was not ended' );
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 403, $e->status );
			$this->assertSame(
				array( 'message' => \AgeWallet_WooCommerce::refusal_message(), 'code' => 'agewallet_age_verification_required' ),
				$e->data
			);
		}
	}

	public static function jsonRequests() {
		return array(
			'admin-ajax' => array( 'doing_ajax' ),
			'JSON'       => array( 'json_request' ),
		);
	}

	public function test_a_verified_shoppers_new_order_gets_the_record_and_the_hook_fires_after_the_save() {
		$this->mode( self::ALWAYS );
		$this->verify();
		$order = $this->order( array( 'items' => array( new \WC_Order_Item_Product( $this->product( 5 ) ) ) ) );
		Actions\expectDone( 'agewallet_wc_verified_order' )->once()->with( $order, Mockery::type( 'string' ) );

		$this->woocommerce()->guard_unverified_order( $order );

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $order->meta[ self::VERIFIED ] );
		$this->assertSame( array( spl_object_id( $order ) => true ), self::getStatic( 'AgeWallet_WooCommerce', 'record_pending' ) );

		$this->woocommerce()->fire_pending_record_hook( $order );
		$this->woocommerce()->fire_pending_record_hook( $order );

		$this->assertSame( array(), self::getStatic( 'AgeWallet_WooCommerce', 'record_pending' ) );
	}

	public function test_a_record_already_on_a_new_order_is_kept() {
		$this->mode( self::ALWAYS );
		$this->verify();
		$order = $this->order( array( 'meta' => array( self::VERIFIED => '2026-01-01 00:00:00' ) ) );

		$this->woocommerce()->guard_unverified_order( $order );

		$this->assertSame( '2026-01-01 00:00:00', $order->meta[ self::VERIFIED ] );
		$this->assertSame( array(), self::getStatic( 'AgeWallet_WooCommerce', 'record_pending' ) );
	}

	public function test_the_pending_hook_ignores_orders_without_a_pending_record() {
		Actions\expectDone( 'agewallet_wc_verified_order' )->never();

		$this->woocommerce()->fire_pending_record_hook( $this->order( array( 'id' => 5 ) ) );
		$this->woocommerce()->fire_pending_record_hook( null );
	}

	// --- The backstop on order lines ---

	public function test_a_new_line_of_a_refused_product_ends_the_request() {
		$this->mode( self::ALWAYS );
		$line          = new \WC_Order_Item_Product( $this->product( 5 ), 0, 9 );
		WP::$orders[9] = $this->order( array( 'id' => 9, 'status' => 'pending' ) );

		$this->expectException( WPDieException::class );
		$this->woocommerce()->guard_unverified_order_item( $line );
	}

	public function test_a_new_line_without_an_order_yet_ends_the_request_too() {
		$this->mode( self::ALWAYS );

		$this->expectException( WPDieException::class );
		$this->woocommerce()->guard_unverified_order_item( new \WC_Order_Item_Product( $this->product( 5 ) ) );
	}

	public function test_saved_lines_draft_order_lines_and_lines_of_other_products_are_left_alone() {
		$this->mode( self::ALWAYS );
		WP::$orders[9] = $this->order( array( 'id' => 9, 'status' => 'checkout-draft' ) );

		$this->woocommerce()->guard_unverified_order_item( new \WC_Order_Item_Product( $this->product( 5 ), 12 ) );
		$this->woocommerce()->guard_unverified_order_item( new \WC_Order_Item_Product( $this->product( 5 ), 0, 9 ) );
		$this->woocommerce()->guard_unverified_order_item( new \WC_Order_Item_Product( $this->product( 6, 'override_not_regulated' ) ) );
		$this->woocommerce()->guard_unverified_order_item( 'not a line' );

		$this->addToAssertionCount( 1 );
	}

	// --- The verification record ---

	public function test_an_order_placed_by_a_verified_shopper_gets_the_record_saved_and_the_hook() {
		$this->verify();
		$order = $this->order( array( 'id' => 12 ) );
		Actions\expectDone( 'agewallet_wc_verified_order' )->once()->with( $order, Mockery::type( 'string' ) );

		\AgeWallet_WooCommerce::record_verification( $order );

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $order->meta[ self::VERIFIED ] );
		$this->assertSame( 1, $order->meta_saves );
	}

	public function test_the_record_is_written_once() {
		$this->verify();
		$order = $this->order( array( 'id' => 12, 'meta' => array( self::VERIFIED => '2026-01-01 00:00:00' ) ) );
		Actions\expectDone( 'agewallet_wc_verified_order' )->never();

		\AgeWallet_WooCommerce::record_verification( $order );

		$this->assertSame( '2026-01-01 00:00:00', $order->meta[ self::VERIFIED ] );
		$this->assertSame( 0, $order->meta_saves );
	}

	public function test_an_unverified_shoppers_order_gets_no_record() {
		$order = $this->order( array( 'id' => 12 ) );
		Actions\expectDone( 'agewallet_wc_verified_order' )->never();

		\AgeWallet_WooCommerce::record_verification( $order );
		\AgeWallet_WooCommerce::record_verification( null );

		$this->assertSame( array(), $order->meta );
	}

	public function test_the_classic_checkout_records_the_order_it_is_given_or_looks_it_up() {
		$this->verify();
		$given          = $this->order( array( 'id' => 12 ) );
		WP::$orders[13] = $this->order( array( 'id' => 13 ) );
		Actions\expectDone( 'agewallet_wc_verified_order' )->twice();

		$this->woocommerce()->record_classic_order( 12, array(), $given );
		$this->woocommerce()->record_classic_order( 13, array(), null );

		$this->assertArrayHasKey( self::VERIFIED, $given->meta );
		$this->assertArrayHasKey( self::VERIFIED, WP::$orders[13]->meta );
	}

	// --- Paid orders without a record ---

	private function paidOrder( array $fields = array() ) {
		return $this->order(
			array_merge(
				array(
					'id'                   => 12,
					'status'               => 'processing',
					'payment_method'       => 'cod',
					'payment_method_title' => 'Cash on delivery',
					'items'                => array( new \WC_Order_Item_Product( $this->product( 5 ) ) ),
				),
				$fields
			)
		);
	}

	public function test_a_paid_order_without_a_record_is_noted_logged_and_emailed_once() {
		$this->mode( self::ALWAYS );
		WP::$options['admin_email'] = 'owner@example.test';
		WP::$options['blogname']    = 'Ash &amp; Co';
		$order                      = $this->paidOrder();

		$this->woocommerce()->monitor_order_status( 12, 'pending', 'processing', $order );

		$note = 'This order has no age verification record. Payment method: Cash on delivery. Please check the customer\'s age before shipping.';
		$this->assertSame( array( $note ), $order->notes );
		$this->assertSame(
			array( array( 'warning', 'Order #12 has no age verification record (status processing, payment method: Cash on delivery).', array( 'source' => 'agewallet' ) ) ),
			WP::$wc_log
		);
		$this->assertCount( 1, WP::$mail );
		list( $to, $subject, $body ) = WP::$mail[0];
		$this->assertSame( 'owner@example.test', $to );
		$this->assertSame( '[Ash & Co] Order #12 has no age verification record', $subject );
		$this->assertSame( $note . "\n\nhttps://example.test/wp-admin/post.php?post=12&action=edit\n", $body );
		$this->assertSame( '1', $order->meta[ self::NOTIFIED ] );
		$this->assertSame( 1, $order->meta_saves );

		$this->woocommerce()->monitor_order_status( 12, 'processing', 'completed', $order );

		$this->assertCount( 1, WP::$mail );
		$this->assertCount( 1, $order->notes );
	}

	public function test_the_note_falls_back_to_the_payment_method_id_then_to_none() {
		$this->mode( self::ALWAYS );

		$order = $this->paidOrder( array( 'payment_method_title' => '' ) );
		$this->woocommerce()->monitor_order_status( 12, 'pending', 'completed', $order );
		$this->assertStringContainsString( 'Payment method: cod.', $order->notes[0] );

		$order = $this->paidOrder( array( 'payment_method_title' => '', 'payment_method' => '' ) );
		$this->woocommerce()->monitor_order_status( 12, 'pending', 'completed', $order );
		$this->assertStringContainsString( 'Payment method: none.', $order->notes[0] );
	}

	public function test_the_order_is_looked_up_when_only_its_id_is_given() {
		$this->mode( self::ALWAYS );
		WP::$orders[12] = $this->paidOrder();

		$this->woocommerce()->monitor_order_status( 12, 'pending', 'processing', null );
		$this->woocommerce()->monitor_order_status( 99, 'pending', 'processing', null );

		$this->assertCount( 1, WP::$orders[12]->notes );
		$this->assertCount( 1, WP::$mail );
	}

	/**
	 * @dataProvider ordersLeftAlone
	 */
	public function test_orders_that_need_no_check_are_left_alone( $mode, $to, array $fields ) {
		$this->mode( $mode );
		$order = $this->paidOrder( $fields );

		$this->woocommerce()->monitor_order_status( 12, 'pending', $to, $order );

		$this->assertSame( array(), $order->notes );
		$this->assertSame( array(), WP::$mail );
		$this->assertSame( array(), WP::$wc_log );
		$this->assertSame( 0, $order->meta_saves );
	}

	public static function ordersLeftAlone() {
		return array(
			'not paid yet'         => array( self::ALWAYS, 'on-hold', array() ),
			'gating off'           => array( self::OFF, 'processing', array() ),
			'with a record'        => array( self::ALWAYS, 'processing', array( 'meta' => array( self::VERIFIED => '2026-01-01 00:00:00' ) ) ),
			'already notified'     => array( self::ALWAYS, 'processing', array( 'meta' => array( self::NOTIFIED => '1' ) ) ),
			'created in the admin' => array( self::ALWAYS, 'processing', array( 'created_via' => 'admin' ) ),
			'no line needing it'   => array( self::CART, 'processing', array() ),
		);
	}

	// --- Per-checkout metadata ---

	private function checkoutOrigin( $origin ) {
		self::setStatic( 'AgeWallet_OIDC_Handler', 'request_origin', $origin );
	}

	public function test_the_cart_fields_follow_the_setting() {
		$cart        = WP::cart( array( $this->product( 5 ), $this->product( 6 ) ) );
		$cart->total = '42.50';
		WP::$user_id = 7;
		WP::$options[ \AgeWalletOIDCClientPro::OPT_WC_METADATA_FIELDS ] = array( 'line_item_count', 'billing_country', 'customer_id', 'currency', 'cart_total', 'cart_hash', 'bogus' );

		$this->assertSame(
			array(
				'line_item_count' => 2,
				'billing_country' => 'US',
				'customer_id'     => 7,
				'currency'        => 'USD',
				'cart_total'      => '42.50',
				'cart_hash'       => $cart->get_cart_hash(),
			),
			$this->woocommerce()->build_checkout_metadata_array()
		);
	}

	public function test_the_default_cart_fields_are_the_hash_the_total_and_the_currency() {
		WP::cart( array( $this->product( 5 ) ) );

		$this->assertSame( array( 'cart_hash', 'cart_total', 'currency' ), array_keys( $this->woocommerce()->build_checkout_metadata_array() ) );

		WP::$options[ \AgeWalletOIDCClientPro::OPT_WC_METADATA_FIELDS ] = 'cart_hash';
		$this->assertSame( array( 'cart_hash', 'cart_total', 'currency' ), array_keys( $this->woocommerce()->build_checkout_metadata_array() ) );
	}

	public function test_without_a_cart_there_are_no_cart_fields() {
		$this->assertSame( array(), $this->woocommerce()->build_checkout_metadata_array() );
	}

	public function test_the_cart_details_are_added_only_for_a_verification_from_the_cart_or_checkout_with_gating_on() {
		WP::cart( array( $this->product( 5 ) ) );

		$this->checkoutOrigin( 'checkout' );
		$this->assertSame( 'site-tag', $this->woocommerce()->inject_checkout_metadata( 'site-tag' ) );

		$this->mode( self::ALWAYS );
		$this->checkoutOrigin( null );
		$this->assertSame( 'site-tag', $this->woocommerce()->inject_checkout_metadata( 'site-tag' ) );
		$this->checkoutOrigin( 'product' );
		$this->assertSame( 'site-tag', $this->woocommerce()->inject_checkout_metadata( 'site-tag' ) );
	}

	public function test_without_a_cart_the_metadata_is_unchanged() {
		$this->mode( self::ALWAYS );
		$this->checkoutOrigin( 'checkout' );

		$this->assertNull( $this->woocommerce()->inject_checkout_metadata( null ) );
	}

	public function test_the_cart_details_alone_when_the_site_has_no_metadata() {
		$this->mode( self::ALWAYS );
		$this->checkoutOrigin( 'cart' );
		$cart        = WP::cart( array( $this->product( 5 ) ) );
		$cart->total = '10';

		$this->assertSame(
			array( 'cart_hash' => $cart->get_cart_hash(), 'cart_total' => '10', 'currency' => 'USD' ),
			json_decode( $this->woocommerce()->inject_checkout_metadata( null ), true )
		);
		$this->assertSame( 'cart_hash', key( json_decode( $this->woocommerce()->inject_checkout_metadata( '' ), true ) ) );
	}

	public function test_static_site_metadata_becomes_a_sibling_key() {
		$this->mode( self::ALWAYS );
		$this->checkoutOrigin( 'checkout' );
		$cart = WP::cart( array( $this->product( 5 ) ) );

		$this->assertSame(
			array( 'site_metadata' => 'site-tag', 'cart_hash' => $cart->get_cart_hash(), 'cart_total' => '0', 'currency' => 'USD' ),
			json_decode( $this->woocommerce()->inject_checkout_metadata( 'site-tag' ), true )
		);
	}

	public function test_auto_site_metadata_is_merged_and_the_cart_wins_on_a_shared_key() {
		$this->mode( self::ALWAYS );
		$this->checkoutOrigin( 'checkout' );
		$cart = WP::cart( array( $this->product( 5 ) ) );

		$this->assertSame(
			array( 'post_id' => 3, 'cart_total' => '0', 'cart_hash' => $cart->get_cart_hash(), 'currency' => 'USD' ),
			json_decode( $this->woocommerce()->inject_checkout_metadata( '{"post_id":3,"cart_total":"old"}' ), true )
		);
	}

	public function test_conditional_on_cart_adds_the_triggers_and_force_always_does_not() {
		$this->checkoutOrigin( 'checkout' );
		$regulated = $this->product( 5, 'regulated' );
		WP::cart( array( $regulated ) );

		$this->mode( self::CART );
		$this->assertSame(
			array( 'products' => array( 5 ), 'product_cats' => array(), 'product_tags' => array() ),
			json_decode( $this->woocommerce()->inject_checkout_metadata( null ), true )['cart_triggers']
		);

		$this->mode( self::ALWAYS );
		$this->assertArrayNotHasKey( 'cart_triggers', json_decode( $this->woocommerce()->inject_checkout_metadata( null ), true ) );

		$this->mode( self::CART );
		WP::cart( array( $this->product( 6 ) ) );
		$this->assertArrayNotHasKey( 'cart_triggers', json_decode( $this->woocommerce()->inject_checkout_metadata( null ), true ) );
	}

	public function test_the_checkout_metadata_filter_gets_the_json_and_the_cart_and_an_empty_answer_keeps_the_site_metadata() {
		$this->mode( self::ALWAYS );
		$this->checkoutOrigin( 'checkout' );
		$cart = WP::cart( array( $this->product( 5 ) ) );
		Filters\expectApplied( 'agewallet_wc_checkout_metadata' )->twice()->with( Mockery::type( 'string' ), $cart )->andReturn( '{"replaced":true}', '' );

		$this->assertSame( '{"replaced":true}', $this->woocommerce()->inject_checkout_metadata( 'site-tag' ) );
		$this->assertSame( 'site-tag', $this->woocommerce()->inject_checkout_metadata( 'site-tag' ) );
	}

	// --- WooCommerce pages and the product meta box ---

	/**
	 * @dataProvider woocommercePages
	 */
	public function test_shop_cart_checkout_and_account_pages_are_woocommerce_pages( $tag ) {
		$this->assertFalse( \AgeWallet_WooCommerce::is_dynamic_wc_page() );

		WP::$is[ $tag ] = true;
		$this->assertTrue( \AgeWallet_WooCommerce::is_dynamic_wc_page() );
	}

	public static function woocommercePages() {
		return array(
			'shop or product' => array( 'woocommerce' ),
			'cart'            => array( 'cart' ),
			'checkout'        => array( 'checkout' ),
			'account'         => array( 'account_page' ),
		);
	}

	public function test_a_page_holding_woocommerce_blocks_or_shortcodes_is_a_woocommerce_page() {
		WP::post( 1, array( 'post_content' => '<!-- wp:woocommerce/all-products /-->' ) );
		$this->assertFalse( \AgeWallet_WooCommerce::is_dynamic_wc_page() );

		WP::$is['singular'] = true;
		$this->assertTrue( \AgeWallet_WooCommerce::is_dynamic_wc_page() );

		WP::post( 2, array( 'post_content' => 'Buy now: [add_to_cart id="5"]' ) );
		$this->assertTrue( \AgeWallet_WooCommerce::is_dynamic_wc_page() );

		WP::post( 3, array( 'post_content' => 'A plain page [gallery]' ) );
		$this->assertFalse( \AgeWallet_WooCommerce::is_dynamic_wc_page() );
	}

	public function test_products_get_no_age_restriction_meta_box() {
		$this->assertSame( array( 'post', 'page' ), $this->woocommerce()->drop_product_meta_box( array( 'post', 'product', 'page' ) ) );
	}

	// --- Masked listing cards ---

	private function listItem( $id, $content ) {
		return '<li class="wc-block-product" data-wp-key="product-item-' . $id . '">' . $content . '</li>';
	}

	public function test_the_product_collection_block_masks_the_cards_of_gated_products() {
		$this->product( 901 );
		$this->product( 902 );
		WP::$post_meta[901][ \AgeWallet_Gating_Manager::META_KEY_FORCE_RESTRICT ] = '1';
		$gated = $this->listItem( 901, '<h3>Gated</h3><ul class="tags"><li>a</li><li>b</li></ul>' );
		$open  = $this->listItem( 902, '<h3>Open</h3>' );
		$block = '<ul class="wc-block-product-template">' . $gated . $open . '</ul>';

		$out = $this->woocommerce()->mask_product_template_block( $block );

		$this->assertStringContainsString( $open, $out );
		$this->assertStringContainsString( '<li class="wc-block-product" data-wp-key="product-item-901"><div class="agewallet-card-mask"', $out );
		$this->assertStringContainsString( 'Verify your age to view this product.', $out );
		$this->assertStringContainsString( '<div class="agewallet-card-content" style="display:none;"><h3>Gated</h3><ul class="tags"><li>a</li><li>b</li></ul></div>', $out );
		$this->assertStringContainsString( "<script>\n(function(){var m=document.cookie.match(", $out );
		$this->assertStringEndsWith( '</li>' . $open . '</ul>', $out );
		$this->assertSame( 1, substr_count( $out, 'class="agewallet-card-mask"' ) );
	}

	public function test_masking_a_block_twice_changes_nothing_more() {
		$this->product( 903 );
		WP::$post_meta[903][ \AgeWallet_Gating_Manager::META_KEY_FORCE_RESTRICT ] = '1';
		$block = '<ul>' . $this->listItem( 903, '<h3>Gated</h3>' ) . '</ul>';

		$once = $this->woocommerce()->mask_product_template_block( $block );

		$this->assertNotSame( $block, $once );
		$this->assertSame( $once, $this->woocommerce()->mask_product_template_block( $once ) );
	}

	public function test_blocks_without_product_items_and_staff_requests_are_left_alone() {
		$this->product( 904 );
		WP::$post_meta[904][ \AgeWallet_Gating_Manager::META_KEY_FORCE_RESTRICT ] = '1';
		$block = '<ul>' . $this->listItem( 904, '<h3>Gated</h3>' ) . '</ul>';

		$this->assertSame( '<ul><li>No products</li></ul>', $this->woocommerce()->mask_product_template_block( '<ul><li>No products</li></ul>' ) );

		WP::$user_id = 2;
		WP::$caps    = array( 'edit_others_posts' );
		$this->assertSame( $block, $this->woocommerce()->mask_product_template_block( $block ) );
	}
}
