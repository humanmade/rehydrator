<?php
/**
 * Template Class Tests
 *
 * @package HM\Rehydrator\Tests
 */

namespace HM\Rehydrator\Tests;

use WP_UnitTestCase;
use HM\Rehydrator\Template;
use HM\Rehydrator\Blocks;

/**
 * Test Template class fluent API.
 */
class TemplateTest extends WP_UnitTestCase {

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
	 * Register test patterns before each test.
	 */
	public function set_up() : void {
		parent::set_up();

		register_block_pattern(
			'test/hero',
			[
				'title' => 'Hero',
				'content' => $this->load_pattern( 'hero' ),
			]
		);

		register_block_pattern(
			'test/footer-cta',
			[
				'title' => 'Footer CTA',
				'content' => $this->load_pattern( 'footer-cta' ),
			]
		);

		register_block_pattern(
			'test/template-article',
			[
				'title' => 'Article Template',
				'content' => $this->load_pattern( 'template-article' ),
			]
		);

		register_block_pattern(
			'test/simple-heading',
			[
				'title' => 'Simple Heading',
				'content' => $this->load_pattern( 'simple-heading-paragraph' ),
			]
		);
	}

	/**
	 * Unregister test patterns after each test.
	 */
	public function tear_down() : void {
		unregister_block_pattern( 'test/hero' );
		unregister_block_pattern( 'test/footer-cta' );
		unregister_block_pattern( 'test/template-article' );
		unregister_block_pattern( 'test/simple-heading' );

		parent::tear_down();
	}

	/**
	 * Test constructor with valid pattern.
	 */
	public function test_constructor_with_valid_pattern() {
		$template = new Template( 'test/simple-heading' );

		$this->assertFalse( $template->has_error() );
		$content = $template->get_content();
		$this->assertIsString( $content );
		$this->assertStringContainsString( 'Test Title', $content );
	}

	/**
	 * Test constructor with invalid pattern returns error.
	 */
	public function test_constructor_with_invalid_pattern_returns_error() {
		$template = new Template( 'nonexistent/pattern' );

		$this->assertTrue( $template->has_error() );
		$this->assertInstanceOf( \WP_Error::class, $template->get_error() );
		$this->assertEquals( 'pattern_not_found', $template->get_error()->get_error_code() );
	}

	/**
	 * Test replace_text updates block content.
	 */
	public function test_replace_text_updates_block_content() {
		$template = new Template( 'test/simple-heading' );

		$content = $template
			->replace_text( 'test/simple-heading', 'core/heading', 0, 'New Title' )
			->get_content();

		$this->assertStringContainsString( 'New Title', $content );
		$this->assertStringNotContainsString( 'Test Title', $content );
	}

	/**
	 * Test replace_text with multiple occurrences.
	 */
	public function test_replace_text_targets_specific_occurrence() {
		$template = new Template( 'test/hero' );

		$content = $template
			->replace_text( 'test/hero', 'core/paragraph', 0, 'First paragraph replaced' )
			->replace_text( 'test/hero', 'core/paragraph', 1, 'Second paragraph replaced' )
			->get_content();

		$this->assertStringContainsString( 'First paragraph replaced', $content );
		$this->assertStringContainsString( 'Second paragraph replaced', $content );
		$this->assertStringNotContainsString( 'Hero description text', $content );
		$this->assertStringNotContainsString( 'Optional subtitle', $content );
	}

	/**
	 * Test replace_attributes merges attributes.
	 */
	public function test_replace_attributes_merges_attributes() {
		$template = new Template( 'test/simple-heading' );

		$content = $template
			->replace_attributes( 'test/simple-heading', 'core/heading', 0, [
				'className' => 'custom-class',
				'anchor' => 'my-heading',
			] )
			->get_content();

		$this->assertStringContainsString( 'custom-class', $content );
		$this->assertStringContainsString( 'my-heading', $content );
	}

	/**
	 * Test transform_callback applies custom logic.
	 */
	public function test_transform_callback_applies_custom_logic() {
		$template = new Template( 'test/simple-heading' );

		$content = $template
			->transform_callback( 'test/simple-heading', 'core/heading', function( $block ) {
				$block['innerHTML'] = '<h2 class="wp-block-heading">Callback Modified</h2>';
				$block['innerContent'] = [ $block['innerHTML'] ];
				return $block;
			} )
			->get_content();

		$this->assertStringContainsString( 'Callback Modified', $content );
	}

	/**
	 * Test remove_block removes specified block.
	 */
	public function test_remove_block_removes_specified_block() {
		$template = new Template( 'test/hero' );

		$content = $template
			->remove_block( 'test/hero', 'core/paragraph', 1 )
			->get_content();

		$this->assertStringContainsString( 'Hero description text', $content );
		$this->assertStringNotContainsString( 'Optional subtitle', $content );
	}

	/**
	 * Test remove_if_empty removes block when value is empty.
	 */
	public function test_remove_if_empty_removes_block_when_empty() {
		$template = new Template( 'test/hero' );
		$empty_value = '';

		$content = $template
			->remove_if_empty( 'test/hero', 'core/paragraph', 1, $empty_value )
			->get_content();

		$this->assertStringNotContainsString( 'Optional subtitle', $content );
	}

	/**
	 * Test remove_if_empty keeps block when value is not empty.
	 */
	public function test_remove_if_empty_keeps_block_when_not_empty() {
		$template = new Template( 'test/hero' );
		$non_empty_value = 'Some value';

		$content = $template
			->remove_if_empty( 'test/hero', 'core/paragraph', 1, $non_empty_value )
			->get_content();

		$this->assertStringContainsString( 'Optional subtitle', $content );
	}

	/**
	 * Test remove_if removes block when condition is true.
	 */
	public function test_remove_if_removes_block_when_condition_true() {
		$template = new Template( 'test/hero' );
		$should_remove = true;

		$content = $template
			->remove_if( 'test/hero', 'core/paragraph', 1, fn() => $should_remove )
			->get_content();

		$this->assertStringNotContainsString( 'Optional subtitle', $content );
	}

	/**
	 * Test remove_if keeps block when condition is false.
	 */
	public function test_remove_if_keeps_block_when_condition_false() {
		$template = new Template( 'test/hero' );
		$should_remove = false;

		$content = $template
			->remove_if( 'test/hero', 'core/paragraph', 1, fn() => $should_remove )
			->get_content();

		$this->assertStringContainsString( 'Optional subtitle', $content );
	}

	/**
	 * Test replace_placeholder inserts content blocks.
	 */
	public function test_replace_placeholder_inserts_content() {
		$template = new Template( 'test/template-article' );

		$content_blocks = [
			Blocks\create_paragraph( 'First content paragraph.' ),
			Blocks\create_paragraph( 'Second content paragraph.' ),
		];

		$content = $template
			->replace_placeholder( 'content-placeholder', $content_blocks )
			->get_content();

		$this->assertStringContainsString( 'First content paragraph', $content );
		$this->assertStringContainsString( 'Second content paragraph', $content );
	}

	/**
	 * Test get_blocks returns array.
	 */
	public function test_get_blocks_returns_array() {
		$template = new Template( 'test/simple-heading' );

		$blocks = $template->get_blocks();

		$this->assertIsArray( $blocks );
		$this->assertNotEmpty( $blocks );
	}

	/**
	 * Test get_blocks returns empty array on error.
	 */
	public function test_get_blocks_returns_empty_on_error() {
		$template = new Template( 'nonexistent/pattern' );

		$blocks = $template->get_blocks();

		$this->assertIsArray( $blocks );
		$this->assertEmpty( $blocks );
	}

	/**
	 * Test fluent interface chaining.
	 */
	public function test_fluent_interface_chaining() {
		$template = new Template( 'test/hero' );

		$content = $template
			->replace_text( 'test/hero', 'core/heading', 0, 'Custom Title' )
			->replace_text( 'test/hero', 'core/paragraph', 0, 'Custom description' )
			->remove_block( 'test/hero', 'core/paragraph', 1 )
			->get_content();

		$this->assertStringContainsString( 'Custom Title', $content );
		$this->assertStringContainsString( 'Custom description', $content );
		$this->assertStringNotContainsString( 'Optional subtitle', $content );
		$this->assertStringNotContainsString( 'Hero Title', $content );
	}

	/**
	 * Test template resolves nested pattern references.
	 */
	public function test_resolves_nested_pattern_references() {
		$template = new Template( 'test/template-article' );

		$content = $template->get_content();

		// Should contain content from hero pattern.
		$this->assertStringContainsString( 'Hero Title', $content );
		// Should contain content from footer-cta pattern.
		$this->assertStringContainsString( 'Ready to get started', $content );
	}

	/**
	 * Test transformations apply to nested patterns.
	 */
	public function test_transformations_apply_to_nested_patterns() {
		$template = new Template( 'test/template-article' );

		$content = $template
			->replace_text( 'test/hero', 'core/heading', 0, 'Welcome to Our Platform' )
			->replace_text( 'test/footer-cta', 'core/heading', 0, 'Get In Touch Today' )
			->get_content();

		$this->assertStringContainsString( 'Welcome to Our Platform', $content );
		$this->assertStringContainsString( 'Get In Touch Today', $content );
		$this->assertStringNotContainsString( 'Hero Title', $content );
		$this->assertStringNotContainsString( 'Ready to get started', $content );
	}

	/**
	 * Test replace_with_synced_pattern creates synced block reference.
	 */
	public function test_replace_with_synced_pattern_creates_reference() {
		$template = new Template( 'test/template-article' );

		$content = $template
			->replace_with_synced_pattern(
				'test/footer-cta',
				'footer-cta-template-test',
				'Footer CTA (Template Test)'
			)
			->get_content();

		// Should contain a wp:block reference instead of resolved pattern content.
		$this->assertStringContainsString( 'wp:block', $content );
		$this->assertStringContainsString( '"ref":', $content );

		// The footer-cta content should NOT be inline (it's now a reference).
		$this->assertStringNotContainsString( 'Ready to get started', $content );

		// Hero content should still be resolved inline.
		$this->assertStringContainsString( 'Hero Title', $content );
	}

	/**
	 * Test replace_with_synced_pattern can be combined with other transformations.
	 */
	public function test_replace_with_synced_pattern_with_transformations() {
		$template = new Template( 'test/template-article' );

		$content = $template
			->replace_text( 'test/hero', 'core/heading', 0, 'Welcome Home' )
			->replace_with_synced_pattern(
				'test/footer-cta',
				'footer-cta-combined-test',
				'Footer CTA (Combined Test)'
			)
			->get_content();

		// Hero transformation should apply.
		$this->assertStringContainsString( 'Welcome Home', $content );
		$this->assertStringNotContainsString( 'Hero Title', $content );

		// Footer should be a synced reference.
		$this->assertStringContainsString( 'wp:block', $content );
	}

	/**
	 * Test replace_text and replace_attributes on the same block occurrence.
	 *
	 * Previously these overwrote each other — only the last call would take effect.
	 */
	public function test_replace_text_and_attributes_on_same_occurrence() {
		$template = new Template( 'test/simple-heading' );

		$content = $template
			->replace_text( 'test/simple-heading', 'core/heading', 0, 'Updated Title' )
			->replace_attributes( 'test/simple-heading', 'core/heading', 0, [
				'level' => 3,
			] )
			->get_content();

		// Both transformations should be applied.
		$this->assertStringContainsString( 'Updated Title', $content );
		$this->assertStringContainsString( '"level":3', $content );
	}

	/**
	 * Test replace_attributes and replace_text on the same occurrence (reverse order).
	 */
	public function test_replace_attributes_then_text_on_same_occurrence() {
		$template = new Template( 'test/simple-heading' );

		$content = $template
			->replace_attributes( 'test/simple-heading', 'core/heading', 0, [
				'level' => 4,
			] )
			->replace_text( 'test/simple-heading', 'core/heading', 0, 'Also Updated' )
			->get_content();

		// Both transformations should be applied regardless of order.
		$this->assertStringContainsString( 'Also Updated', $content );
		$this->assertStringContainsString( '"level":4', $content );
	}

	/**
	 * Test transform_callback does not wipe per-occurrence transformations.
	 */
	public function test_transform_callback_preserves_occurrence_transforms() {
		$template = new Template( 'test/hero' );

		$callback_ran = false;

		$content = $template
			->replace_text( 'test/hero', 'core/paragraph', 0, 'Replaced first paragraph' )
			->transform_callback( 'test/hero', 'core/paragraph', function( $block ) use ( &$callback_ran ) {
				$callback_ran = true;
				return $block;
			} )
			->get_content();

		// The per-occurrence text replacement should still work.
		$this->assertStringContainsString( 'Replaced first paragraph', $content );
		// The callback should also have run.
		$this->assertTrue( $callback_ran );
	}

	/**
	 * Test replace_html replaces full block innerHTML.
	 */
	public function test_replace_html() {
		$template = new Template( 'test/simple-heading' );

		$content = $template
			->replace_html( 'test/simple-heading', 'core/paragraph', 0, '<p>Completely new HTML.</p>' )
			->get_content();

		$this->assertStringContainsString( 'Completely new HTML.', $content );
		$this->assertStringNotContainsString( 'Test paragraph content', $content );
	}

	/**
	 * Test search_replace within a block.
	 */
	public function test_search_replace() {
		$template = new Template( 'test/simple-heading' );

		$content = $template
			->search_replace( 'test/simple-heading', 'core/paragraph', 0, 'Test paragraph', 'Modified paragraph' )
			->get_content();

		$this->assertStringContainsString( 'Modified paragraph content.', $content );
		$this->assertStringNotContainsString( 'Test paragraph content.', $content );
	}

	/**
	 * Test chained search_replace calls on the same occurrence all apply.
	 */
	public function test_multiple_search_replace_on_same_occurrence() {
		$template = new Template( 'test/simple-heading' );

		$content = $template
			->search_replace( 'test/simple-heading', 'core/paragraph', 0, 'Test', 'Modified' )
			->search_replace( 'test/simple-heading', 'core/paragraph', 0, 'content', 'body' )
			->get_content();

		$this->assertStringContainsString( 'Modified paragraph body.', $content );
	}

	/**
	 * Test get_content returns WP_Error for missing pattern.
	 */
	public function test_get_content_returns_wp_error_for_missing_pattern() {
		$template = new Template( 'nonexistent/pattern' );

		$content = $template->get_content();

		$this->assertInstanceOf( \WP_Error::class, $content );
		$this->assertEquals( 'pattern_not_found', $content->get_error_code() );
	}

	/**
	 * Test that serialized output uses literal ampersands (not \u0026).
	 */
	public function test_get_content_serializes_ampersands_correctly() {
		$template = new Template( 'test/simple-heading' );

		$content = $template
			->replace_text( 'test/simple-heading', 'core/heading', 0, 'Tom & Jerry' )
			->get_content();

		$this->assertStringContainsString( 'Tom & Jerry', $content );
		$this->assertStringNotContainsString( '\\u0026', $content );
	}

	/**
	 * Test add_class updates the attribute and the markup together.
	 */
	public function test_add_class_updates_attrs_and_markup() {
		$template = new Template( 'test/simple-heading' );

		$content = $template
			->add_class( 'test/simple-heading', 'core/heading', 0, 'is-style-display' )
			->get_content();

		$this->assertStringContainsString( '"className":"is-style-display"', $content );
		$this->assertStringContainsString( '<h2 class="wp-block-heading is-style-display">', $content );
	}

	/**
	 * Test add_class targets a single occurrence.
	 */
	public function test_add_class_targets_specific_occurrence() {
		$template = new Template( 'test/hero' );

		$content = $template
			->add_class( 'test/hero', 'core/paragraph', 1, 'is-style-quiet' )
			->get_content();

		$this->assertStringContainsString( '<p class="hero-subtitle is-style-quiet">', $content );
		$this->assertStringContainsString( '<p class="hero-description">', $content );
	}

	/**
	 * Test repeated add_class calls on one block accumulate.
	 */
	public function test_add_class_calls_accumulate() {
		$template = new Template( 'test/simple-heading' );

		$content = $template
			->add_class( 'test/simple-heading', 'core/heading', 0, 'one' )
			->add_class( 'test/simple-heading', 'core/heading', 0, 'two' )
			->get_content();

		$this->assertStringContainsString( '"className":"one two"', $content );
		$this->assertStringContainsString( '<h2 class="wp-block-heading one two">', $content );
	}

	/**
	 * Test a later remove_class overrides an earlier add_class.
	 *
	 * Class operations run in call order, so a chain reads the way it executes.
	 */
	public function test_remove_class_after_add_class_wins() {
		$template = new Template( 'test/hero' );

		$content = $template
			->add_class( 'test/hero', 'core/paragraph', 0, 'flagged' )
			->remove_class( 'test/hero', 'core/paragraph', 0, 'flagged' )
			->get_content();

		$this->assertStringContainsString( '<p class="hero-description">', $content );
		$this->assertStringNotContainsString( 'flagged', $content );
	}

	/**
	 * Test a later add_class overrides an earlier remove_class.
	 */
	public function test_add_class_after_remove_class_wins() {
		$template = new Template( 'test/hero' );

		$content = $template
			->remove_class( 'test/hero', 'core/paragraph', 0, 'flagged' )
			->add_class( 'test/hero', 'core/paragraph', 0, 'flagged' )
			->get_content();

		$this->assertStringContainsString( '<p class="hero-description flagged">', $content );
	}

	/**
	 * Test add_class combines with a text replacement on the same block.
	 */
	public function test_add_class_combines_with_replace_text() {
		$template = new Template( 'test/simple-heading' );

		$content = $template
			->replace_text( 'test/simple-heading', 'core/heading', 0, 'New Title' )
			->add_class( 'test/simple-heading', 'core/heading', 0, 'is-style-display' )
			->get_content();

		$this->assertStringContainsString( '<h2 class="wp-block-heading is-style-display">New Title</h2>', $content );
	}

	/**
	 * Test add_class on a container block updates only its wrapper.
	 */
	public function test_add_class_on_container_block_updates_wrapper() {
		$template = new Template( 'test/hero' );

		$content = $template
			->add_class( 'test/hero', 'core/group', 0, 'is-featured' )
			->get_content();

		$this->assertStringContainsString( '<div class="wp-block-group hero is-featured">', $content );
		$this->assertStringContainsString( 'Hero Title', $content );
		$this->assertStringContainsString( 'Optional subtitle', $content );
	}

	/**
	 * Test remove_class drops the class from the attribute and the markup.
	 */
	public function test_remove_class_updates_attrs_and_markup() {
		$template = new Template( 'test/hero' );

		$content = $template
			->remove_class( 'test/hero', 'core/paragraph', 0, 'hero-description' )
			->get_content();

		$this->assertStringNotContainsString( 'hero-description', $content );
		$this->assertStringContainsString( 'Hero description text', $content );
		$this->assertStringContainsString( 'hero-subtitle', $content );
	}

	/**
	 * Test replace_class swaps one class for another.
	 */
	public function test_replace_class_swaps_classes() {
		$template = new Template( 'test/hero' );

		$content = $template
			->replace_class( 'test/hero', 'core/paragraph', 0, 'hero-description', 'is-style-lede' )
			->get_content();

		$this->assertStringNotContainsString( 'hero-description', $content );
		$this->assertStringContainsString( '"className":"is-style-lede"', $content );
		$this->assertStringContainsString( '<p class="is-style-lede">', $content );
	}
}
