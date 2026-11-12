<?php
/**
 * The built-in guides' markup (AgeWallet_Admin::parse_readme_markdown()): the HTML the Usage
 * Guide, CSS Guide and Developer Hooks pages show for headings, lists and paragraphs.
 */

namespace AgeWallet\Tests\Unit;

use AgeWallet\Tests\TestCase;

class AdminGuideMarkupTest extends TestCase {

	/** @var \AgeWallet_Admin */
	private $admin;

	protected function setUp(): void {
		parent::setUp();
		$this->admin = $this->withoutConstructor( 'AgeWallet_Admin' );
	}

	private function render( $text ) {
		return self::callPrivate( $this->admin, 'parse_readme_markdown', array( $text ) );
	}

	public function test_the_guide_is_wrapped_in_the_doc_container() {
		$html = $this->render( "= A. Rules =\n\nSome text." );
		$this->assertStringStartsWith( '<div class="aw-doc">', $html );
		$this->assertStringEndsWith( '</div>', $html );
		$this->assertStringContainsString( '<h3>A. Rules</h3>', $html );
		$this->assertStringContainsString( '<p>Some text.</p>', $html );
	}

	public function test_a_bullet_list_straight_under_a_line_of_text_is_its_own_list() {
		$html = $this->render( "You can define rules.\n* Gate All Terms: gates.\n* Exclude All Terms: opens." );
		$this->assertStringContainsString( '<p>You can define rules.</p>', $html );
		$this->assertStringContainsString( "<ul>\n<li>Gate All Terms: gates.</li>\n<li>Exclude All Terms: opens.</li>\n</ul>", $html );
		$this->assertStringNotContainsString( '<br', $html );
		$this->assertStringNotContainsString( '<p><ul>', $html );
	}

	public function test_a_numbered_list_straight_under_a_line_of_text_is_its_own_list() {
		$html = $this->render( "Before you start:\n1. Install.\n2. Connect." );
		$this->assertStringContainsString( '<p>Before you start:</p>', $html );
		$this->assertStringContainsString( "<ol>\n<li>Install.</li>\n<li>Connect.</li>\n</ol>", $html );
		$this->assertStringNotContainsString( '<br', $html );
	}

	public function test_lists_after_a_heading_or_a_blank_line_render_as_before() {
		$html = $this->render( "= B. Scope =\n* One.\n* Two.\n\nA paragraph.\n\n* Three." );
		$this->assertStringContainsString( "<h3>B. Scope</h3>\n\n<ul>\n<li>One.</li>\n<li>Two.</li>\n</ul>", $html );
		$this->assertStringContainsString( "<p>A paragraph.</p>\n\n<ul>\n<li>Three.</li>\n</ul>", $html );
		$this->assertSame( 2, substr_count( $html, '<ul>' ) );
	}

	public function test_a_paragraph_keeps_its_line_breaks() {
		$html = $this->render( "Line one\nline two." );
		$this->assertStringContainsString( "<p>Line one<br />\nline two.</p>", $html );
	}
}
