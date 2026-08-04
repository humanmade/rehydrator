<?php
/**
 * Synced Patterns Tests
 *
 * @package HM\Rehydrator\Tests
 */

namespace HM\Rehydrator\Tests;

use WP_UnitTestCase;
use HM\Rehydrator\Synced_Patterns;

/**
 * Test synced pattern functions.
 */
class SyncedPatternsTest extends WP_UnitTestCase {

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
	 * Register test pattern before each test.
	 */
	public function set_up() : void {
		parent::set_up();

		register_block_pattern(
			'test/footer-cta',
			[
				'title' => 'Footer CTA',
				'content' => $this->load_pattern( 'footer-cta' ),
			]
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() : void {
		unregister_block_pattern( 'test/footer-cta' );
		parent::tear_down();
	}

	/**
	 * Test get_or_create creates a new synced pattern.
	 */
	public function test_get_or_create_creates_new_pattern() {
		$post_id = Synced_Patterns\get_or_create(
			'footer-cta-articles',
			'test/footer-cta',
			'Footer CTA for Articles'
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		// Verify the post was created correctly.
		$post = get_post( $post_id );
		$this->assertEquals( 'wp_block', $post->post_type );
		$this->assertEquals( 'Footer CTA for Articles', $post->post_title );
		$this->assertEquals( 'publish', $post->post_status );

		// Verify content contains the pattern markup.
		$this->assertStringContainsString( 'Ready to get started', $post->post_content );

		// Verify meta was stored.
		$stored_key = get_post_meta( $post_id, Synced_Patterns\SYNCED_PATTERN_KEY_META, true );
		$this->assertEquals( 'footer-cta-articles', $stored_key );
	}

	/**
	 * Test get_or_create returns existing pattern on second call.
	 */
	public function test_get_or_create_returns_existing_pattern() {
		$first_id = Synced_Patterns\get_or_create(
			'footer-cta-reuse-test',
			'test/footer-cta',
			'Footer CTA Reuse Test'
		);

		$second_id = Synced_Patterns\get_or_create(
			'footer-cta-reuse-test',
			'test/footer-cta',
			'Footer CTA Reuse Test'
		);

		$this->assertEquals( $first_id, $second_id );

		// Verify only one post was created.
		$posts = get_posts( [
			'post_type' => 'wp_block',
			'meta_key' => Synced_Patterns\SYNCED_PATTERN_KEY_META,
			// phpcs:ignore HM.Performance.SlowMetaQuery.slow_query_meta_value
			'meta_value' => 'footer-cta-reuse-test',
		] );
		$this->assertCount( 1, $posts );
	}

	/**
	 * Test get_or_create with different keys creates separate patterns.
	 */
	public function test_get_or_create_different_keys_create_separate_patterns() {
		$articles_id = Synced_Patterns\get_or_create(
			'footer-cta-articles',
			'test/footer-cta',
			'Footer CTA for Articles'
		);

		$case_studies_id = Synced_Patterns\get_or_create(
			'footer-cta-case-studies',
			'test/footer-cta',
			'Footer CTA for Case Studies'
		);

		$this->assertNotEquals( $articles_id, $case_studies_id );

		// Verify both have correct titles.
		$this->assertEquals( 'Footer CTA for Articles', get_the_title( $articles_id ) );
		$this->assertEquals( 'Footer CTA for Case Studies', get_the_title( $case_studies_id ) );
	}

	/**
	 * Test get_or_create returns false for non-existent pattern.
	 */
	public function test_get_or_create_returns_false_for_missing_pattern() {
		$result = Synced_Patterns\get_or_create(
			'nonexistent-key',
			'nonexistent/pattern',
			'Should Not Exist'
		);

		$this->assertFalse( $result );
	}

	/**
	 * Test create_block_reference returns correct structure.
	 */
	public function test_create_block_reference_returns_correct_structure() {
		$block = Synced_Patterns\create_block_reference( 123 );

		$this->assertEquals( 'core/block', $block['blockName'] );
		$this->assertEquals( 123, $block['attrs']['ref'] );
		$this->assertEmpty( $block['innerBlocks'] );
		$this->assertEquals( '', $block['innerHTML'] );
		$this->assertEmpty( $block['innerContent'] );
	}

	/**
	 * Test create_block_reference serializes correctly.
	 */
	public function test_create_block_reference_serializes_correctly() {
		$post_id = Synced_Patterns\get_or_create(
			'serialize-test',
			'test/footer-cta',
			'Serialize Test'
		);

		$block = Synced_Patterns\create_block_reference( $post_id );
		$serialized = serialize_blocks( [ $block ] );

		$this->assertStringContainsString( 'wp:block', $serialized );
		$this->assertStringContainsString( '"ref":' . $post_id, $serialized );
	}
}
