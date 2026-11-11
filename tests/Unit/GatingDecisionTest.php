<?php
/**
 * Whether a request is gated (AgeWallet_Gating_Manager::should_restrict_content()): the excluded
 * contexts, and the precedence of the post's own setting, the excluded paths, the taxonomy rules,
 * the global protection mode and the protected-content shortcode.
 */

namespace AgeWallet\Tests\Unit;

use AgeWallet\Tests\Support\WP;
use AgeWallet\Tests\TestCase;
use Brain\Monkey\Filters;

class GatingDecisionTest extends TestCase {

	const RESTRICT = \AgeWallet_Gating_Manager::META_KEY_FORCE_RESTRICT;
	const EXCLUDE  = \AgeWallet_Gating_Manager::META_KEY_FORCE_EXCLUDE;

	private function restricts( $post_id = null, $include_shortcode = true ) {
		return $this->gating()->should_restrict_content( $post_id, $include_shortcode );
	}

	private function blockMode( $mode ) {
		WP::$options[ \AgeWalletOIDCClientPro::OPT_BLOCK_MODE ] = $mode;
	}

	// --- Contexts ---

	public function test_nothing_is_gated_by_default() {
		$this->assertFalse( $this->restricts() );
	}

	public function test_block_everything_gates_the_request() {
		$this->blockMode( 'all' );

		$this->assertTrue( $this->restricts() );
	}

	/**
	 * @dataProvider excludedContexts
	 */
	public function test_excluded_contexts_are_never_gated( $tag ) {
		$this->blockMode( 'all' );
		WP::$is[ $tag ] = true;

		$this->assertFalse( $this->restricts() );
	}

	public static function excludedContexts() {
		return array(
			'wp-admin'           => array( 'admin' ),
			'feed'               => array( 'feed' ),
			'preview'            => array( 'preview' ),
			'customizer preview' => array( 'customize_preview' ),
		);
	}

	public function test_a_user_who_can_edit_others_posts_is_never_gated() {
		$this->blockMode( 'all' );
		WP::$user_id = 2;
		WP::$caps    = array( 'edit_others_posts' );

		$this->assertFalse( $this->restricts() );
	}

	public function test_a_logged_in_visitor_without_editing_rights_is_gated() {
		$this->blockMode( 'all' );
		WP::$user_id = 5;
		WP::$caps    = array( 'read' );

		$this->assertTrue( $this->restricts() );
	}

	public function test_the_context_check_is_skipped_when_a_post_id_is_given() {
		$this->blockMode( 'all' );
		WP::$is['admin'] = true;

		$this->assertTrue( $this->restricts( 42 ) );
	}

	// --- The post's own setting ---

	public function test_the_exclude_setting_wins_over_everything() {
		$this->blockMode( 'all' );
		WP::post( 42 );
		WP::$is['singular']               = true;
		WP::$post_meta[42][ self::EXCLUDE ]  = '1';
		WP::$post_meta[42][ self::RESTRICT ] = '1';

		$this->assertFalse( $this->restricts() );
	}

	public function test_the_require_setting_gates_a_post_nothing_else_gates() {
		WP::post( 42 );
		WP::$is['singular']                  = true;
		WP::$post_meta[42][ self::RESTRICT ] = '1';

		$this->assertTrue( $this->restricts() );
	}

	public function test_the_post_setting_is_read_for_the_given_post_id() {
		WP::$post_meta[7][ self::RESTRICT ] = '1';

		$this->assertTrue( $this->restricts( 7 ) );
		$this->assertFalse( $this->restricts( 8 ) );
	}

	public function test_only_the_value_1_counts_as_a_post_setting() {
		$this->blockMode( 'all' );
		WP::$post_meta[7][ self::EXCLUDE ]  = 'yes';
		WP::$post_meta[8][ self::RESTRICT ] = 'yes';

		$this->assertTrue( $this->restricts( 7 ) );
		$this->blockMode( 'none' );
		$this->assertFalse( $this->restricts( 8 ) );
	}

	// --- Excluded paths ---

	public function test_excluded_paths_match_anywhere_in_the_request_path() {
		$this->blockMode( 'all' );
		WP::$options['agewallet_excluded_paths'] = '/members, /private';

		foreach ( array( '/members/', '/members/join/?step=2', '/blog/members/' ) as $uri ) {
			$_SERVER['REQUEST_URI'] = $uri;
			$this->assertFalse( $this->restricts(), $uri );
		}
		foreach ( array( '/membership/', '/public/', '/' ) as $uri ) {
			$_SERVER['REQUEST_URI'] = $uri;
			$this->assertTrue( $this->restricts(), $uri );
		}
	}

	public function test_excluded_paths_without_a_leading_slash_are_ignored() {
		$this->blockMode( 'all' );
		WP::$options['agewallet_excluded_paths'] = 'members';
		$_SERVER['REQUEST_URI']                  = '/members/';

		$this->assertTrue( $this->restricts() );
	}

	public function test_excluded_paths_are_checked_against_the_permalink_when_a_post_id_is_given() {
		$this->blockMode( 'all' );
		WP::$options['agewallet_excluded_paths'] = '/private';
		WP::$permalinks[9]                       = 'https://example.test/private/thing/';
		WP::$permalinks[10]                      = 'https://example.test/open/thing/';

		$this->assertFalse( $this->restricts( 9 ) );
		$this->assertTrue( $this->restricts( 10 ) );
	}

	public function test_an_excluded_path_beats_the_require_setting_only_when_the_setting_is_absent() {
		WP::$options['agewallet_excluded_paths'] = '/private';
		WP::$permalinks[9]                       = 'https://example.test/private/thing/';
		WP::$post_meta[9][ self::RESTRICT ]      = '1';

		$this->assertTrue( $this->restricts( 9 ) );
	}

	// --- Taxonomy rules ---

	public function test_a_taxonomy_set_to_gate_all_gates_posts_with_terms_in_it() {
		WP::$options['agewallet_taxonomy_rules'] = array( 'category' => array( 'mode' => 'gate_all' ) );
		WP::terms( 42, 'category', array( 3 ) );

		$this->assertTrue( $this->restricts( 42 ) );
		$this->assertFalse( $this->restricts( 43 ) );
	}

	public function test_specific_terms_gate_only_posts_in_those_terms() {
		WP::$options['agewallet_taxonomy_rules'] = array(
			'category' => array(
				'mode'          => 'specific',
				'terms_gate'    => '3,4',
				'terms_exclude' => '',
			),
		);
		WP::terms( 42, 'category', array( 4, 8 ) );
		WP::terms( 43, 'category', array( 5 ) );

		$this->assertTrue( $this->restricts( 42 ) );
		$this->assertFalse( $this->restricts( 43 ) );
	}

	public function test_an_excluded_term_wins_over_a_gated_term_in_another_taxonomy_and_over_block_everything() {
		$this->blockMode( 'all' );
		WP::$options['agewallet_taxonomy_rules'] = array(
			'category' => array( 'mode' => 'gate_all' ),
			'post_tag' => array(
				'mode'          => 'specific',
				'terms_gate'    => '',
				'terms_exclude' => '9',
			),
		);
		WP::terms( 42, 'category', array( 1 ) );
		WP::terms( 42, 'post_tag', array( 9 ) );

		$this->assertFalse( $this->restricts( 42 ) );
	}

	public function test_a_taxonomy_set_to_exclude_all_allows_posts_with_terms_in_it() {
		$this->blockMode( 'all' );
		WP::$options['agewallet_taxonomy_rules'] = array( 'post_tag' => array( 'mode' => 'exclude_all' ) );
		WP::terms( 42, 'post_tag', array( 9 ) );

		$this->assertFalse( $this->restricts( 42 ) );
		$this->assertTrue( $this->restricts( 43 ) );
	}

	public function test_ignored_taxonomies_and_posts_without_terms_do_not_count() {
		WP::$options['agewallet_taxonomy_rules'] = array(
			'category' => array( 'mode' => 'ignore' ),
			'post_tag' => array( 'mode' => 'gate_all' ),
		);
		WP::terms( 42, 'category', array( 1 ) );

		$this->assertFalse( $this->restricts( 42 ) );
	}

	public function test_taxonomy_rules_apply_only_when_the_post_is_known() {
		WP::$options['agewallet_taxonomy_rules'] = array( 'category' => array( 'mode' => 'gate_all' ) );

		$this->assertFalse( $this->restricts() );
	}

	public function test_taxonomy_rules_can_be_changed_by_filter() {
		Filters\expectApplied( 'agewallet_taxonomy_rules' )->once()->with( array() )->andReturn( array( 'category' => array( 'mode' => 'gate_all' ) ) );
		WP::terms( 42, 'category', array( 1 ) );

		$this->assertTrue( $this->restricts( 42 ) );
	}

	// --- The global protection mode ---

	public function test_all_but_home_gates_every_page_except_the_front_page() {
		$this->blockMode( 'all_but_home' );
		WP::$options['page_on_front'] = 5;

		$this->assertFalse( $this->restricts( 5 ) );
		$this->assertTrue( $this->restricts( 6 ) );

		WP::$is['front_page'] = true;
		$this->assertFalse( $this->restricts() );
		WP::$is['front_page'] = false;
		$this->assertTrue( $this->restricts() );
	}

	public function test_specific_paths_gate_the_matching_request_paths() {
		$this->blockMode( 'specific' );
		WP::$options[ \AgeWalletOIDCClientPro::OPT_BLOCKED_PATHS ] = '/shop, /adult, nope';

		foreach ( array( '/shop/', '/shop/item-1/?v=2', '/adult/', '/x/adult/' ) as $uri ) {
			$_SERVER['REQUEST_URI'] = $uri;
			$this->assertTrue( $this->restricts(), $uri );
		}
		foreach ( array( '/about/', '/nope/', '/shopping/' ) as $uri ) {
			$_SERVER['REQUEST_URI'] = $uri;
			$this->assertFalse( $this->restricts(), $uri );
		}
	}

	public function test_specific_paths_are_checked_against_the_permalink_when_a_post_id_is_given() {
		$this->blockMode( 'specific' );
		WP::$options[ \AgeWalletOIDCClientPro::OPT_BLOCKED_PATHS ] = '/shop';
		WP::$permalinks[9]  = 'https://example.test/shop/thing/';
		WP::$permalinks[10] = 'https://example.test/blog/thing/';

		$this->assertTrue( $this->restricts( 9 ) );
		$this->assertFalse( $this->restricts( 10 ) );
	}

	public function test_specific_mode_without_paths_and_unknown_modes_gate_nothing() {
		$this->blockMode( 'specific' );
		$this->assertFalse( $this->restricts() );

		$this->blockMode( 'everything' );
		$this->assertFalse( $this->restricts() );
	}

	public function test_the_decision_filter_gets_the_post_id_and_can_override_the_answer() {
		Filters\expectApplied( 'agewallet_should_gate_request' )->once()->with( false, 42 )->andReturn( true );

		$this->assertTrue( $this->restricts( 42 ) );
	}

	public function test_the_decision_filter_is_applied_to_a_gated_answer_too() {
		$this->blockMode( 'all' );
		Filters\expectApplied( 'agewallet_should_gate_request' )->once()->with( true, null )->andReturn( false );

		$this->assertFalse( $this->restricts() );
	}

	// --- The protected-content shortcode ---

	public function test_a_page_with_the_protected_shortcode_needs_the_gate_assets_but_no_overlay() {
		WP::post( 42, array( 'post_content' => 'Intro [agewallet_protected]Secret[/agewallet_protected]' ) );
		WP::$is['singular'] = true;

		$this->assertTrue( $this->restricts() );
		$this->assertFalse( $this->restricts( null, false ) );
	}

	public function test_the_shortcode_never_gates_in_strict_mode() {
		WP::$options['agewallet_protection_mode'] = 'strict';
		WP::post( 42, array( 'post_content' => '[agewallet_protected]Secret[/agewallet_protected]' ) );
		WP::$is['singular'] = true;

		$this->assertFalse( $this->restricts() );
	}

	public function test_the_shortcode_counts_only_for_a_known_post() {
		WP::post( 42, array( 'post_content' => '[agewallet_protected]Secret[/agewallet_protected]' ) );

		$this->assertFalse( $this->restricts() );
		$this->assertTrue( $this->restricts( 42 ) );
	}
}
