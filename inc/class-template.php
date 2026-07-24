<?php
/**
 * Pattern Transformer Template Class
 *
 * Provides a fluent API for transforming block patterns with imported data.
 *
 * @package HM\Rehydrator
 */

namespace HM\Rehydrator;

use HM\Rehydrator\Content_Parser;
use HM\Rehydrator\Pattern_Transformer;
use HM\Rehydrator\Synced_Patterns;
use WP_Error;

/**
 * Template class for pattern-based content transformation.
 *
 * Usage:
 *   $transformer = new Template( 'theme/template-article' );
 *   $content = $transformer
 *       ->replace_text( 'theme/hero', 'core/heading', 0, 'Title' )
 *       ->replace_placeholder( 'content-placeholder', $content_blocks )
 *       ->get_content();
 */
class Template {

	/**
	 * Parsed and resolved blocks.
	 *
	 * @var array
	 */
	protected $blocks = [];

	/**
	 * Transformations to apply.
	 *
	 * @var array
	 */
	protected $transformations = [];

	/**
	 * Error encountered during processing.
	 *
	 * @var WP_Error|null
	 */
	protected $error = null;

	/**
	 * Pattern slugs to replace with synced patterns.
	 *
	 * Each entry is keyed by pattern slug and contains:
	 * - 'key': Unique key for the synced pattern
	 * - 'title': Display title for the synced pattern
	 *
	 * @var array
	 */
	protected $synced_patterns = [];

	/**
	 * Template pattern slug.
	 *
	 * @var string
	 */
	protected $pattern_slug = '';

	/**
	 * Whether pattern has been loaded.
	 *
	 * @var bool
	 */
	protected $pattern_loaded = false;

	/**
	 * Constructor.
	 *
	 * @param string $pattern_slug Pattern slug to load.
	 */
	public function __construct( string $pattern_slug ) {
		$this->pattern_slug = $pattern_slug;
		// Don't load pattern yet - wait until get_content() or get_blocks()
		// is called so all transformations can be registered first.
	}

	/**
	 * Load and resolve pattern.
	 *
	 * Loads the pattern and applies synced pattern replacements before resolving.
	 * Called automatically by get_content() and get_blocks().
	 *
	 * @return void
	 */
	protected function load_pattern() {
		// Only load once.
		if ( $this->pattern_loaded ) {
			return;
		}

		$this->pattern_loaded = true;

		$markup = Pattern_Transformer\get_pattern_by_slug( $this->pattern_slug );

		if ( empty( $markup ) ) {
			$this->error = new WP_Error(
				'pattern_not_found',
				sprintf( 'Pattern "%s" not found in registry', $this->pattern_slug )
			);
			return;
		}

		// Parse blocks.
		$blocks = parse_blocks( $markup );

		// Replace pattern references with synced patterns before resolution.
		// This must happen before resolve_and_tag_patterns so synced patterns
		// remain as wp:block references instead of being resolved inline.
		$blocks = $this->replace_synced_pattern_references( $blocks );

		// Resolve remaining nested patterns.
		$this->blocks = Pattern_Transformer\resolve_and_tag_patterns( $blocks );

		// Tag top-level blocks with the template pattern slug so transformations
		// targeting this pattern will apply. This is needed because resolve_and_tag_patterns
		// only tags blocks when resolving nested pattern references.
		$this->blocks = Pattern_Transformer\tag_blocks_recursively( $this->blocks, $this->pattern_slug );
	}

	/**
	 * Replace text content of a specific block.
	 *
	 * @param string $pattern_slug Source pattern slug.
	 * @param string $block_type Block type (e.g., 'core/heading').
	 * @param int    $occurrence Which occurrence (0-indexed).
	 * @param string $text New text content.
	 * @return self For chaining.
	 */
	public function replace_text( string $pattern_slug, string $block_type, int $occurrence, string $text ) {
		$this->merge_transformation( $pattern_slug, $block_type, $occurrence, [
			'textContent' => $text,
		] );

		return $this;
	}

	/**
	 * Replace attributes of a specific block.
	 *
	 * @param string $pattern_slug Source pattern slug.
	 * @param string $block_type Block type.
	 * @param int    $occurrence Which occurrence (0-indexed).
	 * @param array  $attrs Attributes to merge.
	 * @return self For chaining.
	 */
	public function replace_attributes( string $pattern_slug, string $block_type, int $occurrence, array $attrs ) {
		$this->merge_transformation( $pattern_slug, $block_type, $occurrence, [
			'attrs' => $attrs,
		] );

		return $this;
	}

	/**
	 * Replace the full innerHTML of a specific block.
	 *
	 * @param string $pattern_slug Source pattern slug.
	 * @param string $block_type Block type.
	 * @param int    $occurrence Which occurrence (0-indexed).
	 * @param string $html New innerHTML content.
	 * @return self For chaining.
	 */
	public function replace_html( string $pattern_slug, string $block_type, int $occurrence, string $html ) {
		$this->merge_transformation( $pattern_slug, $block_type, $occurrence, [
			'innerHTML' => $html,
		] );

		return $this;
	}

	/**
	 * Search and replace within a specific block's HTML.
	 *
	 * @param string $pattern_slug Source pattern slug.
	 * @param string $block_type Block type.
	 * @param int    $occurrence Which occurrence (0-indexed).
	 * @param string $search String to search for.
	 * @param string $replace Replacement string.
	 * @return self For chaining.
	 */
	public function search_replace( string $pattern_slug, string $block_type, int $occurrence, string $search, string $replace ) {
		// Stored as arrays so repeated calls on the same occurrence accumulate;
		// str_replace() applies all pairs in one pass.
		$this->merge_transformation( $pattern_slug, $block_type, $occurrence, [
			'search'  => [ $search ],
			'replace' => [ $replace ],
		] );

		return $this;
	}

	/**
	 * Apply a callback transformation to a specific block type within a pattern.
	 *
	 * @param string   $pattern_slug Source pattern slug.
	 * @param string   $block_type Block type.
	 * @param callable $callback Callback that receives block and returns modified block.
	 * @return self For chaining.
	 */
	public function transform_callback( string $pattern_slug, string $block_type, callable $callback ) {
		$this->merge_transformation( $pattern_slug, $block_type, null, [
			'callback' => $callback,
		] );

		return $this;
	}

	/**
	 * Remove a specific block occurrence from a pattern.
	 *
	 * Useful for removing blocks when source content doesn't have corresponding data.
	 *
	 * @param string $pattern_slug Source pattern slug.
	 * @param string $block_type Block type to remove.
	 * @param int    $occurrence Which occurrence to remove (0-indexed).
	 * @return self For chaining.
	 */
	public function remove_block( string $pattern_slug, string $block_type, int $occurrence ) {
		$this->merge_transformation( $pattern_slug, $block_type, $occurrence, [
			'_delete' => true,
		] );

		return $this;
	}

	/**
	 * Replace a pattern reference with a synced (reusable) pattern.
	 *
	 * When a pattern slug is registered here, instead of resolving it inline,
	 * the template will reference a synced pattern (wp_block post). If the synced
	 * pattern doesn't exist, it will be created automatically.
	 *
	 * This is useful for sections that should be shared across multiple posts
	 * (e.g., footer CTAs, resource sections).
	 *
	 * @param string $pattern_slug Pattern slug to convert to synced pattern.
	 * @param string $key Unique key to identify this synced pattern instance.
	 * @param string $title Display title for the synced pattern in the editor.
	 * @return self For chaining.
	 */
	public function replace_with_synced_pattern( string $pattern_slug, string $key, string $title ) {
		$this->synced_patterns[ $pattern_slug ] = [
			'key'   => $key,
			'title' => $title,
		];
		return $this;
	}

	/**
	 * Conditionally remove a block if a value is empty.
	 *
	 * @param string $pattern_slug Source pattern slug.
	 * @param string $block_type Block type to remove.
	 * @param int    $occurrence Which occurrence to remove (0-indexed).
	 * @param mixed  $value Value to check - block removed if empty.
	 * @return self For chaining.
	 */
	public function remove_if_empty( string $pattern_slug, string $block_type, int $occurrence, $value ) {
		// Only add deletion transformation if value is actually empty.
		if ( empty( $value ) ) {
			return $this->remove_block( $pattern_slug, $block_type, $occurrence );
		}

		return $this;
	}

	/**
	 * Remove a block based on a callback condition.
	 *
	 * @param string   $pattern_slug Source pattern slug.
	 * @param string   $block_type Block type to remove.
	 * @param int      $occurrence Which occurrence to remove (0-indexed).
	 * @param callable $condition Callback that returns true if block should be removed.
	 * @return self For chaining.
	 */
	public function remove_if( string $pattern_slug, string $block_type, int $occurrence, callable $condition ) {
		// Evaluate condition immediately.
		if ( $condition() === true ) {
			return $this->remove_block( $pattern_slug, $block_type, $occurrence );
		}

		return $this;
	}

	/**
	 * Replace a content placeholder block with actual content.
	 *
	 * Looks for blocks with metadata.name matching the placeholder name and replaces them.
	 * Does NOT apply transformations - that happens in get_content() after all setup is complete.
	 *
	 * @param string $placeholder_name Placeholder name to match (default: "content-placeholder").
	 * @param array  $content_blocks Array of blocks to insert.
	 * @return self For chaining.
	 */
	public function replace_placeholder( string $placeholder_name, array $content_blocks ) {
		// Load pattern if not already loaded.
		$this->load_pattern();

		// Default placeholder name if empty string provided.
		if ( empty( $placeholder_name ) ) {
			$placeholder_name = 'content-placeholder';
		}

		$this->blocks = $this->replace_placeholder_recursive( $this->blocks, $placeholder_name, $content_blocks );
		return $this;
	}

	/**
	 * Recursively replace placeholder blocks.
	 *
	 * @param array  $blocks Blocks to process.
	 * @param string $placeholder_name Placeholder name to match.
	 * @param array  $content_blocks Content to insert.
	 * @return array Modified blocks.
	 */
	protected function replace_placeholder_recursive( array $blocks, string $placeholder_name, array $content_blocks ) {
		$result = [];

		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? '';
			$metadata_name = $block['attrs']['metadata']['name'] ?? '';

			// Check if this block has the matching placeholder metadata.
			// Placeholder blocks can be any block type with metadata.name = placeholder_name.
			if ( $metadata_name === $placeholder_name ) {
				// Replace with content blocks.
				foreach ( $content_blocks as $content_block ) {
					$result[] = $content_block;
				}
				continue;
			}

			// Process inner blocks recursively.
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->replace_placeholder_recursive(
					$block['innerBlocks'],
					$placeholder_name,
					$content_blocks
				);

				// Rebuild innerContent after modifying innerBlocks.
				$block = Pattern_Transformer\rebuild_inner_content( $block );
			}

			$result[] = $block;
		}

		return $result;
	}

	/**
	 * Merge a transformation into the transformations array.
	 *
	 * Merges with any existing transformation for the same target, so multiple
	 * calls (e.g. replace_text + replace_attributes) can be combined on the
	 * same block occurrence.
	 *
	 * @param string   $pattern_slug Source pattern slug.
	 * @param string   $block_type Block type.
	 * @param int|null $occurrence Occurrence index, or null for all-occurrence transforms.
	 * @param array    $transformation Transformation data to merge.
	 */
	protected function merge_transformation( string $pattern_slug, string $block_type, ?int $occurrence, array $transformation ) : void {
		if ( ! isset( $this->transformations[ $pattern_slug ] ) ) {
			$this->transformations[ $pattern_slug ] = [];
		}

		if ( ! isset( $this->transformations[ $pattern_slug ][ $block_type ] ) ) {
			$this->transformations[ $pattern_slug ][ $block_type ] = [];
		}

		if ( $occurrence === null ) {
			// All-occurrence transformation (e.g. callback).
			$this->transformations[ $pattern_slug ][ $block_type ] = array_merge(
				$this->transformations[ $pattern_slug ][ $block_type ],
				$transformation
			);
		} else {
			// Per-occurrence transformation.
			$existing = $this->transformations[ $pattern_slug ][ $block_type ][ $occurrence ] ?? [];

			// Accumulate search/replace pairs rather than letting array_merge
			// overwrite them, so multiple search_replace() calls can target the
			// same block occurrence.
			if ( isset( $existing['search'], $transformation['search'] ) ) {
				$transformation['search'] = array_merge( $existing['search'], $transformation['search'] );
				$transformation['replace'] = array_merge( $existing['replace'], $transformation['replace'] );
			}

			$this->transformations[ $pattern_slug ][ $block_type ][ $occurrence ] = array_merge(
				$existing,
				$transformation
			);
		}
	}

	/**
	 * Replace pattern references with synced pattern references.
	 *
	 * Recursively walks through blocks and replaces any wp:pattern references
	 * that are in the synced_patterns list with wp:block references.
	 *
	 * @param array $blocks Blocks to process.
	 * @return array Blocks with synced pattern references.
	 */
	protected function replace_synced_pattern_references( array $blocks ) : array {
		if ( empty( $this->synced_patterns ) ) {
			return $blocks;
		}

		$result = [];

		foreach ( $blocks as $block ) {
			// Check if this is a pattern reference that should be synced.
			if ( ( $block['blockName'] ?? '' ) === 'core/pattern' ) {
				$slug = $block['attrs']['slug'] ?? '';

				if ( isset( $this->synced_patterns[ $slug ] ) ) {
					$synced_config = $this->synced_patterns[ $slug ];

					// Get or create synced pattern.
					$synced_id = Synced_Patterns\get_or_create(
						$synced_config['key'],
						$slug,
						$synced_config['title']
					);

					if ( $synced_id ) {
						// Replace with synced block reference.
						$result[] = Synced_Patterns\create_block_reference( $synced_id );
						continue;
					}
					// If creation failed, fall through to keep original reference.
				}
			}

			// Process inner blocks recursively.
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->replace_synced_pattern_references( $block['innerBlocks'] );
			}

			$result[] = $block;
		}

		return $result;
	}

	/**
	 * Apply all pending transformations.
	 *
	 * This is called automatically by get_content() and get_blocks() after
	 * all transformations have been registered via the fluent API.
	 *
	 * @return self For chaining.
	 */
	protected function apply_transformations() {
		if ( ! empty( $this->transformations ) && ! $this->error ) {
			$this->blocks = Pattern_Transformer\apply_pattern_transformations( $this->blocks, $this->transformations );
			// Clear transformations after applying.
			$this->transformations = [];
		}
		return $this;
	}

	/**
	 * Get the final transformed content as block markup.
	 *
	 * @return string|WP_Error Block markup or error.
	 */
	public function get_content() {
		// Load pattern if not already loaded.
		$this->load_pattern();

		if ( $this->error ) {
			return $this->error;
		}

		// Apply any pending transformations.
		$this->apply_transformations();

		// Serialize to markup using the Content_Parser version which fixes
		// JSON ampersand encoding to match the JavaScript block editor.
		return Content_Parser\serialize_blocks( $this->blocks );
	}

	/**
	 * Get the blocks array (after all transformations).
	 *
	 * Useful for debugging or additional processing.
	 *
	 * @return array Blocks array.
	 */
	public function get_blocks() {
		// Load pattern if not already loaded.
		$this->load_pattern();

		if ( $this->error ) {
			return [];
		}

		// Apply any pending transformations.
		$this->apply_transformations();

		return $this->blocks;
	}

	/**
	 * Check if there was an error during processing.
	 *
	 * @return bool True if error occurred.
	 */
	public function has_error() {
		// Load pattern to determine error state.
		$this->load_pattern();

		return $this->error !== null;
	}

	/**
	 * Get the error if one occurred.
	 *
	 * @return WP_Error|null
	 */
	public function get_error() {
		return $this->error;
	}
}
