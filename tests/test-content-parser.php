<?php
/**
 * Content Parser Functions Tests
 *
 * @package HM\Rehydrator\Tests
 */

namespace HM\Rehydrator\Tests;

use WP_UnitTestCase;
use HM\Rehydrator\Content_Parser;

/**
 * Test content parsing and HTML-to-blocks conversion functions.
 */
class ContentParserTest extends WP_UnitTestCase {

	/**
	 * Get fixture file contents.
	 *
	 * @param string $name Fixture name (without .html extension).
	 * @return string Fixture contents.
	 */
	protected function get_html_fixture( string $name ) : string {
		return file_get_contents( __DIR__ . '/fixtures/html/' . $name . '.html' );
	}

	// =========================================================================
	// Block Detection Tests
	// =========================================================================

	/**
	 * Test content_has_blocks returns true for block content.
	 */
	public function test_content_has_blocks_returns_true_for_blocks() {
		$content = '<!-- wp:paragraph --><p>Test</p><!-- /wp:paragraph -->';

		$this->assertTrue( Content_Parser\content_has_blocks( $content ) );
	}

	/**
	 * Test content_has_blocks returns false for classic content.
	 */
	public function test_content_has_blocks_returns_false_for_classic() {
		$content = '<p>This is classic content without block markers.</p>';

		$this->assertFalse( Content_Parser\content_has_blocks( $content ) );
	}

	/**
	 * Test content_has_blocks returns false for empty content.
	 */
	public function test_content_has_blocks_returns_false_for_empty() {
		$this->assertFalse( Content_Parser\content_has_blocks( '' ) );
	}

	/**
	 * Test is_freeform_block detects null blockName.
	 */
	public function test_is_freeform_block_with_null_name() {
		$block = [
			'blockName' => null,
			'innerHTML' => '<p>Test</p>',
		];

		$this->assertTrue( Content_Parser\is_freeform_block( $block ) );
	}

	/**
	 * Test is_freeform_block detects core/freeform.
	 */
	public function test_is_freeform_block_with_core_freeform() {
		$block = [
			'blockName' => 'core/freeform',
			'innerHTML' => '<p>Test</p>',
		];

		$this->assertTrue( Content_Parser\is_freeform_block( $block ) );
	}

	/**
	 * Test is_freeform_block returns false for other blocks.
	 */
	public function test_is_freeform_block_returns_false_for_other_blocks() {
		$block = [
			'blockName' => 'core/paragraph',
			'innerHTML' => '<p>Test</p>',
		];

		$this->assertFalse( Content_Parser\is_freeform_block( $block ) );
	}

	// =========================================================================
	// Embed Detection Tests
	// =========================================================================

	/**
	 * Test is_oembed_url uses WordPress core's provider registry.
	 *
	 * Just a sanity check - detailed provider testing is WordPress core's responsibility.
	 */
	public function test_is_oembed_url_delegates_to_core() {
		// Known provider should return true.
		$this->assertTrue( Content_Parser\is_oembed_url( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ) );

		// Unknown URL should return false.
		$this->assertFalse( Content_Parser\is_oembed_url( 'https://example.com/page' ) );
	}

	/**
	 * Test detect_embeddable_html detects YouTube iframe.
	 */
	public function test_detect_embeddable_html_youtube_iframe() {
		$html = '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>';
		$result = Content_Parser\detect_embeddable_html( $html );

		$this->assertIsArray( $result );
		$this->assertEquals( 'oembed', $result['type'] );
		// Returns a URL that WordPress oEmbed recognizes (may be original or converted).
		$this->assertTrue( Content_Parser\is_oembed_url( $result['url'] ) );
		$this->assertStringContainsString( 'dQw4w9WgXcQ', $result['url'] );
	}

	/**
	 * Test detect_embeddable_html detects Vimeo iframe.
	 */
	public function test_detect_embeddable_html_vimeo_iframe() {
		$html = '<iframe src="https://player.vimeo.com/video/123456789"></iframe>';
		$result = Content_Parser\detect_embeddable_html( $html );

		$this->assertIsArray( $result );
		$this->assertEquals( 'oembed', $result['type'] );
		// Returns a URL that WordPress oEmbed recognizes (may be original or converted).
		$this->assertTrue( Content_Parser\is_oembed_url( $result['url'] ) );
		$this->assertStringContainsString( '123456789', $result['url'] );
	}

	/**
	 * Test detect_embeddable_html returns false for generic iframe.
	 */
	public function test_detect_embeddable_html_generic_iframe() {
		$html = '<iframe src="https://example.com/embed"></iframe>';
		$result = Content_Parser\detect_embeddable_html( $html );

		$this->assertFalse( $result );
	}

	/**
	 * Test get_embed_provider_name returns correct providers.
	 */
	public function test_get_embed_provider_name() {
		$this->assertEquals( 'youtube', Content_Parser\get_embed_provider_name( 'https://youtube.com/watch?v=123' ) );
		$this->assertEquals( 'youtube', Content_Parser\get_embed_provider_name( 'https://youtu.be/123' ) );
		$this->assertEquals( 'vimeo', Content_Parser\get_embed_provider_name( 'https://vimeo.com/123' ) );
		$this->assertEquals( 'twitter', Content_Parser\get_embed_provider_name( 'https://twitter.com/user' ) );
		$this->assertEquals( 'twitter', Content_Parser\get_embed_provider_name( 'https://x.com/user' ) );
		$this->assertEquals( 'generic', Content_Parser\get_embed_provider_name( 'https://example.com' ) );
	}

	/**
	 * Test convert_html_to_embed_block converts YouTube.
	 */
	public function test_convert_html_to_embed_block_youtube() {
		$block = [
			'blockName' => 'core/html',
			'innerHTML' => '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>',
		];

		$result = Content_Parser\convert_html_to_embed_block( $block );

		$this->assertIsArray( $result );
		$this->assertEquals( 'core/embed', $result['blockName'] );
		$this->assertEquals( 'youtube', $result['attrs']['providerNameSlug'] );
	}

	/**
	 * Test convert_html_to_embed_block returns null for non-html blocks.
	 */
	public function test_convert_html_to_embed_block_non_html_block() {
		$block = [
			'blockName' => 'core/paragraph',
			'innerHTML' => '<p>Test</p>',
		];

		$this->assertNull( Content_Parser\convert_html_to_embed_block( $block ) );
	}

	/**
	 * Test convert_html_to_embed_block returns null for non-embeddable.
	 */
	public function test_convert_html_to_embed_block_non_embeddable() {
		$block = [
			'blockName' => 'core/html',
			'innerHTML' => '<div>Custom HTML</div>',
		];

		$this->assertNull( Content_Parser\convert_html_to_embed_block( $block ) );
	}

	// =========================================================================
	// Block Creation Tests
	// =========================================================================

	/**
	 * Test create_paragraph_block creates proper structure.
	 */
	public function test_create_paragraph_block() {
		$block = Content_Parser\create_paragraph_block( 'Test content' );

		$this->assertEquals( 'core/paragraph', $block['blockName'] );
		$this->assertStringContainsString( '<p>Test content</p>', $block['innerHTML'] );
	}

	/**
	 * Test create_separator_block creates proper structure.
	 */
	public function test_create_separator_block() {
		$block = Content_Parser\create_separator_block();

		$this->assertEquals( 'core/separator', $block['blockName'] );
		$this->assertStringContainsString( '<hr', $block['innerHTML'] );
	}

	/**
	 * Test create_freeform_block creates proper structure.
	 */
	public function test_create_freeform_block() {
		$html = '<p>Classic content</p>';
		$block = Content_Parser\create_freeform_block( $html );

		$this->assertEquals( 'core/freeform', $block['blockName'] );
		$this->assertEquals( $html, $block['innerHTML'] );
	}

	// =========================================================================
	// HTML to Blocks Conversion Tests
	// =========================================================================

	/**
	 * Test convert_html_to_blocks with simple paragraph.
	 */
	public function test_convert_html_to_blocks_simple_paragraph() {
		$html = '<p>Simple paragraph text.</p>';
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertCount( 1, $blocks );
		$this->assertEquals( 'core/paragraph', $blocks[0]['blockName'] );
	}

	/**
	 * Test convert_html_to_blocks with TinyMCE-style content (no p tags).
	 */
	public function test_convert_html_to_blocks_tinymce_no_p_tags() {
		$html = "First paragraph.\n\nSecond paragraph.";
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertCount( 2, $blocks );
		$this->assertEquals( 'core/paragraph', $blocks[0]['blockName'] );
		$this->assertEquals( 'core/paragraph', $blocks[1]['blockName'] );
	}

	/**
	 * Test convert_html_to_blocks with headings.
	 */
	public function test_convert_html_to_blocks_headings() {
		$html = '<h2>Heading Two</h2><h3>Heading Three</h3>';
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertCount( 2, $blocks );
		$this->assertEquals( 'core/heading', $blocks[0]['blockName'] );
		$this->assertEquals( 2, $blocks[0]['attrs']['level'] );
		$this->assertEquals( 'core/heading', $blocks[1]['blockName'] );
		$this->assertEquals( 3, $blocks[1]['attrs']['level'] );
	}

	/**
	 * Test convert_html_to_blocks strips bold from headings.
	 */
	public function test_convert_html_to_blocks_strips_bold_from_headings() {
		$html = '<h2><strong>Bold Heading</strong></h2>';
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertCount( 1, $blocks );
		$this->assertStringContainsString( 'Bold Heading', $blocks[0]['innerHTML'] );
		$this->assertStringNotContainsString( '<strong>', $blocks[0]['innerHTML'] );
	}

	/**
	 * Test convert_html_to_blocks with unordered list.
	 */
	public function test_convert_html_to_blocks_unordered_list() {
		$html = '<ul><li>Item one</li><li>Item two</li></ul>';
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertCount( 1, $blocks );
		$this->assertEquals( 'core/list', $blocks[0]['blockName'] );
		$this->assertArrayNotHasKey( 'ordered', $blocks[0]['attrs'] );
		$this->assertCount( 2, $blocks[0]['innerBlocks'] );
	}

	/**
	 * Test convert_html_to_blocks with ordered list.
	 */
	public function test_convert_html_to_blocks_ordered_list() {
		$html = '<ol><li>Step one</li><li>Step two</li></ol>';
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertCount( 1, $blocks );
		$this->assertEquals( 'core/list', $blocks[0]['blockName'] );
		$this->assertTrue( $blocks[0]['attrs']['ordered'] );
	}

	/**
	 * Test convert_html_to_blocks preserves content inside block-containing divs.
	 *
	 * Regression: a div holding block-level children was previously dropped
	 * wholesale; it is now recursed into and returned as a group block.
	 */
	public function test_convert_html_to_blocks_preserves_block_containing_divs() {
		$html = '<div><p>Wrapped paragraph.</p><ul><li>Item</li></ul></div>';
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertCount( 1, $blocks );
		$this->assertEquals( 'core/group', $blocks[0]['blockName'] );

		$inner_names = array_column( $blocks[0]['innerBlocks'], 'blockName' );
		$this->assertContains( 'core/paragraph', $inner_names );
		$this->assertContains( 'core/list', $inner_names );

		$serialized = Content_Parser\serialize_blocks( $blocks );
		$this->assertStringContainsString( 'Wrapped paragraph.', $serialized );
	}

	/**
	 * Test convert_html_to_blocks with blockquote.
	 */
	public function test_convert_html_to_blocks_blockquote() {
		$html = '<blockquote><p>Quote text</p><cite>Author</cite></blockquote>';
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertCount( 1, $blocks );
		$this->assertEquals( 'core/quote', $blocks[0]['blockName'] );
		$this->assertNotEmpty( $blocks[0]['innerBlocks'] );
	}

	/**
	 * Test convert_html_to_blocks with image.
	 */
	public function test_convert_html_to_blocks_image() {
		$html = '<img src="https://example.com/image.jpg" alt="Test image" width="800" height="600">';
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertCount( 1, $blocks );
		$this->assertEquals( 'core/image', $blocks[0]['blockName'] );
		$this->assertEquals( 800, $blocks[0]['attrs']['width'] );
		$this->assertEquals( 600, $blocks[0]['attrs']['height'] );
	}

	/**
	 * Test convert_html_to_blocks with figure and caption.
	 */
	public function test_convert_html_to_blocks_figure_with_caption() {
		$html = '<figure><img src="https://example.com/image.jpg" alt="Test"><figcaption>Caption text</figcaption></figure>';
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertCount( 1, $blocks );
		$this->assertEquals( 'core/image', $blocks[0]['blockName'] );
		$this->assertStringContainsString( 'figcaption', $blocks[0]['innerHTML'] );
		$this->assertStringContainsString( 'Caption text', $blocks[0]['innerHTML'] );
	}

	/**
	 * Test convert_html_to_blocks with horizontal rule.
	 */
	public function test_convert_html_to_blocks_hr() {
		$html = '<hr>';
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertCount( 1, $blocks );
		$this->assertEquals( 'core/separator', $blocks[0]['blockName'] );
	}

	/**
	 * Test convert_html_to_blocks with table.
	 */
	public function test_convert_html_to_blocks_table() {
		$html = '<table><tr><td>Cell</td></tr></table>';
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertCount( 1, $blocks );
		$this->assertEquals( 'core/table', $blocks[0]['blockName'] );
		$this->assertStringContainsString( 'figure', $blocks[0]['innerHTML'] );
	}

	/**
	 * Test convert_html_to_blocks sets hasFixedLayout so the block validates.
	 */
	public function test_convert_html_to_blocks_table_has_fixed_layout_attr() {
		$html = '<table><tr><td>Cell</td></tr></table>';
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertSame( [ 'hasFixedLayout' => false ], $blocks[0]['attrs'] );
	}

	/**
	 * Test convert_html_to_blocks strips legacy presentational table attributes.
	 */
	public function test_convert_html_to_blocks_table_strips_presentation_attributes() {
		$html = '<table border="1" cellpadding="2" cellspacing="0" bgcolor="#fff">'
			. '<tr><td valign="top">Cell</td></tr></table>';
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertStringNotContainsString( 'border=', $blocks[0]['innerHTML'] );
		$this->assertStringNotContainsString( 'cellpadding=', $blocks[0]['innerHTML'] );
		$this->assertStringNotContainsString( 'cellspacing=', $blocks[0]['innerHTML'] );
		$this->assertStringNotContainsString( 'bgcolor=', $blocks[0]['innerHTML'] );
		$this->assertStringNotContainsString( 'valign=', $blocks[0]['innerHTML'] );
		$this->assertStringContainsString( '<td>Cell</td>', $blocks[0]['innerHTML'] );
	}

	/**
	 * Test strip_table_presentation_attributes directly.
	 */
	public function test_strip_table_presentation_attributes() {
		$html = '<table border="1" cellpadding="2" cellspacing="0" bgcolor="#ffffff">'
			. '<tr><td valign="middle" align="left">Cell</td></tr></table>';

		$stripped = Content_Parser\strip_table_presentation_attributes( $html );

		$this->assertSame(
			'<table><tr><td align="left">Cell</td></tr></table>',
			$stripped
		);
	}

	/**
	 * Test convert_html_to_blocks with preformatted text.
	 */
	public function test_convert_html_to_blocks_pre() {
		$html = '<pre>function test() { return true; }</pre>';
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertCount( 1, $blocks );
		$this->assertEquals( 'core/code', $blocks[0]['blockName'] );
	}

	/**
	 * Test convert_html_to_blocks with YouTube iframe.
	 */
	public function test_convert_html_to_blocks_youtube_iframe() {
		$html = '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>';
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertCount( 1, $blocks );
		$this->assertEquals( 'core/embed', $blocks[0]['blockName'] );
		$this->assertEquals( 'youtube', $blocks[0]['attrs']['providerNameSlug'] );
	}

	/**
	 * Test convert_html_to_blocks with generic iframe.
	 */
	public function test_convert_html_to_blocks_generic_iframe() {
		$html = '<iframe src="https://example.com/widget"></iframe>';
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertCount( 1, $blocks );
		$this->assertEquals( 'core/html', $blocks[0]['blockName'] );
	}

	/**
	 * Test convert_html_to_blocks preserves inline formatting.
	 */
	public function test_convert_html_to_blocks_preserves_inline_formatting() {
		$html = '<p>Text with <strong>bold</strong> and <em>italic</em> and <a href="https://example.com">link</a>.</p>';
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertCount( 1, $blocks );
		$this->assertStringContainsString( '<strong>bold</strong>', $blocks[0]['innerHTML'] );
		$this->assertStringContainsString( '<em>italic</em>', $blocks[0]['innerHTML'] );
		$this->assertStringContainsString( '<a href="https://example.com">link</a>', $blocks[0]['innerHTML'] );
	}

	/**
	 * Test convert_html_to_blocks cleans font-weight spans.
	 */
	public function test_convert_html_to_blocks_cleans_font_weight_spans() {
		$html = '<p><span style="font-weight: 400">Normal text</span></p>';
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertCount( 1, $blocks );
		$this->assertStringNotContainsString( 'font-weight', $blocks[0]['innerHTML'] );
		$this->assertStringContainsString( 'Normal text', $blocks[0]['innerHTML'] );
	}

	/**
	 * Test convert_html_to_blocks removes empty paragraphs.
	 */
	public function test_convert_html_to_blocks_removes_empty_paragraphs() {
		$html = "<p>First</p>\n\n\n\n<p>Second</p>";
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertCount( 2, $blocks );
	}

	/**
	 * Test convert_html_to_blocks with div containing inline content.
	 */
	public function test_convert_html_to_blocks_div_inline() {
		$html = '<div>Text content in a div.</div>';
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertCount( 1, $blocks );
		$this->assertEquals( 'core/paragraph', $blocks[0]['blockName'] );
		$this->assertStringContainsString( 'Text content in a div.', $blocks[0]['innerHTML'] );
	}

	/**
	 * Test convert_html_to_blocks with empty content.
	 */
	public function test_convert_html_to_blocks_empty() {
		$blocks = Content_Parser\convert_html_to_blocks( '' );

		$this->assertCount( 0, $blocks );
	}

	/**
	 * Test convert_html_to_blocks with malformed HTML.
	 */
	public function test_convert_html_to_blocks_malformed_html() {
		$html = '<p>Unclosed paragraph<p>Another paragraph</p>';
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		// Should still produce blocks, DOMDocument recovers from malformed HTML.
		$this->assertNotEmpty( $blocks );
	}

	// =========================================================================
	// Mixed Content Parsing Tests
	// =========================================================================

	/**
	 * Test parse_content_with_conversion with pure classic HTML.
	 */
	public function test_parse_content_with_conversion_classic_html() {
		$content = '<p>Classic paragraph one.</p><p>Classic paragraph two.</p>';
		$blocks = Content_Parser\parse_content_with_conversion( $content );

		$this->assertCount( 2, $blocks );
		$this->assertEquals( 'core/paragraph', $blocks[0]['blockName'] );
		$this->assertEquals( 'core/paragraph', $blocks[1]['blockName'] );
	}

	/**
	 * Test parse_content_with_conversion with pure block content.
	 */
	public function test_parse_content_with_conversion_block_content() {
		$content = '<!-- wp:paragraph --><p>Block paragraph.</p><!-- /wp:paragraph -->';
		$blocks = Content_Parser\parse_content_with_conversion( $content );

		$this->assertCount( 1, $blocks );
		$this->assertEquals( 'core/paragraph', $blocks[0]['blockName'] );
	}

	/**
	 * Test parse_content_with_conversion with mixed content fixture.
	 */
	public function test_parse_content_with_conversion_mixed_fixture() {
		$content = $this->get_html_fixture( 'mixed-content' );
		$blocks = Content_Parser\parse_content_with_conversion( $content );

		// Should have blocks from both the block editor portion and converted classic portion.
		$this->assertNotEmpty( $blocks );

		// First two blocks should be the original blocks (heading and paragraph).
		$this->assertEquals( 'core/heading', $blocks[0]['blockName'] );
		$this->assertEquals( 'core/paragraph', $blocks[1]['blockName'] );

		// Remaining blocks should be converted from classic content.
		$block_names = array_column( $blocks, 'blockName' );
		$this->assertContains( 'core/heading', $block_names ); // The h3.
	}

	/**
	 * Test parse_content_with_conversion with empty content.
	 */
	public function test_parse_content_with_conversion_empty() {
		$blocks = Content_Parser\parse_content_with_conversion( '' );

		$this->assertCount( 0, $blocks );
	}

	// =========================================================================
	// Transform HTML Blocks Tests
	// =========================================================================

	/**
	 * Test transform_html_blocks converts embeddable HTML.
	 */
	public function test_transform_html_blocks_converts_embeddable() {
		$blocks = [
			[
				'blockName' => 'core/html',
				'innerHTML' => '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>',
				'innerBlocks' => [],
			],
		];

		$result = Content_Parser\transform_html_blocks( $blocks );

		$this->assertEquals( 'core/embed', $result[0]['blockName'] );
	}

	/**
	 * Test transform_html_blocks leaves non-embeddable unchanged.
	 */
	public function test_transform_html_blocks_leaves_non_embeddable() {
		$blocks = [
			[
				'blockName' => 'core/html',
				'innerHTML' => '<div>Custom HTML</div>',
				'innerBlocks' => [],
			],
		];

		$result = Content_Parser\transform_html_blocks( $blocks );

		$this->assertEquals( 'core/html', $result[0]['blockName'] );
	}

	/**
	 * Test transform_html_blocks with logger callback.
	 */
	public function test_transform_html_blocks_calls_logger() {
		$logged_messages = [];
		$logger = function( $message ) use ( &$logged_messages ) {
			$logged_messages[] = $message;
		};

		$blocks = [
			[
				'blockName' => 'core/html',
				'innerHTML' => '<div>Non-convertible</div>',
				'innerBlocks' => [],
			],
		];

		Content_Parser\transform_html_blocks( $blocks, 'test-post', $logger );

		$this->assertCount( 1, $logged_messages );
		$this->assertStringContainsString( 'test-post', $logged_messages[0] );
	}

	/**
	 * Test transform_html_blocks processes inner blocks recursively.
	 */
	public function test_transform_html_blocks_recursive() {
		$blocks = [
			[
				'blockName' => 'core/group',
				'innerHTML' => '',
				'innerBlocks' => [
					[
						'blockName' => 'core/html',
						'innerHTML' => '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>',
						'innerBlocks' => [],
					],
				],
			],
		];

		$result = Content_Parser\transform_html_blocks( $blocks );

		$this->assertEquals( 'core/embed', $result[0]['innerBlocks'][0]['blockName'] );
	}

	// =========================================================================
	// Serialize Blocks Tests
	// =========================================================================

	/**
	 * Test serialize_blocks handles ampersands correctly.
	 */
	public function test_serialize_blocks_handles_ampersands() {
		$blocks = [
			[
				'blockName' => 'core/paragraph',
				'attrs' => [ 'content' => 'Tom & Jerry' ],
				'innerBlocks' => [],
				'innerHTML' => '<p>Tom &amp; Jerry</p>',
				'innerContent' => [ '<p>Tom &amp; Jerry</p>' ],
			],
		];

		$result = Content_Parser\serialize_blocks( $blocks );

		// Should not have unicode-escaped ampersands.
		$this->assertStringNotContainsString( '\\u0026', $result );
	}

	// =========================================================================
	// Fixture-Based Integration Tests
	// =========================================================================

	/**
	 * Test TinyMCE output fixture conversion.
	 */
	public function test_tinymce_fixture_conversion() {
		$html = $this->get_html_fixture( 'tinymce-output' );
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertNotEmpty( $blocks );

		// Should have multiple paragraphs.
		$paragraph_count = count( array_filter( $blocks, fn( $b ) => $b['blockName'] === 'core/paragraph' ) );
		$this->assertGreaterThan( 1, $paragraph_count );

		// Should have a heading.
		$heading_count = count( array_filter( $blocks, fn( $b ) => $b['blockName'] === 'core/heading' ) );
		$this->assertEquals( 1, $heading_count );

		// Font-weight spans should be cleaned.
		$serialized = serialize_blocks( $blocks );
		$this->assertStringNotContainsString( 'font-weight: 400', $serialized );
	}

	/**
	 * Test table fixture conversion.
	 */
	public function test_table_fixture_conversion() {
		$html = $this->get_html_fixture( 'tables' );
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertNotEmpty( $blocks );

		// Should have a table block.
		$table_blocks = array_filter( $blocks, fn( $b ) => $b['blockName'] === 'core/table' );
		$this->assertCount( 1, $table_blocks );
	}

	/**
	 * Test edge cases fixture conversion.
	 */
	public function test_edge_cases_fixture_conversion() {
		$html = $this->get_html_fixture( 'edge-cases' );
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertNotEmpty( $blocks );

		// Should have a code block from the pre element.
		$code_blocks = array_filter( $blocks, fn( $b ) => $b['blockName'] === 'core/code' );
		$this->assertCount( 1, $code_blocks );

		// Empty paragraphs should be filtered out.
		$serialized = serialize_blocks( $blocks );
		$this->assertStringNotContainsString( '<p></p>', $serialized );
	}

	/**
	 * Test embed fixture conversion.
	 */
	public function test_embed_fixture_conversion() {
		$html = $this->get_html_fixture( 'embeds' );
		$blocks = Content_Parser\convert_html_to_blocks( $html );

		$this->assertNotEmpty( $blocks );

		// Should have embed blocks for YouTube and Vimeo iframes.
		$embed_blocks = array_filter( $blocks, fn( $b ) => $b['blockName'] === 'core/embed' );
		$this->assertGreaterThanOrEqual( 2, count( $embed_blocks ) );

		// Should have an HTML block for the generic iframe.
		$html_blocks = array_filter( $blocks, fn( $b ) => $b['blockName'] === 'core/html' );
		$this->assertGreaterThanOrEqual( 1, count( $html_blocks ) );
	}
}
