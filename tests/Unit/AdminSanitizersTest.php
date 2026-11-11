<?php
/**
 * The settings sanitizers (AgeWallet_Admin): what each setting is stored as for the values the
 * settings forms can post.
 */

namespace AgeWallet\Tests\Unit;

use AgeWallet\Tests\TestCase;
use Brain\Monkey\Functions;

class AdminSanitizersTest extends TestCase {

	/** @var \AgeWallet_Admin */
	private $admin;

	protected function setUp(): void {
		parent::setUp();
		$this->admin = $this->withoutConstructor( 'AgeWallet_Admin' );
	}

	public function test_a_positive_int_is_the_absolute_integer() {
		$this->assertSame( 12, $this->admin->sanitize_positive_int( '12' ) );
		$this->assertSame( 3, $this->admin->sanitize_positive_int( '-3' ) );
		$this->assertSame( 0, $this->admin->sanitize_positive_int( 'twelve' ) );
	}

	public function test_metadata_text_loses_its_tags_and_is_cut_at_the_byte_limit() {
		$this->assertSame( 'site tag', $this->admin->sanitize_metadata( '<b>site</b> tag<script>x()</script>' ) );
		$this->assertSame( '5', $this->admin->sanitize_metadata( 5 ) );
		$this->assertSame( '', $this->admin->sanitize_metadata( array( 'a' ) ) );
		$this->assertSame( str_repeat( 'a', 4096 ), $this->admin->sanitize_metadata( str_repeat( 'a', 5000 ) ) );
	}

	public function test_the_cart_fields_keep_only_known_keys_in_the_fixed_order() {
		$this->assertSame( array(), $this->admin->sanitize_wc_metadata_fields( 'cart_hash' ) );
		$this->assertSame(
			array( 'cart_hash', 'cart_total', 'currency' ),
			$this->admin->sanitize_wc_metadata_fields( array( 'currency', 'Cart_Total', 'bogus', 'cart_hash' ) )
		);
	}

	public function test_the_metadata_mode_falls_back_to_static() {
		$this->assertSame( 'auto', $this->admin->sanitize_metadata_mode( 'auto' ) );
		$this->assertSame( 'off', $this->admin->sanitize_metadata_mode( 'off' ) );
		$this->assertSame( 'static', $this->admin->sanitize_metadata_mode( 'manual' ) );
		$this->assertSame( 'static', $this->admin->sanitize_metadata_mode( null ) );
	}

	public function test_the_auto_fields_keep_only_known_keys_in_the_fixed_order() {
		$this->assertSame( array(), $this->admin->sanitize_auto_metadata_fields( 'post_id' ) );
		$this->assertSame(
			array( 'post_id', 'term_slug' ),
			$this->admin->sanitize_auto_metadata_fields( array( 'term_slug', 'POST_ID', 'nope' ) )
		);
	}

	public function test_the_gate_copy_goes_through_wp_kses_post() {
		Functions\expect( 'wp_kses_post' )->once()->with( '<p onclick="x()">Hi</p>' )->andReturn( '<p>Hi</p>' );

		$this->assertSame( '<p>Hi</p>', $this->admin->sanitize_wysiwyg( '<p onclick="x()">Hi</p>' ) );
	}

	public function test_the_radius_keeps_blank_apart_from_zero_and_clamps_to_32() {
		$this->assertSame( '', $this->admin->sanitize_radius( '' ) );
		$this->assertSame( '', $this->admin->sanitize_radius( '   ' ) );
		$this->assertSame( 0, $this->admin->sanitize_radius( '0' ) );
		$this->assertSame( 12, $this->admin->sanitize_radius( '12' ) );
		$this->assertSame( 32, $this->admin->sanitize_radius( '40' ) );
		$this->assertSame( 5, $this->admin->sanitize_radius( '-5' ) );
		$this->assertSame( 0, $this->admin->sanitize_radius( 'round' ) );
	}

	public function test_analytics_ids_are_upper_cased_trimmed_and_checked_against_their_shape() {
		$this->assertSame( 'G-ABC123', $this->admin->sanitize_ga4_id( ' g-abc123 ' ) );
		$this->assertSame( '', $this->admin->sanitize_ga4_id( 'G-AB' ) );
		$this->assertSame( '', $this->admin->sanitize_ga4_id( 'UA-123456' ) );

		$this->assertSame( 'GTM-ABCD', $this->admin->sanitize_gtm_id( 'gtm-abcd' ) );
		$this->assertSame( '', $this->admin->sanitize_gtm_id( 'GTM-1' ) );

		$this->assertSame( '1234567', $this->admin->sanitize_fb_pixel_id( ' 1234567 ' ) );
		$this->assertSame( '', $this->admin->sanitize_fb_pixel_id( '12345' ) );
		$this->assertSame( '', $this->admin->sanitize_fb_pixel_id( '12345a' ) );
	}

	public function test_a_checkbox_is_on_only_when_the_form_posts_1() {
		$this->assertSame( 1, $this->admin->sanitize_checkbox( '1' ) );
		$this->assertSame( 0, $this->admin->sanitize_checkbox( '0' ) );
		$this->assertSame( 0, $this->admin->sanitize_checkbox( 'on' ) );
		$this->assertSame( 0, $this->admin->sanitize_checkbox( null ) );
	}

	/**
	 * @dataProvider gateModes
	 */
	public function test_the_checkout_gating_mode_accepts_the_three_modes_and_the_old_checkbox_values( $posted, $stored ) {
		$this->assertSame( $stored, $this->admin->sanitize_wc_gate_mode( $posted ) );
	}

	public static function gateModes() {
		return array(
			'old checkbox on'       => array( 1, 'force-always' ),
			'old checkbox on text'  => array( '1', 'force-always' ),
			'true'                  => array( true, 'force-always' ),
			'old checkbox off'      => array( 0, 'off' ),
			'old checkbox off text' => array( '0', 'off' ),
			'empty'                 => array( '', 'off' ),
			'null'                  => array( null, 'off' ),
			'false'                 => array( false, 'off' ),
			'off'                   => array( 'off', 'off' ),
			'force always'          => array( 'force-always', 'force-always' ),
			'conditional on cart'   => array( 'conditional-on-cart', 'conditional-on-cart' ),
			'unknown'               => array( 'sometimes', 'off' ),
		);
	}

	public function test_the_block_mode_falls_back_to_none() {
		foreach ( array( 'none', 'all_but_home', 'all', 'specific' ) as $mode ) {
			$this->assertSame( $mode, $this->admin->sanitize_block_mode( $mode ) );
		}
		$this->assertSame( 'none', $this->admin->sanitize_block_mode( 'everything' ) );
	}

	public function test_paths_are_trimmed_given_a_leading_slash_cleaned_and_de_duplicated() {
		$this->assertSame( '', $this->admin->sanitize_paths_textarea( '' ) );
		$this->assertSame( '', $this->admin->sanitize_paths_textarea( '  ,  ' ) );
		$this->assertSame( '/shop,/adult,/members', $this->admin->sanitize_paths_textarea( 'shop, /adult ,, members' ) );
		$this->assertSame( '/shop', $this->admin->sanitize_paths_textarea( '/shop,shop,/shop' ) );
		$this->assertSame( '/', $this->admin->sanitize_paths_textarea( '/' ) );
		$this->assertSame( '/a', $this->admin->sanitize_paths_textarea( '/a<b>' ) );
	}

	public function test_taxonomy_rules_keep_a_clean_mode_and_integer_term_lists_per_taxonomy() {
		$this->assertSame( array(), $this->admin->sanitize_taxonomy_rules( 'category' ) );

		$this->assertSame(
			array(
				'category' => array(
					'mode'          => 'specific',
					'terms_gate'    => '3,4',
					'terms_exclude' => '',
				),
				'post_tag' => array(
					'mode'          => 'ignore',
					'terms_gate'    => '',
					'terms_exclude' => '9',
				),
			),
			$this->admin->sanitize_taxonomy_rules(
				array(
					'Category!' => array( 'mode' => 'specific', 'terms_gate' => '3, 4, x, 0', 'terms_exclude' => '' ),
					'post_tag'  => array( 'mode' => 'everything', 'terms_exclude' => '9' ),
				)
			)
		);
	}
}
