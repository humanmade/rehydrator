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
	 * update_block_text_content must not wrap text inside a void element, which
	 * would emit invalid markup like <hr>text</hr>.
	 */
	public function test_update_block_text_content_leaves_void_elements_untouched() {
		$separator = [
			'blockName' => 'core/separator',
			'attrs' => [],
			'innerBlocks' => [],
			'innerHTML' => '<hr class="wp-block-separator has-alpha-channel-opacity"/>',
			'innerContent' => [ '<hr class="wp-block-separator has-alpha-channel-opacity"/>' ],
		];

		$updated = Pattern_Transformer\update_block_text_content( $separator, 'nope' );

		$this->assertStringNotContainsString( 'nope', $updated['innerHTML'] );
		$this->assertStringNotContainsString( '</hr>', $updated['innerHTML'] );
		$this->assertSame( $separator['innerHTML'], $updated['innerHTML'] );
	}

	/**
	 * update_block_text_content preserves standard attributes on the wrapper
	 * tag when replacing text.
	 */
	public function test_update_block_text_content_preserves_attributes() {
		$heading = [
			'blockName' => 'core/heading',
			'attrs' => [],
			'innerBlocks' => [],
			'innerHTML' => '<h2 class="wp-block-heading" id="intro">Old</h2>',
			'innerContent' => [ '<h2 class="wp-block-heading" id="intro">Old</h2>' ],
		];

		$updated = Pattern_Transformer\update_block_text_content( $heading, 'New' );

		$this->assertStringContainsString( 'New', $updated['innerHTML'] );
		$this->assertStringContainsString( 'class="wp-block-heading"', $updated['innerHTML'] );
		$this->assertStringContainsString( 'id="intro"', $updated['innerHTML'] );
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

	/**
	 * Test add_block_class updates both the attribute and the markup.
	 */
	public function test_add_block_class_updates_attrs_and_markup() {
		$block = parse_blocks( '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Title</h2><!-- /wp:heading -->' )[0];

		$result = Pattern_Transformer\add_block_class( $block, 'is-style-display' );

		$this->assertSame( 'is-style-display', $result['attrs']['className'] );
		$this->assertStringContainsString( 'class="wp-block-heading is-style-display"', $result['innerHTML'] );
		$this->assertSame( [ $result['innerHTML'] ], $result['innerContent'] );
	}

	/**
	 * Test add_block_class adds a class attribute when the tag has none.
	 *
	 * This is the case the old markup-prefix matching could not handle.
	 */
	public function test_add_block_class_on_tag_without_class_attribute() {
		$block = parse_blocks( '<!-- wp:heading {"level":3} --><h3>Title</h3><!-- /wp:heading -->' )[0];

		$result = Pattern_Transformer\add_block_class( $block, 'is-style-display' );

		$this->assertSame( 'is-style-display', $result['attrs']['className'] );
		$this->assertStringContainsString( '<h3 class="is-style-display">', $result['innerHTML'] );
	}

	/**
	 * Test adding nothing does not introduce an empty class attribute.
	 *
	 * Guards the case where the class comes from source data that turned out
	 * to be empty: the block should be left exactly as it was.
	 */
	public function test_add_block_class_with_empty_input_changes_nothing() {
		$block = parse_blocks( '<!-- wp:heading {"level":3} --><h3>Title</h3><!-- /wp:heading -->' )[0];

		foreach ( [ '', '   ', [] ] as $empty ) {
			$result = Pattern_Transformer\add_block_class( $block, $empty );

			$this->assertArrayNotHasKey( 'className', $result['attrs'] );
			$this->assertSame( '<h3>Title</h3>', $result['innerHTML'] );
			$this->assertSame( [ '<h3>Title</h3>' ], $result['innerContent'] );
		}
	}

	/**
	 * Test add_block_class appends to classes the block already has.
	 */
	public function test_add_block_class_preserves_existing_classes() {
		$block = parse_blocks( '<!-- wp:paragraph {"className":"lede"} --><p class="lede">Text.</p><!-- /wp:paragraph -->' )[0];

		$result = Pattern_Transformer\add_block_class( $block, 'is-style-large' );

		$this->assertSame( 'lede is-style-large', $result['attrs']['className'] );
		$this->assertStringContainsString( 'class="lede is-style-large"', $result['innerHTML'] );
	}

	/**
	 * Test add_block_class accepts several classes, as an array or a string.
	 */
	public function test_add_block_class_accepts_multiple_classes() {
		$block = parse_blocks( '<!-- wp:paragraph --><p>Text.</p><!-- /wp:paragraph -->' )[0];

		$from_array = Pattern_Transformer\add_block_class( $block, [ 'one', 'two' ] );
		$from_string = Pattern_Transformer\add_block_class( $block, 'one two' );

		$this->assertSame( 'one two', $from_array['attrs']['className'] );
		$this->assertSame( $from_array, $from_string );
	}

	/**
	 * Test add_block_class does not duplicate a class the block already has.
	 */
	public function test_add_block_class_does_not_duplicate() {
		$block = parse_blocks( '<!-- wp:paragraph {"className":"lede"} --><p class="lede">Text.</p><!-- /wp:paragraph -->' )[0];

		$result = Pattern_Transformer\add_block_class( $block, 'lede' );

		$this->assertSame( 'lede', $result['attrs']['className'] );
		$this->assertStringContainsString( 'class="lede"', $result['innerHTML'] );
	}

	/**
	 * Test add_block_class only touches the wrapper tag of a container block.
	 */
	public function test_add_block_class_updates_wrapper_of_container_block() {
		$blocks = parse_blocks( $this->load_pattern( 'hero-section' ) );
		$group = $this->find_block( $blocks, 'core/group' );

		$result = Pattern_Transformer\add_block_class( $group, 'is-featured' );

		$this->assertSame( 'hero-section is-featured', $result['attrs']['className'] );
		$this->assertStringContainsString( 'class="wp-block-group hero-section is-featured"', $result['innerContent'][0] );
		$this->assertNull( $result['innerContent'][1] );
		$this->assertSame( '</div>', trim( end( $result['innerContent'] ) ) );
		$this->assertCount( 3, $result['innerBlocks'] );
	}

	/**
	 * Test add_block_class handles a block with no markup of its own.
	 */
	public function test_add_block_class_on_block_without_markup() {
		$block = parse_blocks( '<!-- wp:latest-posts /-->' )[0];

		$result = Pattern_Transformer\add_block_class( $block, 'is-style-grid' );

		$this->assertSame( 'is-style-grid', $result['attrs']['className'] );
		$this->assertSame( '', trim( $result['innerHTML'] ) );
	}

	/**
	 * Test remove_block_class drops the class from the attribute and the markup.
	 */
	public function test_remove_block_class_updates_attrs_and_markup() {
		$block = parse_blocks( '<!-- wp:paragraph {"className":"lede is-style-large"} --><p class="lede is-style-large">Text.</p><!-- /wp:paragraph -->' )[0];

		$result = Pattern_Transformer\remove_block_class( $block, 'is-style-large' );

		$this->assertSame( 'lede', $result['attrs']['className'] );
		$this->assertStringNotContainsString( 'is-style-large', $result['innerHTML'] );
		$this->assertStringContainsString( 'lede', $result['innerHTML'] );
	}

	/**
	 * Test remove_block_class drops className entirely when nothing is left.
	 */
	public function test_remove_block_class_unsets_empty_class_name() {
		$block = parse_blocks( '<!-- wp:paragraph {"className":"lede"} --><p class="lede">Text.</p><!-- /wp:paragraph -->' )[0];

		$result = Pattern_Transformer\remove_block_class( $block, 'lede' );

		$this->assertArrayNotHasKey( 'className', $result['attrs'] );

		// The space left where the attribute was is what WP_HTML_Tag_Processor
		// itself emits when the last class goes. Checked against the editor's
		// isValidBlockContent(): insignificant whitespace inside a tag never
		// reaches the token comparison, so this still validates.
		$this->assertSame( '<p >Text.</p>', $result['innerHTML'] );
	}

	/**
	 * Test remove_block_class leaves generated classes alone.
	 */
	public function test_remove_block_class_leaves_other_classes() {
		$block = parse_blocks( '<!-- wp:heading {"level":2,"className":"is-style-display"} --><h2 class="wp-block-heading is-style-display">Title</h2><!-- /wp:heading -->' )[0];

		$result = Pattern_Transformer\remove_block_class( $block, 'is-style-display' );

		$this->assertArrayNotHasKey( 'className', $result['attrs'] );
		$this->assertStringContainsString( 'class="wp-block-heading"', $result['innerHTML'] );
	}

	/**
	 * Test replace_block_class swaps one class for another.
	 */
	public function test_replace_block_class_swaps_classes() {
		$block = parse_blocks( '<!-- wp:heading {"level":2,"className":"is-style-default"} --><h2 class="wp-block-heading is-style-default">Title</h2><!-- /wp:heading -->' )[0];

		$result = Pattern_Transformer\replace_block_class( $block, 'is-style-default', 'is-style-display' );

		$this->assertSame( 'is-style-display', $result['attrs']['className'] );
		$this->assertStringContainsString( 'class="wp-block-heading is-style-display"', $result['innerHTML'] );
	}

	/**
	 * Test replace_block_class swaps a tag's only class without leaving debris.
	 *
	 * Removing the last class drops the class attribute, so a swap done as two
	 * passes leaves stray whitespace in the tag.
	 */
	public function test_replace_block_class_swaps_only_class_cleanly() {
		$block = parse_blocks( '<!-- wp:paragraph {"className":"lede"} --><p class="lede">Text.</p><!-- /wp:paragraph -->' )[0];

		$result = Pattern_Transformer\replace_block_class( $block, 'lede', 'is-style-large' );

		$this->assertSame( 'is-style-large', $result['attrs']['className'] );
		$this->assertStringContainsString( '<p class="is-style-large">Text.</p>', $result['innerHTML'] );
	}

	/**
	 * Test replace_block_class adds the new class even when the old one is absent.
	 */
	public function test_replace_block_class_adds_when_old_class_absent() {
		$block = parse_blocks( '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Title</h2><!-- /wp:heading -->' )[0];

		$result = Pattern_Transformer\replace_block_class( $block, 'is-style-default', 'is-style-display' );

		$this->assertSame( 'is-style-display', $result['attrs']['className'] );
		$this->assertStringContainsString( 'class="wp-block-heading is-style-display"', $result['innerHTML'] );
	}

	/**
	 * Test the addClass and removeClass transformation keys.
	 */
	public function test_apply_pattern_transformations_adds_and_removes_classes() {
		$blocks = parse_blocks( $this->load_pattern( 'simple-heading-paragraph' ) );
		$tagged = Pattern_Transformer\tag_blocks_recursively( $blocks, 'test/hero' );

		// Note the generated wp-block-heading class is deliberately left alone:
		// core/heading's save() always emits it, so content without it fails
		// block validation in the editor.
		$transformations = [
			'test/hero' => [
				'core/heading' => [
					0 => [
						'classOps' => [
							[
								'action' => 'add',
								'classes' => 'is-style-display legacy-heading',
							],
							[
								'action' => 'remove',
								'classes' => 'legacy-heading',
							],
						],
					],
				],
			],
		];

		$result = Pattern_Transformer\apply_pattern_transformations( $tagged, $transformations );
		$heading = $this->find_block( $result, 'core/heading' );

		$this->assertSame( 'is-style-display', $heading['attrs']['className'] );
		$this->assertStringContainsString( 'class="wp-block-heading is-style-display"', $heading['innerHTML'] );
		$this->assertStringNotContainsString( 'legacy-heading', $heading['innerHTML'] );
	}

	/**
	 * Test class operations are applied in the order they were given.
	 */
	public function test_apply_class_ops_honours_order() {
		$existing = [ 'wp-block-heading' ];

		$add_then_remove = Pattern_Transformer\apply_class_ops( $existing, [
			[
				'action' => 'add',
				'classes' => 'flagged',
			],
			[
				'action' => 'remove',
				'classes' => 'flagged',
			],
		] );

		$remove_then_add = Pattern_Transformer\apply_class_ops( $existing, [
			[
				'action' => 'remove',
				'classes' => 'flagged',
			],
			[
				'action' => 'add',
				'classes' => 'flagged',
			],
		] );

		$this->assertSame( [ 'wp-block-heading' ], $add_then_remove );
		$this->assertSame( [ 'wp-block-heading', 'flagged' ], $remove_then_add );
	}

	/**
	 * Test class changes apply after a textContent replacement on the same block.
	 */
	public function test_apply_pattern_transformations_class_survives_text_replacement() {
		$blocks = parse_blocks( $this->load_pattern( 'simple-heading-paragraph' ) );
		$tagged = Pattern_Transformer\tag_blocks_recursively( $blocks, 'test/hero' );

		$transformations = [
			'test/hero' => [
				'core/heading' => [
					0 => [
						'textContent' => 'New Title',
						'classOps' => [
							[
								'action' => 'add',
								'classes' => 'is-style-display',
							],
						],
					],
				],
			],
		];

		$result = Pattern_Transformer\apply_pattern_transformations( $tagged, $transformations );
		$heading = $this->find_block( $result, 'core/heading' );

		$this->assertStringContainsString( 'New Title', $heading['innerHTML'] );
		$this->assertStringContainsString( 'class="wp-block-heading is-style-display"', $heading['innerHTML'] );
	}
}
