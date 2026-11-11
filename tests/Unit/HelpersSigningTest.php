<?php
/**
 * AgeWallet_Helpers beyond the cookie: the signed metadata that rides in the launch URL, the PKCE
 * values, the random values, the endpoint and current URLs, and the appearance and analytics
 * values read from the settings.
 */

namespace AgeWallet\Tests\Unit;

use AgeWallet\Tests\Support\WP;
use AgeWallet\Tests\TestCase;
use Brain\Monkey\Filters;

class HelpersSigningTest extends TestCase {

	// --- Signed metadata ---

	public function test_signed_metadata_round_trips() {
		$signed = $this->helpers()->sign_metadata( '{"post_id":42}' );

		$this->assertSame( '{"post_id":42}', $this->helpers()->verify_signed_metadata( $signed ) );
	}

	public function test_signed_metadata_is_the_base64_value_a_dot_and_its_hmac_sha256() {
		$signed = $this->helpers()->sign_metadata( 'checkout' );

		list( $encoded, $signature ) = explode( '.', $signed, 2 );
		$this->assertSame( base64_encode( 'checkout' ), $encoded );
		$this->assertSame( hash_hmac( 'sha256', $encoded, self::SECRET ), $signature );
	}

	public function test_signed_metadata_with_a_changed_signature_is_rejected() {
		$signed = $this->helpers()->sign_metadata( 'checkout' );
		$last   = substr( $signed, -1 );
		$forged = substr( $signed, 0, -1 ) . ( 'a' === $last ? 'b' : 'a' );

		$this->assertNull( $this->helpers()->verify_signed_metadata( $forged ) );
	}

	public function test_signed_metadata_with_a_changed_value_is_rejected() {
		list( , $signature ) = explode( '.', $this->helpers()->sign_metadata( 'cart' ), 2 );

		$this->assertNull( $this->helpers()->verify_signed_metadata( base64_encode( 'checkout' ) . '.' . $signature ) );
	}

	public function test_metadata_signed_with_another_secret_is_rejected() {
		$encoded = base64_encode( 'checkout' );
		$other   = $encoded . '.' . hash_hmac( 'sha256', $encoded, str_repeat( 'f', 64 ) );

		$this->assertNull( $this->helpers()->verify_signed_metadata( $other ) );
	}

	public function test_metadata_that_is_not_a_signed_string_is_rejected() {
		$this->assertNull( $this->helpers()->verify_signed_metadata( 'no-dot' ) );
		$this->assertNull( $this->helpers()->verify_signed_metadata( '' ) );
		$this->assertNull( $this->helpers()->verify_signed_metadata( null ) );
		$this->assertNull( $this->helpers()->verify_signed_metadata( array( 'a' ) ) );
		$this->assertNull( $this->helpers()->verify_signed_metadata( 42 ) );
	}

	public function test_a_correctly_signed_value_that_is_not_base64_is_rejected() {
		$encoded = 'not*base64!';
		$signed  = $encoded . '.' . hash_hmac( 'sha256', $encoded, self::SECRET );

		$this->assertNull( $this->helpers()->verify_signed_metadata( $signed ) );
	}

	// --- PKCE and random values ---

	public function test_the_pkce_challenge_is_the_rfc_7636_s256_of_the_verifier() {
		$this->assertSame(
			'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
			$this->helpers()->generate_pkce_challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' )
		);
	}

	public function test_the_pkce_verifier_is_86_base64url_characters_without_padding_and_differs_each_time() {
		$first  = $this->helpers()->generate_pkce_verifier();
		$second = $this->helpers()->generate_pkce_verifier();

		$this->assertSame( 86, strlen( $first ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $first );
		$this->assertNotSame( $first, $second );
	}

	public function test_an_empty_or_non_string_verifier_gives_no_challenge() {
		$this->assertSame( '', $this->helpers()->generate_pkce_challenge( '' ) );
		$this->assertSame( '', $this->helpers()->generate_pkce_challenge( null ) );
		$this->assertSame( '', $this->helpers()->generate_pkce_challenge( 123 ) );
	}

	public function test_random_hex_has_two_characters_per_byte() {
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $this->helpers()->generate_random_hex( 32 ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $this->helpers()->generate_random_hex( 8 ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $this->helpers()->generate_random_hex() );
	}

	// --- URLs ---

	public function test_the_endpoint_urls_hang_off_the_home_url() {
		WP::$home_url = 'https://shop.test/blog';

		$this->assertSame( 'https://shop.test/blog/agewallet/callback/', $this->helpers()->get_oidc_redirect_uri() );
		$this->assertSame( 'https://shop.test/blog/agewallet/success/', $this->helpers()->get_success_url() );
		$this->assertSame( 'https://shop.test/blog/agewallet/launch/', $this->helpers()->get_launch_url() );
	}

	public function test_the_endpoint_urls_pass_their_filters() {
		Filters\expectApplied( 'agewallet_redirect_uri' )->once()->andReturn( 'https://x.test/cb' );
		Filters\expectApplied( 'agewallet_success_url' )->once()->andReturn( 'https://x.test/ok' );
		Filters\expectApplied( 'agewallet_launch_url' )->once()->andReturn( 'https://x.test/go' );

		$this->assertSame( 'https://x.test/cb', $this->helpers()->get_oidc_redirect_uri() );
		$this->assertSame( 'https://x.test/ok', $this->helpers()->get_success_url() );
		$this->assertSame( 'https://x.test/go', $this->helpers()->get_launch_url() );
	}

	public function test_the_site_path_is_the_home_url_path_with_a_trailing_slash() {
		$this->assertSame( '/', $this->helpers()->get_site_path() );

		WP::$home_url = 'https://shop.test/blog';
		$this->assertSame( '/blog/', $this->helpers()->get_site_path() );

		Filters\expectApplied( 'agewallet_cookie_path' )->once()->with( '/blog/' )->andReturn( '/' );
		$this->assertSame( '/', $this->helpers()->get_site_path() );
	}

	public function test_the_current_url_is_rebuilt_from_the_request() {
		$_SERVER['HTTP_HOST']   = 'shop.test';
		$_SERVER['REQUEST_URI'] = '/products/?page=2';

		$this->assertSame( 'http://shop.test/products/?page=2', $this->helpers()->get_current_url() );

		$_SERVER['HTTPS'] = 'on';
		$this->assertSame( 'https://shop.test/products/?page=2', $this->helpers()->get_current_url() );

		$_SERVER['HTTPS'] = 'off';
		$this->assertSame( 'http://shop.test/products/?page=2', $this->helpers()->get_current_url() );

		unset( $_SERVER['HTTPS'] );
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
		$this->assertSame( 'https://shop.test/products/?page=2', $this->helpers()->get_current_url() );

		unset( $_SERVER['HTTP_X_FORWARDED_PROTO'] );
		$_SERVER['SERVER_PORT'] = '443';
		$this->assertSame( 'https://shop.test/products/?page=2', $this->helpers()->get_current_url() );
	}

	public function test_the_current_url_falls_back_to_the_server_name_then_the_home_url_host() {
		unset( $_SERVER['HTTP_HOST'] );
		$_SERVER['SERVER_NAME'] = 'named.test';
		$_SERVER['REQUEST_URI'] = '/a/';
		$this->assertSame( 'http://named.test/a/', $this->helpers()->get_current_url() );

		unset( $_SERVER['SERVER_NAME'] );
		$this->assertSame( 'http://example.test/a/', $this->helpers()->get_current_url() );
	}

	public function test_the_current_url_passes_its_filter() {
		Filters\expectApplied( 'agewallet_current_url' )->once()->with( 'http://example.test/' )->andReturn( 'https://f.test/' );

		$this->assertSame( 'https://f.test/', $this->helpers()->get_current_url() );
	}

	// --- Settings read for the gate ---

	/**
	 * @dataProvider analyticsIds
	 */
	public function test_analytics_ids_are_returned_only_when_well_formed( $option, $value, $key, $expected ) {
		WP::$options[ $option ] = $value;

		$this->assertSame( $expected, $this->helpers()->get_analytics_ids()[ $key ] );
	}

	public static function analyticsIds() {
		return array(
			'GA4 id'                  => array( 'agewallet_ga4_id', 'G-ABCD1234', 'ga4', 'G-ABCD1234' ),
			'GA4 old UA id'           => array( 'agewallet_ga4_id', 'UA-12345-1', 'ga4', '' ),
			'GA4 lower case'          => array( 'agewallet_ga4_id', 'g-abcd1234', 'ga4', '' ),
			'GTM id'                  => array( 'agewallet_gtm_id', 'GTM-ABCD', 'gtm', 'GTM-ABCD' ),
			'GTM too short'           => array( 'agewallet_gtm_id', 'GTM-A', 'gtm', '' ),
			'pixel id'                => array( 'agewallet_fb_pixel_id', '123456789', 'pixel', '123456789' ),
			'pixel too short'         => array( 'agewallet_fb_pixel_id', '12345', 'pixel', '' ),
			'pixel with letters'      => array( 'agewallet_fb_pixel_id', '12a456', 'pixel', '' ),
			'pixel with a script tag' => array( 'agewallet_fb_pixel_id', '123456<script>', 'pixel', '' ),
		);
	}

	public function test_the_gate_css_variables_carry_the_appearance_settings() {
		$css = $this->helpers()->get_gate_css_vars();

		$this->assertStringStartsWith( ':root{', $css );
		$this->assertStringEndsWith( '}', $css );
		$this->assertStringContainsString( '--aw-bg: #0c0a14;', $css );
		$this->assertStringContainsString( '--aw-purple: #9d70c9;', $css );
		$this->assertStringNotContainsString( '--aw-radius', $css );
		$this->assertStringNotContainsString( '--aw-btn-radius', $css );

		WP::$options['agewallet_color_btn_yes_bg'] = '#112233';
		WP::$options['agewallet_radius_card']      = '0';
		WP::$options['agewallet_radius_btn']       = 12;
		$css                                       = $this->helpers()->get_gate_css_vars();

		$this->assertStringContainsString( '--aw-purple: #112233;', $css );
		$this->assertStringContainsString( '--aw-radius: 0px;', $css );
		$this->assertStringContainsString( '--aw-btn-radius: 12px;', $css );
	}

	public function test_a_setting_value_with_markup_is_escaped_in_the_css() {
		WP::$options['agewallet_color_text'] = '</style><script>';

		$this->assertStringContainsString( '--aw-text: &lt;/style&gt;&lt;script&gt;;', $this->helpers()->get_gate_css_vars() );
	}
}
