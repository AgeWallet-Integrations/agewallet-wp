<?php
/**
 * The metadata attached to a verification (AgeWallet_Metadata_Builder): off, the static text, the
 * auto-composed page-context fields and their filter; and the per-visitor fields merged in at
 * verify time (AgeWallet_OIDC_Handler::merge_per_visitor_fields()).
 */

namespace AgeWallet\Tests\Unit;

use AgeWallet\Tests\Support\WP;
use AgeWallet\Tests\TestCase;
use Brain\Monkey\Filters;

class MetadataBuilderTest extends TestCase {

	private function mode( $mode ) {
		WP::$options['agewallet_metadata_mode'] = $mode;
	}

	private function select( array $fields ) {
		WP::$options['agewallet_auto_metadata_fields'] = $fields;
	}

	private function built() {
		$json = \AgeWallet_Metadata_Builder::build();
		return null === $json ? null : json_decode( $json, true );
	}

	public function test_the_modes_and_the_auto_fields_are_fixed_lists() {
		$this->assertSame( array( 'off', 'static', 'auto' ), \AgeWallet_Metadata_Builder::allowed_modes() );
		$this->assertSame(
			array( 'post_id', 'post_slug', 'post_type', 'user_id', 'user_role', 'request_path', 'page_type', 'term_id', 'term_slug', 'term_taxonomy', 'search_query', 'archive_post_type' ),
			\AgeWallet_Metadata_Builder::allowed_auto_fields()
		);
	}

	public function test_strict_mode_is_read_from_the_protection_mode_option() {
		$this->assertFalse( \AgeWallet_Metadata_Builder::is_strict_mode() );

		WP::$options['agewallet_protection_mode'] = 'strict';
		$this->assertTrue( \AgeWallet_Metadata_Builder::is_strict_mode() );
	}

	// --- Off and static ---

	public function test_the_default_mode_is_static_and_an_empty_text_gives_no_metadata() {
		$this->assertNull( \AgeWallet_Metadata_Builder::build() );

		WP::$options[ \AgeWalletOIDCClientPro::OPT_METADATA_DEFAULT ] = 'site-tag';
		$this->assertSame( 'site-tag', \AgeWallet_Metadata_Builder::build() );

		WP::$options[ \AgeWalletOIDCClientPro::OPT_METADATA_DEFAULT ] = 0;
		$this->assertSame( '0', \AgeWallet_Metadata_Builder::build() );
	}

	public function test_off_and_unknown_modes_give_no_metadata() {
		WP::$options[ \AgeWalletOIDCClientPro::OPT_METADATA_DEFAULT ] = 'site-tag';

		$this->mode( 'off' );
		$this->assertNull( \AgeWallet_Metadata_Builder::build() );

		$this->mode( 'manual' );
		$this->assertNull( \AgeWallet_Metadata_Builder::build() );
	}

	// --- Auto ---

	public function test_auto_mode_needs_selected_fields() {
		$this->mode( 'auto' );
		$this->assertNull( \AgeWallet_Metadata_Builder::build() );

		$this->select( array() );
		$this->assertNull( \AgeWallet_Metadata_Builder::build() );

		WP::$options['agewallet_auto_metadata_fields'] = 'post_id';
		$this->assertNull( \AgeWallet_Metadata_Builder::build() );
	}

	public function test_a_single_post_gives_its_id_slug_type_path_and_page_type_in_the_selected_order() {
		$this->mode( 'auto' );
		$this->select( array( 'page_type', 'post_id', 'post_slug', 'post_type', 'request_path' ) );
		WP::post( 7, array( 'post_name' => 'hello-world', 'post_type' => 'page' ) );
		WP::$is['singular']     = true;
		$_SERVER['REQUEST_URI'] = '/hello-world/?utm=1';

		$this->assertSame(
			array(
				'page_type'    => 'singular',
				'post_id'      => 7,
				'post_slug'    => 'hello-world',
				'post_type'    => 'page',
				'request_path' => '/hello-world/',
			),
			$this->built()
		);
	}

	public function test_fields_without_a_value_on_this_request_are_left_out() {
		$this->mode( 'auto' );
		$this->select( array( 'post_id', 'post_slug', 'post_type', 'term_id', 'term_slug', 'term_taxonomy', 'search_query', 'archive_post_type', 'page_type', 'request_path' ) );
		unset( $_SERVER['REQUEST_URI'] );

		$this->assertNull( \AgeWallet_Metadata_Builder::build() );
	}

	public function test_unknown_and_per_visitor_fields_are_never_resolved_at_build_time() {
		$this->mode( 'auto' );
		$this->select( array( 'user_id', 'user_role', 'colour', 42, 'request_path' ) );
		WP::$user_id    = 5;
		WP::$user_roles = array( 'editor' );

		$this->assertSame( array( 'request_path' => '/' ), $this->built() );
	}

	/**
	 * @dataProvider termArchives
	 */
	public function test_a_term_archive_gives_the_term_and_its_page_type( $tag, $taxonomy ) {
		$this->mode( 'auto' );
		$this->select( array( 'term_id', 'term_slug', 'term_taxonomy', 'page_type' ) );
		WP::$is[ $tag ]     = true;
		WP::$queried_object = new \WP_Term( 3, $taxonomy, 'news' );

		$this->assertSame(
			array( 'term_id' => 3, 'term_slug' => 'news', 'term_taxonomy' => $taxonomy, 'page_type' => $tag ),
			$this->built()
		);
	}

	public static function termArchives() {
		return array(
			'category' => array( 'category', 'category' ),
			'tag'      => array( 'tag', 'post_tag' ),
			'taxonomy' => array( 'tax', 'genre' ),
		);
	}

	public function test_a_term_archive_without_a_queried_term_gives_no_term_fields() {
		$this->mode( 'auto' );
		$this->select( array( 'term_id', 'page_type' ) );
		WP::$is['category'] = true;

		$this->assertSame( array( 'page_type' => 'category' ), $this->built() );
	}

	public function test_a_search_gives_its_cleaned_query() {
		$this->mode( 'auto' );
		$this->select( array( 'search_query', 'page_type' ) );
		WP::$is['search'] = true;
		WP::$search_query = ' red <b>wine</b>  bottles ';

		$this->assertSame( array( 'search_query' => 'red wine bottles', 'page_type' => 'search' ), $this->built() );

		WP::$search_query = '';
		$this->assertSame( array( 'page_type' => 'search' ), $this->built() );
	}

	public function test_a_post_type_archive_gives_its_post_type() {
		$this->mode( 'auto' );
		$this->select( array( 'archive_post_type', 'page_type' ) );
		WP::post( 9, array( 'post_type' => 'product' ) );
		WP::$is['post_type_archive'] = true;
		WP::$is['archive']           = true;

		$this->assertSame( array( 'archive_post_type' => 'product', 'page_type' => 'archive' ), $this->built() );
	}

	/**
	 * @dataProvider pageTypes
	 */
	public function test_the_page_type_names_the_kind_of_page( $tag, $expected ) {
		$this->mode( 'auto' );
		$this->select( array( 'page_type' ) );
		if ( $tag ) {
			WP::$is[ $tag ] = true;
		}

		$this->assertSame( null === $expected ? null : array( 'page_type' => $expected ), $this->built() );
	}

	public static function pageTypes() {
		return array(
			'author'     => array( 'author', 'author' ),
			'date'       => array( 'date', 'date' ),
			'404'        => array( '404', '404' ),
			'front page' => array( 'front_page', 'home' ),
			'blog home'  => array( 'home', 'home' ),
			'archive'    => array( 'archive', 'archive' ),
			'nothing'    => array( null, null ),
		);
	}

	public function test_the_auto_fields_filter_can_change_or_empty_the_fields() {
		$this->mode( 'auto' );
		$this->select( array( 'request_path' ) );
		Filters\expectApplied( 'agewallet_auto_metadata' )->twice()->with( array( 'request_path' => '/' ) )->andReturn( array( 'campaign' => 'spring' ), array() );

		$this->assertSame( array( 'campaign' => 'spring' ), $this->built() );
		$this->assertNull( \AgeWallet_Metadata_Builder::build() );
	}

	// --- Per-visitor fields at verify time ---

	private function merge( $existing ) {
		return $this->withoutConstructor( 'AgeWallet_OIDC_Handler' )->merge_per_visitor_fields( $existing );
	}

	private function signIn( $id, array $roles ) {
		WP::$user_id    = $id;
		WP::$user_roles = $roles;
	}

	public function test_per_visitor_fields_are_merged_only_in_auto_mode() {
		$this->select( array( 'user_id', 'user_role' ) );
		$this->signIn( 5, array( 'editor' ) );

		$this->assertSame( 'site-tag', $this->merge( 'site-tag' ) );
		$this->assertNull( $this->merge( null ) );
	}

	public function test_the_user_id_and_first_role_are_added_for_a_logged_in_visitor() {
		$this->mode( 'auto' );
		$this->select( array( 'user_id', 'user_role' ) );
		$this->signIn( 5, array( 'editor', 'author' ) );

		$this->assertSame( '{"user_id":5,"user_role":"editor"}', $this->merge( null ) );
		$this->assertSame( '{"post_id":3,"user_id":5,"user_role":"editor"}', $this->merge( '{"post_id":3}' ) );
	}

	public function test_only_the_selected_per_visitor_fields_are_added() {
		$this->mode( 'auto' );
		$this->signIn( 5, array( 'editor' ) );

		$this->select( array( 'user_id' ) );
		$this->assertSame( '{"user_id":5}', $this->merge( null ) );

		$this->select( array( 'user_role' ) );
		$this->assertSame( '{"user_role":"editor"}', $this->merge( null ) );
	}

	public function test_a_logged_out_visitor_or_one_without_roles_adds_nothing() {
		$this->mode( 'auto' );
		$this->select( array( 'user_id', 'user_role' ) );

		$this->assertSame( '{"post_id":3}', $this->merge( '{"post_id":3}' ) );

		$this->signIn( 5, array() );
		$this->assertSame( '{"user_id":5}', $this->merge( null ) );
	}

	public function test_existing_metadata_that_is_not_json_is_left_alone() {
		$this->mode( 'auto' );
		$this->select( array( 'user_id' ) );
		$this->signIn( 5, array( 'editor' ) );

		$this->assertSame( 'plain text', $this->merge( 'plain text' ) );
	}

	public function test_a_selection_that_is_not_a_list_adds_nothing() {
		$this->mode( 'auto' );
		WP::$options['agewallet_auto_metadata_fields'] = 'user_id';
		$this->signIn( 5, array( 'editor' ) );

		$this->assertNull( $this->merge( null ) );
	}
}
