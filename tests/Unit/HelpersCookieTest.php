<?php
/**
 * The HMAC verification cookie (AgeWallet_Helpers): how it is signed, what fails verification and
 * why, the payload the request cookie yields, and the secret everything is signed with.
 */

namespace AgeWallet\Tests\Unit;

use AgeWallet\Tests\Support\WP;
use AgeWallet\Tests\TestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

class HelpersCookieTest extends TestCase {

	const COOKIE = 'agewallet_verified';

	// --- Signing ---

	public function test_a_cookie_the_plugin_signed_verifies() {
		$cookie = $this->helpers()->generate_signed_cookie( 'salt123' );

		$this->assertTrue( $this->helpers()->verify_signed_cookie( $cookie ) );
	}

	public function test_the_cookie_is_the_base64_payload_a_dot_and_its_hmac_sha256() {
		$cookie = $this->helpers()->generate_signed_cookie( 'salt123' );

		list( $encoded, $signature ) = explode( '.', $cookie, 2 );
		$this->assertSame( hash_hmac( 'sha256', $encoded, self::SECRET ), $signature );
		$payload = json_decode( base64_decode( $encoded, true ), true );
		$this->assertSame( 'verified', $payload['status'] );
		$this->assertSame( 'salt123', $payload['salt'] );
	}

	public function test_the_payload_expires_twenty_four_hours_after_signing() {
		$before  = time();
		$payload = $this->payloadOf( $this->helpers()->generate_signed_cookie( 'n' ) );

		$this->assertGreaterThanOrEqual( $before + DAY_IN_SECONDS, $payload['exp'] );
		$this->assertLessThanOrEqual( time() + DAY_IN_SECONDS, $payload['exp'] );
	}

	public function test_a_salt_is_generated_when_none_is_given() {
		$payload = $this->payloadOf( $this->helpers()->generate_signed_cookie() );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $payload['salt'] );
	}

	public function test_metadata_is_carried_only_when_it_is_a_non_empty_string() {
		$helpers = $this->helpers();

		$this->assertSame( '{"k":1}', $this->payloadOf( $helpers->generate_signed_cookie( 'n', '{"k":1}' ) )['metadata'] );
		$this->assertArrayNotHasKey( 'metadata', $this->payloadOf( $helpers->generate_signed_cookie( 'n', '' ) ) );
		$this->assertArrayNotHasKey( 'metadata', $this->payloadOf( $helpers->generate_signed_cookie( 'n', null ) ) );
		$this->assertArrayNotHasKey( 'metadata', $this->payloadOf( $helpers->generate_signed_cookie( 'n', array( 'a' ) ) ) );
	}

	public function test_the_payload_filter_can_add_fields_and_the_cookie_still_verifies() {
		Filters\expectApplied( 'agewallet_cookie_payload' )->once()->andReturnUsing(
			static function ( $payload ) {
				$payload['site'] = 'main';
				return $payload;
			}
		);

		$cookie = $this->helpers()->generate_signed_cookie( 'n' );

		$this->assertSame( 'main', $this->payloadOf( $cookie )['site'] );
		$this->assertTrue( $this->helpers()->verify_signed_cookie( $cookie ) );
	}

	// --- Verifying ---

	public function test_a_changed_payload_fails_with_a_signature_mismatch_event() {
		Actions\expectDone( 'agewallet_cookie_validation_error' )->once()->with( 'signature_mismatch', \Mockery::type( 'string' ) );
		list( , $signature ) = explode( '.', $this->helpers()->generate_signed_cookie( 'n' ), 2 );
		$forged = base64_encode( json_encode( array( 'status' => 'verified', 'salt' => 'n', 'exp' => time() + 99999 ) ) ) . '.' . $signature;

		$this->assertFalse( $this->helpers()->verify_signed_cookie( $forged ) );
	}

	public function test_a_changed_signature_fails_with_a_signature_mismatch_event() {
		Actions\expectDone( 'agewallet_cookie_validation_error' )->once()->with( 'signature_mismatch', \Mockery::type( 'string' ) );
		$cookie = $this->helpers()->generate_signed_cookie( 'n' );
		$last   = substr( $cookie, -1 );
		$forged = substr( $cookie, 0, -1 ) . ( 'a' === $last ? 'b' : 'a' );

		$this->assertFalse( $this->helpers()->verify_signed_cookie( $forged ) );
	}

	public function test_a_cookie_signed_with_another_secret_fails() {
		Actions\expectDone( 'agewallet_cookie_validation_error' )->once()->with( 'signature_mismatch', \Mockery::type( 'string' ) );
		$cookie = $this->signCookie( array( 'status' => 'verified', 'salt' => 'n', 'exp' => time() + 100 ), str_repeat( 'f', 64 ) );

		$this->assertFalse( $this->helpers()->verify_signed_cookie( $cookie ) );
	}

	public function test_a_cookie_signed_before_the_secret_changed_fails() {
		$cookie = $this->helpers()->generate_signed_cookie( 'n' );
		WP::$options[ \AgeWalletOIDCClientPro::OPT_HMAC_SECRET ] = str_repeat( 'e', 64 );

		$this->assertFalse( $this->helpers()->verify_signed_cookie( $cookie ) );
	}

	public function test_an_expired_cookie_fails_with_an_expired_event() {
		Actions\expectDone( 'agewallet_cookie_validation_error' )->once()->with( 'expired', \Mockery::type( 'string' ) );
		$cookie = $this->signCookie( array( 'status' => 'verified', 'salt' => 'n', 'exp' => time() - 1 ) );

		$this->assertFalse( $this->helpers()->verify_signed_cookie( $cookie ) );
	}

	public function test_a_value_without_a_dot_fails_without_an_event() {
		Actions\expectDone( 'agewallet_cookie_validation_error' )->never();

		$this->assertFalse( $this->helpers()->verify_signed_cookie( 'abc' ) );
		$this->assertFalse( $this->helpers()->verify_signed_cookie( '' ) );
		$this->assertFalse( $this->helpers()->verify_signed_cookie( null ) );
	}

	public function test_a_correctly_signed_payload_that_is_not_json_or_has_no_expiry_fails_without_an_event() {
		Actions\expectDone( 'agewallet_cookie_validation_error' )->never();
		$encoded  = base64_encode( 'not json' );
		$not_json = $encoded . '.' . hash_hmac( 'sha256', $encoded, self::SECRET );
		$no_exp   = $this->signCookie( array( 'status' => 'verified', 'salt' => 'n' ) );

		$this->assertFalse( $this->helpers()->verify_signed_cookie( $not_json ) );
		$this->assertFalse( $this->helpers()->verify_signed_cookie( $no_exp ) );
	}

	// --- The request cookie ---

	public function test_the_request_cookie_payload_is_returned_when_it_is_valid() {
		$_COOKIE[ self::COOKIE ] = $this->helpers()->generate_signed_cookie( 'salt', '{"order":"checkout"}' );

		$payload = $this->helpers()->get_verified_cookie_payload();

		$this->assertSame( 'verified', $payload['status'] );
		$this->assertSame( 'salt', $payload['salt'] );
		$this->assertSame( '{"order":"checkout"}', $payload['metadata'] );
		$this->assertSame( '{"order":"checkout"}', agewallet_get_metadata() );
	}

	public function test_the_request_cookie_payload_is_null_when_the_cookie_is_missing_forged_expired_or_garbage() {
		$this->assertNull( $this->helpers()->get_verified_cookie_payload() );

		$_COOKIE[ self::COOKIE ] = $this->signCookie( array( 'status' => 'verified', 'salt' => 'n', 'exp' => time() + 100 ), str_repeat( 'f', 64 ) );
		$this->assertNull( $this->helpers()->get_verified_cookie_payload() );

		$_COOKIE[ self::COOKIE ] = $this->signCookie( array( 'status' => 'verified', 'salt' => 'n', 'exp' => time() - 1 ) );
		$this->assertNull( $this->helpers()->get_verified_cookie_payload() );

		$_COOKIE[ self::COOKIE ] = 'garbage';
		$this->assertNull( $this->helpers()->get_verified_cookie_payload() );
		$this->assertNull( agewallet_get_metadata() );
	}

	public function test_a_valid_cookie_without_metadata_gives_no_metadata() {
		$_COOKIE[ self::COOKIE ] = $this->helpers()->generate_signed_cookie( 'n' );

		$this->assertNull( agewallet_get_metadata() );
	}

	public function test_the_request_cookie_is_checked_once_per_request() {
		$_COOKIE[ self::COOKIE ] = $this->helpers()->generate_signed_cookie( 'n' );
		$this->assertTrue( $this->helpers()->has_valid_verified_cookie() );

		unset( $_COOKIE[ self::COOKIE ] );
		$this->assertTrue( $this->helpers()->has_valid_verified_cookie() );

		self::setStatic( 'AgeWallet_Helpers', 'instance', null );
		$this->assertFalse( $this->helpers()->has_valid_verified_cookie() );
	}

	public function test_the_visitor_counts_as_verified_by_the_cookie_or_by_the_filter() {
		$this->assertFalse( $this->helpers()->visitor_is_verified() );

		self::setStatic( 'AgeWallet_Helpers', 'instance', null );
		$_COOKIE[ self::COOKIE ] = $this->helpers()->generate_signed_cookie( 'n' );
		$this->assertTrue( $this->helpers()->visitor_is_verified() );

		self::setStatic( 'AgeWallet_Helpers', 'instance', null );
		unset( $_COOKIE[ self::COOKIE ] );
		Filters\expectApplied( 'agewallet_visitor_is_verified' )->once()->with( false )->andReturn( true );
		$this->assertTrue( $this->helpers()->visitor_is_verified() );
	}

	// --- The secret ---

	public function test_the_stored_secret_is_returned_unchanged() {
		$this->assertSame( self::SECRET, $this->helpers()->ensure_hmac_secret() );
		$this->assertSame( array(), WP::$updated_options );
	}

	public function test_a_missing_secret_is_generated_as_64_hex_characters_and_stored_without_autoload() {
		unset( WP::$options[ \AgeWalletOIDCClientPro::OPT_HMAC_SECRET ] );

		$secret = $this->helpers()->ensure_hmac_secret();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $secret );
		$this->assertSame( array( array( \AgeWalletOIDCClientPro::OPT_HMAC_SECRET, $secret, false ) ), WP::$updated_options );
	}

	public function test_a_short_or_non_string_secret_is_replaced() {
		foreach ( array( 'too-short', str_repeat( 'a', 31 ), 12345, array( 'x' ) ) as $bad ) {
			WP::$options[ \AgeWalletOIDCClientPro::OPT_HMAC_SECRET ] = $bad;

			$secret = $this->helpers()->ensure_hmac_secret();

			$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $secret );
			$this->assertSame( $secret, WP::$options[ \AgeWalletOIDCClientPro::OPT_HMAC_SECRET ] );
		}
	}

	public function test_a_32_character_secret_is_kept() {
		WP::$options[ \AgeWalletOIDCClientPro::OPT_HMAC_SECRET ] = str_repeat( 'b', 32 );

		$this->assertSame( str_repeat( 'b', 32 ), $this->helpers()->ensure_hmac_secret() );
		$this->assertSame( array(), WP::$updated_options );
	}

	public function test_the_secret_filter_changes_the_key_cookies_are_signed_and_checked_with() {
		Filters\expectApplied( 'agewallet_hmac_secret' )->twice()->with( self::SECRET )->andReturn( str_repeat( 'c', 64 ) );

		$cookie = $this->helpers()->generate_signed_cookie( 'n' );

		list( $encoded, $signature ) = explode( '.', $cookie, 2 );
		$this->assertSame( hash_hmac( 'sha256', $encoded, str_repeat( 'c', 64 ) ), $signature );
		$this->assertTrue( $this->helpers()->verify_signed_cookie( $cookie ) );
	}

	/**
	 * @param string $cookie
	 * @return array
	 */
	private function payloadOf( $cookie ) {
		list( $encoded ) = explode( '.', $cookie, 2 );
		return json_decode( base64_decode( $encoded, true ), true );
	}
}
