<?php
/**
 * The base class of the plugin's unit tests: Brain Monkey up and down around each test, the
 * in-memory WordPress state and the superglobals reset, the plugin's singletons and static state
 * cleared, and a few helpers for the plugin's objects, signed cookies and captured output.
 */

namespace AgeWallet\Tests;

use AgeWallet\Tests\Support\WP;
use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase {

	use MockeryPHPUnitIntegration;

	/** A 64-character hex HMAC secret, stored as the plugin's secret before each test. */
	const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

	/** The plugin's singleton classes, whose instances are dropped before and after each test. */
	const SINGLETONS = array(
		'AgeWallet_Helpers',
		'AgeWallet_Admin',
		'AgeWallet_OIDC_Handler',
		'AgeWallet_Gating_Manager',
		'AgeWallet_API',
		'AgeWallet_Product_Flags',
		'AgeWallet_WooCommerce',
	);

	/** @var array */
	private $server_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WP::reset();
		$this->server_backup = $_SERVER;
		$this->resetRequest();
		$this->resetPluginState();
		WP::$options[ \AgeWalletOIDCClientPro::OPT_HMAC_SECRET ] = self::SECRET;
	}

	protected function tearDown(): void {
		$this->resetPluginState();
		Monkey\tearDown();
		$_SERVER  = $this->server_backup;
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_COOKIE  = array();
		parent::tearDown();
	}

	/** A plain GET of the home page over HTTPS at example.test, with no cookies. */
	protected function resetRequest() {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_COOKIE  = array();
		foreach ( array( 'HTTPS', 'HTTP_X_FORWARDED_PROTO', 'SERVER_PORT', 'SERVER_NAME' ) as $key ) {
			unset( $_SERVER[ $key ] );
		}
		$_SERVER['HTTP_HOST']      = 'example.test';
		$_SERVER['REQUEST_URI']    = '/';
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	/** Drops every singleton instance and the static request state the plugin keeps. */
	protected function resetPluginState() {
		foreach ( self::SINGLETONS as $class ) {
			if ( class_exists( $class ) ) {
				self::setStatic( $class, 'instance', null );
			}
		}
		self::setStatic( 'AgeWallet_WooCommerce', 'order_actions_open', false );
		self::setStatic( 'AgeWallet_WooCommerce', 'store_api_checkout', false );
		self::setStatic( 'AgeWallet_WooCommerce', 'record_pending', array() );
		self::setStatic( 'AgeWallet_OIDC_Handler', 'request_origin', null );
	}

	/**
	 * @param string $class
	 * @param string $property
	 * @param mixed  $value
	 */
	public static function setStatic( $class, $property, $value ) {
		$ref = new \ReflectionProperty( $class, $property );
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}
		$ref->setValue( null, $value );
	}

	/**
	 * @param string $class
	 * @param string $property
	 * @return mixed
	 */
	public static function getStatic( $class, $property ) {
		$ref = new \ReflectionProperty( $class, $property );
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}
		return $ref->getValue();
	}

	/**
	 * Calls a private or protected method.
	 *
	 * @param object|string $target An instance, or a class name for a static method.
	 * @param string        $method
	 * @param array         $args
	 * @return mixed
	 */
	public static function callPrivate( $target, $method, array $args = array() ) {
		$ref = new \ReflectionMethod( is_object( $target ) ? get_class( $target ) : $target, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}
		return $ref->invokeArgs( is_object( $target ) ? $target : null, $args );
	}

	/** @return \AgeWallet_Helpers */
	protected function helpers() {
		return \AgeWallet_Helpers::instance();
	}

	/** @return \AgeWallet_Gating_Manager */
	protected function gating() {
		return \AgeWallet_Gating_Manager::instance();
	}

	/** @return \AgeWallet_WooCommerce */
	protected function woocommerce() {
		return \AgeWallet_WooCommerce::instance();
	}

	/**
	 * An instance built without running the constructor, for classes whose constructor does more
	 * than register hooks.
	 *
	 * @param string $class
	 * @return object
	 */
	protected function withoutConstructor( $class ) {
		return ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * A cookie value in the plugin's format, base64(json payload).hmac, signed with $secret.
	 *
	 * @param array  $payload
	 * @param string $secret
	 * @return string
	 */
	protected function signCookie( array $payload, $secret = self::SECRET ) {
		$encoded = base64_encode( json_encode( $payload ) );
		return $encoded . '.' . hash_hmac( 'sha256', $encoded, $secret );
	}

	/**
	 * Runs $fn with output buffering on and returns what it printed.
	 *
	 * @param callable $fn
	 * @return string
	 */
	protected function capture( callable $fn ) {
		ob_start();
		try {
			$fn();
		} finally {
			$out = ob_get_clean();
		}
		return $out;
	}

	/**
	 * The query parameters of a URL, decoded once, as PHP would fill $_GET.
	 *
	 * @param string $url
	 * @return array
	 */
	protected function query( $url ) {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		return $query;
	}
}
