<?php
/**
 * Strict mode against Standard mode (AgeWallet_Gating_Manager): which template a gated request
 * gets, the cache-build bypass, WooCommerce pages, and the assets and script data the gate gets.
 */

namespace AgeWallet\Tests\Unit;

use AgeWallet\Tests\Support\WP;
use AgeWallet\Tests\TestCase;
use Brain\Monkey\Filters;

class GatingTemplateTest extends TestCase {

	const TEMPLATE = '/theme/single.php';

	private function intercept() {
		return $this->gating()->intercept_template_loading( self::TEMPLATE );
	}

	private function skeleton() {
		return AGEWALLET_PLUGIN_DIR . 'templates/gatekeeper.php';
	}

	private function gateEverything( $mode = 'standard' ) {
		WP::$options[ \AgeWalletOIDCClientPro::OPT_BLOCK_MODE ] = 'all';
		WP::$options['agewallet_protection_mode']               = $mode;
	}

	// --- The template ---

	public function test_standard_mode_leaves_the_template_alone() {
		$this->gateEverything( 'standard' );

		$this->assertSame( self::TEMPLATE, $this->intercept() );
	}

	public function test_strict_mode_serves_the_skeleton_for_gated_content_whatever_the_cookie_says() {
		$this->gateEverything( 'strict' );

		$this->assertSame( $this->skeleton(), $this->intercept() );

		$_COOKIE['agewallet_verified'] = $this->helpers()->generate_signed_cookie( 'n' );
		$this->assertSame( $this->skeleton(), $this->intercept() );
	}

	public function test_strict_mode_leaves_ungated_content_alone() {
		WP::$options['agewallet_protection_mode'] = 'strict';

		$this->assertSame( self::TEMPLATE, $this->intercept() );
	}

	public function test_strict_mode_leaves_excluded_contexts_alone() {
		$this->gateEverything( 'strict' );
		WP::$is['admin'] = true;

		$this->assertSame( self::TEMPLATE, $this->intercept() );
	}

	public function test_the_skeleton_template_can_be_swapped_by_filter() {
		$this->gateEverything( 'strict' );
		Filters\expectApplied( 'agewallet_skeleton_template' )->once()->with( $this->skeleton() )->andReturn( '/theme/custom-gate.php' );

		$this->assertSame( '/theme/custom-gate.php', $this->intercept() );
	}

	public function test_a_matching_cache_bypass_secret_serves_the_original_template() {
		$this->gateEverything( 'strict' );
		WP::$options['agewallet_loopback_secret'] = 'loopback-secret-123';
		$_GET['agewallet_cache_bypass']           = 'loopback-secret-123';
		Filters\expectAdded( 'w3tc_lazyload_can_process' )->once();

		$this->assertSame( self::TEMPLATE, $this->intercept() );
	}

	public function test_a_wrong_or_missing_bypass_secret_serves_the_skeleton() {
		$this->gateEverything( 'strict' );
		WP::$options['agewallet_loopback_secret'] = 'loopback-secret-123';

		$_GET['agewallet_cache_bypass'] = 'wrong';
		$this->assertSame( $this->skeleton(), $this->intercept() );

		unset( $_GET['agewallet_cache_bypass'] );
		$this->assertSame( $this->skeleton(), $this->intercept() );
	}

	public function test_without_a_stored_secret_no_bypass_value_is_accepted() {
		$this->gateEverything( 'strict' );

		$_GET['agewallet_cache_bypass'] = '';
		$this->assertSame( $this->skeleton(), $this->intercept() );

		$_GET['agewallet_cache_bypass'] = 'anything';
		$this->assertSame( $this->skeleton(), $this->intercept() );
	}

	/**
	 * @dataProvider woocommercePages
	 */
	public function test_woocommerce_pages_keep_the_live_template_in_strict_mode( $tag ) {
		$this->gateEverything( 'strict' );
		WP::$is[ $tag ] = true;

		$this->assertSame( self::TEMPLATE, $this->intercept() );
	}

	public static function woocommercePages() {
		return array(
			'shop or product' => array( 'woocommerce' ),
			'cart'            => array( 'cart' ),
			'checkout'        => array( 'checkout' ),
			'account'         => array( 'account_page' ),
		);
	}

	public function test_a_page_with_woocommerce_blocks_or_shortcodes_keeps_the_live_template_in_strict_mode() {
		$this->gateEverything( 'strict' );
		WP::$is['singular'] = true;

		WP::post( 1, array( 'post_content' => '<!-- wp:woocommerce/cart --><div></div><!-- /wp:woocommerce/cart -->' ) );
		$this->assertSame( self::TEMPLATE, $this->intercept() );

		WP::post( 2, array( 'post_content' => '[products limit="3"]' ) );
		$this->assertSame( self::TEMPLATE, $this->intercept() );

		WP::post( 3, array( 'post_content' => 'Plain article' ) );
		$this->assertSame( $this->skeleton(), $this->intercept() );
	}

	// --- Assets and script data ---

	public function test_no_assets_are_enqueued_when_nothing_is_gated() {
		$this->gating()->maybe_enqueue_gate_assets();

		$this->assertSame( array(), WP::$enqueued_styles );
		$this->assertSame( array(), WP::$enqueued_scripts );
		$this->assertSame( array(), WP::$localized );
	}

	public function test_an_auto_gated_page_gets_the_assets_the_overlay_and_the_pending_body_class() {
		$this->gateEverything();
		Filters\expectAdded( '__agewallet_is_gating_this_request' )->once();

		$this->gating()->maybe_enqueue_gate_assets();

		$this->assertSame( array( 'agewallet-gate-style' ), WP::$enqueued_styles );
		$this->assertSame( array( 'agewallet-gate-script' ), WP::$enqueued_scripts );
		$this->assertStringStartsWith( ':root{', WP::$inline_styles['agewallet-gate-style'][0] );
		$data = WP::$localized['agewallet-gate-script']['agewallet_gate_data'];
		$this->assertSame( 'agewallet_verified', $data['cookieName'] );
		$this->assertSame( 'agewallet-gated-pending', $data['bodyClassPending'] );
		$this->assertTrue( $data['isOverlayActive'] );
		$this->assertStringContainsString( 'aw-gate__card', $data['gateHtml'] );
	}

	public function test_a_verified_visitor_gets_the_same_assets_as_an_unverified_one() {
		$this->gateEverything();
		$_COOKIE['agewallet_verified'] = $this->helpers()->generate_signed_cookie( 'n' );

		$this->gating()->maybe_enqueue_gate_assets();

		$this->assertSame( array( 'agewallet-gate-style' ), WP::$enqueued_styles );
		$this->assertTrue( WP::$localized['agewallet-gate-script']['agewallet_gate_data']['isOverlayActive'] );
	}

	public function test_a_shortcode_only_page_gets_the_assets_without_the_overlay_or_the_body_class() {
		WP::post( 1, array( 'post_content' => '[agewallet_protected]Secret[/agewallet_protected]' ) );
		WP::$is['singular'] = true;
		Filters\expectAdded( '__agewallet_is_gating_this_request' )->never();

		$this->gating()->maybe_enqueue_gate_assets();

		$this->assertSame( array( 'agewallet-gate-style' ), WP::$enqueued_styles );
		$data = WP::$localized['agewallet-gate-script']['agewallet_gate_data'];
		$this->assertFalse( $data['isOverlayActive'] );
		$this->assertSame( '', $data['gateHtml'] );
	}

	public function test_assets_are_enqueued_once_per_request() {
		$this->gateEverything();

		$this->gating()->maybe_enqueue_gate_assets();
		$this->gating()->maybe_enqueue_gate_assets();

		$this->assertCount( 1, WP::$enqueued_styles );
		$this->assertCount( 1, WP::$enqueued_scripts );
	}

	public function test_the_script_data_filter_applies() {
		$this->gateEverything();
		Filters\expectApplied( 'agewallet_gate_script_data' )->once()->andReturnUsing(
			static function ( $data ) {
				$data['extra'] = 'yes';
				return $data;
			}
		);

		$this->gating()->maybe_enqueue_gate_assets();

		$this->assertSame( 'yes', WP::$localized['agewallet-gate-script']['agewallet_gate_data']['extra'] );
	}

	public function test_the_pending_body_class_is_added_only_while_gating() {
		$this->assertSame( array( 'home' ), $this->gating()->add_body_classes( array( 'home' ) ) );

		Filters\expectApplied( '__agewallet_is_gating_this_request' )->once()->andReturn( true );
		$this->assertSame( array( 'home', 'agewallet-gated-pending' ), $this->gating()->add_body_classes( array( 'home' ) ) );
	}

	public function test_the_gate_assets_are_registered_under_their_handles() {
		$this->gating()->register_assets();

		$this->assertSame( array( 'agewallet-gate-style', 'agewallet-gate-skeleton' ), WP::$registered_styles );
		$this->assertSame( array( 'agewallet-gate-script' ), WP::$registered_scripts );
	}
}
