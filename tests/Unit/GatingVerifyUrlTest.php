<?php
/**
 * The address every Verify button sends the visitor to (AgeWallet_Gating_Manager::get_verify_url()):
 * the return address, the signed metadata and the signed checkout origin; and the gate markup.
 */

namespace AgeWallet\Tests\Unit;

use AgeWallet\Tests\Support\WP;
use AgeWallet\Tests\TestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

class GatingVerifyUrlTest extends TestCase {

	const RETURN_URL = 'https://example.test/post/?a=1&b=two words';

	public function test_the_verify_url_is_the_launch_address_with_the_return_address() {
		$url = \AgeWallet_Gating_Manager::get_verify_url( self::RETURN_URL );

		$this->assertStringStartsWith( 'https://example.test/agewallet/launch/?', $url );
		$query = $this->query( $url );
		$this->assertSame( self::RETURN_URL, $query['redirect_to'] );
		$this->assertArrayNotHasKey( 'agewallet_md', $query );
		$this->assertArrayNotHasKey( 'agewallet_origin', $query );
	}

	public function test_metadata_is_signed_into_the_url_only_when_the_builder_gives_a_value() {
		WP::$options['agewallet_metadata_mode']                       = 'static';
		WP::$options[ \AgeWalletOIDCClientPro::OPT_METADATA_DEFAULT ] = 'site-tag';

		$query = $this->query( \AgeWallet_Gating_Manager::get_verify_url( self::RETURN_URL ) );
		$this->assertSame( 'site-tag', $this->helpers()->verify_signed_metadata( $query['agewallet_md'] ) );
		$this->assertSame( self::RETURN_URL, $query['redirect_to'] );

		WP::$options['agewallet_metadata_mode'] = 'off';
		$this->assertArrayNotHasKey( 'agewallet_md', $this->query( \AgeWallet_Gating_Manager::get_verify_url( self::RETURN_URL ) ) );
	}

	public function test_the_checkout_page_marks_the_origin_as_checkout() {
		WP::$wc_pages['checkout'] = 12;
		WP::$is['page']           = 12;

		$query = $this->query( \AgeWallet_Gating_Manager::get_verify_url( self::RETURN_URL ) );

		$this->assertSame( 'checkout', $this->helpers()->verify_signed_metadata( $query['agewallet_origin'] ) );
	}

	public function test_other_pages_carry_no_origin() {
		WP::$wc_pages['checkout'] = 12;
		WP::$is['page']           = 13;

		$this->assertArrayNotHasKey( 'agewallet_origin', $this->query( \AgeWallet_Gating_Manager::get_verify_url( self::RETURN_URL ) ) );
	}

	public function test_a_given_origin_is_signed_into_the_url() {
		$query = $this->query( \AgeWallet_Gating_Manager::get_verify_url( self::RETURN_URL, 'cart' ) );

		$this->assertSame( 'cart', $this->helpers()->verify_signed_metadata( $query['agewallet_origin'] ) );
	}

	public function test_the_origin_cannot_be_forged() {
		$query = $this->query( \AgeWallet_Gating_Manager::get_verify_url( self::RETURN_URL, 'cart' ) );
		list( , $signature ) = explode( '.', $query['agewallet_origin'], 2 );

		$this->assertNull( $this->helpers()->verify_signed_metadata( base64_encode( 'checkout' ) . '.' . $signature ) );
	}

	public function test_without_a_launch_address_there_is_no_verify_url() {
		Filters\expectApplied( 'agewallet_launch_url' )->once()->andReturn( '' );

		$this->assertSame( '', \AgeWallet_Gating_Manager::get_verify_url( self::RETURN_URL ) );
	}

	// --- The gate markup ---

	public function test_the_gate_markup_holds_the_title_the_copy_and_the_verify_address() {
		$_SERVER['REQUEST_URI'] = '/gated-post/';

		$html = $this->gating()->get_gate_html();

		$this->assertStringContainsString( '<h1 class="aw-gate__title">You Must Verify Your Age</h1>', $html );
		$this->assertStringContainsString( 'You must be 18+ to view this content', $html );
		$this->assertStringContainsString( 'class="aw-gate__btn aw-gate__btn--yes" type="button" data-redirect-url="', $html );
		$this->assertStringContainsString( 'agewallet/launch/?redirect_to=http%3A%2F%2Fexample.test%2Fgated-post%2F', $html );
		$this->assertStringContainsString( 'I Agree', $html );
		$this->assertStringContainsString( 'I Disagree', $html );
		$this->assertStringContainsString( 'href="https://www.agewallet.com"', $html );
		$this->assertStringNotContainsString( 'aw-gate__logo', $html );
	}

	public function test_the_gate_heading_can_be_hidden_and_the_copy_and_logo_set() {
		WP::$options[ \AgeWalletOIDCClientPro::OPT_HIDE_HEADING ]  = 1;
		WP::$options[ \AgeWalletOIDCClientPro::OPT_COPY_WYSIWYG ]  = '<p>Custom copy</p>';
		WP::$options[ \AgeWalletOIDCClientPro::OPT_LOGO_ID ]       = 7;
		WP::$options[ \AgeWalletOIDCClientPro::OPT_LOGO_WIDTH_PX ] = 120;
		WP::$attachments[7]                                        = 'https://example.test/logo.png';

		$html = $this->gating()->get_gate_html();

		$this->assertStringNotContainsString( 'aw-gate__title', $html );
		$this->assertStringContainsString( '<p>Custom copy</p>', $html );
		$this->assertStringContainsString( 'src="https://example.test/logo.png"', $html );
		$this->assertStringContainsString( 'width:120px', $html );
	}

	public function test_the_gate_template_args_filter_and_the_after_buttons_action_run() {
		Filters\expectApplied( 'agewallet_gate_template_args' )->once()->andReturnUsing(
			static function ( $args ) {
				$args['agree_text'] = 'Yes please';
				return $args;
			}
		);
		Actions\expectDone( 'agewallet_after_gate_buttons' )->once();

		$this->assertStringContainsString( 'Yes please', $this->gating()->get_gate_html() );
	}
}
