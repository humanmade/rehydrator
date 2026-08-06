<?php
/**
 * Pattern Transformer Integration Tests
 *
 * @package HM\Rehydrator\Tests
 */

namespace HM\Rehydrator\Tests;

use WP_UnitTestCase;
use HM\Rehydrator\Pattern_Transformer;

/**
 * Test pattern transformer functions with real WordPress.
 */
class PatternTransformerTest extends WP_UnitTestCase {

	/**
	 * Path to fixture files.
	 *
	 * @var string
	 */
	protected static string $fixtures_path;

	/**
	 * Set up fixtures path.
	 */
	public static function set_up_before_class() : void {
		parent::set_up_before_class();
		self::$fixtures_path = __DIR__ . '/fixtures/patterns/';
	}

	/**
	 * Load a pattern fixture file.
	 *
	 * @param string $name Pattern filename without extension.
	 * @return string Pattern content.
	 */
	protected function load_pattern( string $name ) : string {
		$file = self::$fixtures_path . $name . '.html';
		return file_get_contents( $file );
	}

	/**
	 * Test that blocks can be parsed and serialized round-trip.
	 */
	public function test_parse_and_serialize_blocks() {
		$content = $this->load_pattern( 'simple-heading-paragraph' );
		$blocks = parse_blocks( $content );
		$serialized = serialize_blocks( $blocks );

		// Should have heading and paragraph blocks (plus empty spacer blocks).
		$non_empty_blocks = array_filter( $blocks, fn( $b ) => ! empty( $b['blockName'] ) );
		$this->assertCount( 2, $non_empty_blocks );

		// Serialized output should contain the original content.
		$this->assertStringContainsString( 'Test Title', $serialized );
		$this->assertStringContainsString( 'Test paragraph content', $serialized );
	}

	/**
	 * Test update_block_text_content updates text safely.
	 */
	public function test_update_block_text_content() {
		$content = $this->load_pattern( 'simple-heading-paragraph' );
		$blocks = parse_blocks( $content );

		// Find the heading block.
		$heading = null;
		foreach ( $blocks as $block ) {
			if ( $block['blockName'] === 'core/heading' ) {
				$heading = $block;
				break;
			}
		}

		$this->assertNotNull( $heading );

		$updated = Pattern_Transformer\update_block_text_content( $heading, 'New Title' );

		$this->assertStringContainsString( 'New Title', $updated['innerHTML'] );
		$this->assertStringNotContainsString( 'Test Title', $updated['innerHTML'] );
		$this->assertStringContainsString( '<h2', $updated['innerHTML'] );
	}

	/**
	 * Test update_block_text_content preserves nested markup in wrapper blocks.
	 *
	 * core/button renders a wrapping <div> with an inner <a>; only the link
	 * text should change — the outer div and inner anchor must be preserved.
	 */
	public function test_update_block_text_content_preserves_nested_markup() {
		$button_html = '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Click Here</a></div>';

		$block = [
			'blockName'    => 'core/button',
			'attrs'        => [],
			'innerHTML'    => $button_html,
			'innerContent' => [ $button_html ],
			'innerBlocks'  => [],
		];

		$updated = Pattern_Transformer\update_block_text_content( $block, 'Buy Now' );

		// Outer wrapper div must still be present.
		$this->assertStringContainsString( '<div class="wp-block-button">', $updated['innerHTML'] );
		// Inner anchor tag must be preserved with its attributes.
		$this->assertStringContainsString( '<a class="wp-block-button__link wp-element-button" href="#">', $updated['innerHTML'] );
		// New text should be set.
		$this->assertStringContainsString( 'Buy Now', $updated['innerHTML'] );
		// Old text should be gone.
		$this->assertStringNotContainsString( 'Click Here', $updated['innerHTML'] );
	}

	/**
	 * Test update_block_text_content HTML-encodes special characters.
	 *
	 * replace_text() delegates to this function, so the text value should be
	 * HTML-encoded (e.g. & → &amp;) rather than inserted verbatim.
	 */
	public function test_update_block_text_content_escapes_special_chars() {
		$content = $this->load_pattern( 'simple-heading-paragraph' );
		$blocks = parse_blocks( $content );

		$heading = null;
		foreach ( $blocks as $block ) {
			if ( $block['blockName'] === 'core/heading' ) {
				$heading = $block;
				break;
			}
		}

		$this->assertNotNull( $heading );

		$updated = Pattern_Transformer\update_block_text_content( $heading, 'Tom & Jerry' );

		// The ampersand must be HTML-encoded in the stored markup.
		$this->assertStringContainsString( 'Tom &amp; Jerry', $updated['innerHTML'] );
		// Must not be double-encoded (set_modifiable_text should encode exactly once).
		$this->assertStringNotContainsString( 'Tom &amp;amp; Jerry', $updated['innerHTML'] );
	}

	/**
	 * Test rebuild_inner_content preserves structure.
	 */
	public function test_rebuild_inner_content() {
		$content = $this->load_pattern( 'hero-section' );
		$blocks = parse_blocks( $content );

		// Find the group block.
		$group = null;
		foreach ( $blocks as $block ) {
			if ( $block['blockName'] === 'core/group' ) {
				$group = $block;
				break;
			}
		}

		$this->assertNotNull( $group );
		$this->assertNotEmpty( $group['innerBlocks'] );

		$rebuilt = Pattern_Transformer\rebuild_inner_content( $group );

		// Should maintain the wrapper structure with null placeholders for inner blocks.
		$this->assertStringContainsString( 'wp-block-group', $rebuilt['innerContent'][0] );
		$this->assertContains( null, $rebuilt['innerContent'] );
	}

	/**
	 * Test rebuild_inner_content keeps every level of a nested wrapper.
	 *
	 * Uses a carousel viewport block to verify that no level of nested wrapper
	 * gets dropped when inner content is rebuilt.
	 */
	public function test_rebuild_inner_content_preserves_nested_wrapper() {
		$blocks = parse_blocks( $this->load_pattern( 'nested-wrapper-carousel' ) );
		$viewport = $this->find_block( $blocks, 'rt-carousel/carousel-viewport' );

		$this->assertNotNull( $viewport );
		$this->assertCount( 2, $viewport['innerBlocks'] );

		// Drop a slide, as a transformation removing an inner block would.
		$viewport['innerBlocks'] = [ $viewport['innerBlocks'][0] ];

		$rebuilt = Pattern_Transformer\rebuild_inner_content( $viewport );

		$this->assertCount( 3, $rebuilt['innerContent'] );
		$this->assertStringContainsString( 'embla__container', $rebuilt['innerContent'][0] );
		$this->assertNull( $rebuilt['innerContent'][1] );
		$this->assertStringContainsString( '</div></div>', $rebuilt['innerContent'][2] );
	}

	/**
	 * Test rebuild_inner_content keeps markup that follows the last inner block.
	 *
	 * Uses a carousel block to verify that elements after wrapped content don't
	 * get discarded on reconstruction.
	 */
	public function test_rebuild_inner_content_preserves_trailing_markup() {
		$blocks = parse_blocks( $this->load_pattern( 'nested-wrapper-carousel' ) );
		$carousel = $this->find_block( $blocks, 'rt-carousel/carousel' );

		$this->assertNotNull( $carousel );

		$rebuilt = Pattern_Transformer\rebuild_inner_content( $carousel );

		$this->assertStringContainsString( 'screen-reader-text', end( $rebuilt['innerContent'] ) );
	}

	/**
	 * Find the first block of a given type, at any depth.
	 *
	 * @param array  $blocks Parsed blocks to search.
	 * @param string $name   Block type name.
	 * @return array|null Matched block, or null when absent.
	 */
	protected function find_block( array $blocks, string $name ) : ?array {
		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] ?? '' ) === $name ) {
				return $block;
			}

			$found = $this->find_block( $block['innerBlocks'] ?? [], $name );
			if ( $found !== null ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Test resolving pattern references tags blocks with source.
	 */
	public function test_resolve_and_tag_patterns_tags_blocks() {
		$pattern_content = $this->load_pattern( 'simple-paragraph' );

		// Register a test pattern.
		register_block_pattern(
			'test/simple-pattern',
			[
				'title' => 'Simple Pattern',
				'content' => $pattern_content,
			]
		);

		$blocks = parse_blocks( '<!-- wp:pattern {"slug":"test/simple-pattern"} /-->' );
		$resolved = Pattern_Transformer\resolve_and_tag_patterns( $blocks );

		// Should have resolved the pattern reference.
		$this->assertNotEmpty( $resolved );

		// Find the paragraph block.
		$paragraph = null;
		foreach ( $resolved as $block ) {
			if ( $block['blockName'] === 'core/paragraph' ) {
				$paragraph = $block;
				break;
			}
		}

		$this->assertNotNull( $paragraph, 'Paragraph block should exist' );
		$this->assertEquals( 'test/simple-pattern', $paragraph['_source_pattern'] ?? null );

		// Clean up.
		unregister_block_pattern( 'test/simple-pattern' );
	}

	/**
	 * Test apply_pattern_transformations replaces text.
	 */
	public function test_apply_pattern_transformations() {
		$content = $this->load_pattern( 'simple-heading-paragraph' );
		$blocks = parse_blocks( $content );

		// Tag blocks as coming from a pattern.
		$tagged_blocks = Pattern_Transformer\tag_blocks_recursively( $blocks, 'test/hero' );

		$transformations = [
			'test/hero' => [
				'core/heading' => [
					0 => [ 'textContent' => 'Transformed Title' ],
				],
			],
		];

		$result = Pattern_Transformer\apply_pattern_transformations( $tagged_blocks, $transformations );

		// Find the heading block in the result.
		$heading = null;
		foreach ( $result as $block ) {
			if ( $block['blockName'] === 'core/heading' ) {
				$heading = $block;
				break;
			}
		}

		$this->assertNotNull( $heading );
		$this->assertStringContainsString( 'Transformed Title', $heading['innerHTML'] );
	}
}
