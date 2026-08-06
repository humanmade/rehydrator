<?php
/**
 * Pattern Transformer Functions
 *
 * Load block patterns and replace placeholder content with actual data.
 * Handles pattern resolution (wp:pattern references) and transformations.
 *
 * @package HM\Rehydrator
 */

namespace HM\Rehydrator\Pattern_Transformer;

use WP_Block_Patterns_Registry;
use WP_HTML_Tag_Processor;

/**
 * Get pattern content by slug using WordPress pattern registry.
 *
 * @param string $slug Pattern slug (e.g., 'theme/hero-internal').
 * @return string Pattern markup, or empty string if not found.
 */
function get_pattern_by_slug( string $slug ) : string {
	// Check if running in WordPress context.
	if ( ! class_exists( WP_Block_Patterns_Registry::class ) ) {
		return '';
	}

	// Get pattern from registry.
	$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $slug );

	if ( ! $pattern ) {
		return '';
	}

	return $pattern['content'] ?? '';
}

/**
 * Resolve pattern references and tag blocks with their source pattern.
 *
 * Finds wp:pattern blocks and replaces them with the actual pattern content.
 * Tags each block with _source_pattern metadata for transformation targeting.
 *
 * @param array  $blocks Parsed blocks.
 * @param string $source_pattern Current source pattern slug.
 * @return array Blocks with patterns resolved and source tagged.
 */
function resolve_and_tag_patterns( array $blocks, string $source_pattern = '' ) : array {
	$result = [];

	foreach ( $blocks as $block ) {
		// Tag block with its source pattern.
		if ( ! empty( $source_pattern ) ) {
			$block['_source_pattern'] = $source_pattern;
		}

		// Check if this is a pattern reference.
		if ( ( $block['blockName'] ?? '' ) === 'core/pattern' ) {
			$slug = $block['attrs']['slug'] ?? '';

			// Get pattern content from WordPress registry.
			$pattern_markup = get_pattern_by_slug( $slug );

			if ( empty( $pattern_markup ) ) {
				// Pattern not found, keep the reference block as-is.
				$result[] = $block;
				continue;
			}

			$pattern_blocks = parse_blocks( $pattern_markup );

			// Recursively resolve with this pattern as the source.
			$resolved_blocks = resolve_and_tag_patterns( $pattern_blocks, $slug );

			// Add resolved blocks to result (replacing the pattern reference).
			foreach ( $resolved_blocks as $resolved_block ) {
				$result[] = $resolved_block;
			}

			continue;
		}

		// Process inner blocks.
		if ( ! empty( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = resolve_and_tag_patterns(
				$block['innerBlocks'],
				$source_pattern
			);

			// Filter out null/whitespace blocks (these are preserved in innerContent, not innerBlocks).
			$block['innerBlocks'] = array_values( array_filter(
				$block['innerBlocks'],
				function( $inner_block ) {
					return ! empty( $inner_block['blockName'] );
				}
			) );

			// Rebuild innerContent to match the new innerBlocks structure after resolution.
			$block = rebuild_inner_content( $block );
		}

		$result[] = $block;
	}

	return $result;
}

/**
 * Recursively tag blocks with a source pattern if they don't have one.
 *
 * This ensures nested blocks inherit their parent's pattern context.
 *
 * @param array  $blocks Blocks to tag.
 * @param string $source_pattern Pattern to tag with.
 * @return array Tagged blocks.
 */
function tag_blocks_recursively( array $blocks, string $source_pattern ) : array {
	foreach ( $blocks as &$block ) {
		// Only tag if block doesn't already have a source pattern.
		if ( empty( $block['_source_pattern'] ) && empty( $block['attrs']['metadata']['patternName'] ?? '' ) ) {
			$block['_source_pattern'] = $source_pattern;
		}

		// Recurse into inner blocks.
		if ( ! empty( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = tag_blocks_recursively( $block['innerBlocks'], $source_pattern );
		}
	}

	return $blocks;
}

/**
 * Apply transformations to blocks based on their source pattern.
 *
 * @param array $blocks Blocks with _source_pattern metadata.
 * @param array $transformations Transformations per pattern.
 * @param array $block_type_counters Counter array (for internal recursion).
 * @return array Transformed blocks.
 */
function apply_pattern_transformations( array $blocks, array $transformations, array &$block_type_counters = [] ) : array {
	$result = [];

	foreach ( $blocks as $block ) {
		// Check both _source_pattern (from resolved pattern references) and
		// metadata.patternName (from inline content with semantic pattern grouping).
		$source_pattern = $block['_source_pattern'] ?? $block['attrs']['metadata']['patternName'] ?? '';
		$block_name = $block['blockName'] ?? '';

		// Track occurrences of each block type within each pattern.
		$counter_key = $source_pattern . '::' . $block_name;
		if ( ! isset( $block_type_counters[ $counter_key ] ) ) {
			$block_type_counters[ $counter_key ] = 0;
		}
		$occurrence = $block_type_counters[ $counter_key ];
		$block_type_counters[ $counter_key ]++;

		$should_delete = false;

		// Check if we have transformations for this pattern.
		if ( ! empty( $source_pattern ) && isset( $transformations[ $source_pattern ] ) ) {
			$pattern_transforms = $transformations[ $source_pattern ];

			// Check if there's a callback for the whole pattern.
			if ( isset( $pattern_transforms['callback'] ) && is_callable( $pattern_transforms['callback'] ) ) {
				$block = $pattern_transforms['callback']( $block );
			}

			// Apply block-type specific transformations.
			if ( ! empty( $block_name ) && isset( $pattern_transforms[ $block_name ] ) ) {
				$block_transform = $pattern_transforms[ $block_name ];

				// Apply per-occurrence transformation if one exists.
				if ( isset( $block_transform[ $occurrence ] ) ) {
					$transform = $block_transform[ $occurrence ];

					// Check if this is a deletion transformation.
					if ( isset( $transform['_delete'] ) && $transform['_delete'] === true ) {
						$should_delete = true;
					} else {
						$block = apply_block_transformation( $block, $transform );
					}
				}

				// Apply block-type-level callback (applies to all occurrences).
				// This runs in addition to any per-occurrence transform above.
				if ( ! $should_delete && isset( $block_transform['callback'] ) && is_callable( $block_transform['callback'] ) ) {
					$block = $block_transform['callback']( $block );
				}

				// If no per-occurrence or callback transform matched, try as a
				// single transformation for all occurrences (non-callback keys
				// like _delete, textContent, etc. at the block-type level).
				if ( ! $should_delete && ! isset( $block_transform[ $occurrence ] ) && ! isset( $block_transform['callback'] ) && ! isset( $block_transform[0] ) ) {
					if ( isset( $block_transform['_delete'] ) && $block_transform['_delete'] === true ) {
						$should_delete = true;
					} else {
						$block = apply_block_transformation( $block, $block_transform );
					}
				}
			}
		}

		// Skip this block if marked for deletion.
		if ( $should_delete ) {
			continue;
		}

		// Clean up metadata.
		unset( $block['_source_pattern'] );

		// Process inner blocks.
		if ( ! empty( $block['innerBlocks'] ) ) {
			$original_inner_count = count( $block['innerBlocks'] );

			// Tag inner blocks with parent's source pattern if they don't have one.
			// This ensures transformations targeting a pattern also apply to nested blocks.
			// Do this recursively for ALL descendant blocks.
			if ( ! empty( $source_pattern ) ) {
				$block['innerBlocks'] = tag_blocks_recursively( $block['innerBlocks'], $source_pattern );
			}

			$block['innerBlocks'] = apply_pattern_transformations(
				$block['innerBlocks'],
				$transformations,
				$block_type_counters
			);

			// If blocks were deleted, rebuild innerContent to match new innerBlocks count.
			if ( count( $block['innerBlocks'] ) !== $original_inner_count ) {
				$block = rebuild_inner_content( $block );
			}
		}

		$result[] = $block;
	}

	return $result;
}

/**
 * Apply a transformation to a single block.
 *
 * @param array $block Block to modify.
 * @param array $transformation Transformation configuration.
 * @return array Modified block.
 */
function apply_block_transformation( array $block, array $transformation ) : array {
	// Replace entire innerHTML.
	if ( isset( $transformation['innerHTML'] ) ) {
		$block['innerHTML'] = $transformation['innerHTML'];
		$block['innerContent'] = [ $transformation['innerHTML'] ];
	}

	// Replace or merge attributes.
	if ( isset( $transformation['attrs'] ) ) {
		$block['attrs'] = array_merge( $block['attrs'] ?? [], $transformation['attrs'] );
	}

	// Replace just the text content, preserving HTML structure.
	if ( isset( $transformation['textContent'] ) ) {
		$block = update_block_text_content( $block, $transformation['textContent'] );
	}

	// Search and replace in innerHTML.
	if ( isset( $transformation['search'] ) && isset( $transformation['replace'] ) ) {
		$block['innerHTML'] = str_replace(
			$transformation['search'],
			$transformation['replace'],
			$block['innerHTML']
		);

		// Also update innerContent.
		if ( ! empty( $block['innerContent'] ) ) {
			foreach ( $block['innerContent'] as &$content ) {
				if ( is_string( $content ) ) {
					$content = str_replace(
						$transformation['search'],
						$transformation['replace'],
						$content
					);
				}
			}
		}
	}

	// Callback for custom replacement logic.
	if ( isset( $transformation['callback'] ) && is_callable( $transformation['callback'] ) ) {
		$block = $transformation['callback']( $block );
	}

	return $block;
}

/**
 * Update just the text content of a block, preserving HTML structure.
 *
 * Finds the first text node in the block's innerHTML and replaces it using
 * WP_HTML_Tag_Processor::set_modifiable_text(), which HTML-encodes the value.
 * This preserves nested markup (e.g. the wrapping div and inner anchor of a
 * core/button block) and prevents XSS by escaping special characters.
 *
 * Use replace_html / the 'innerHTML' transformation key when you need to
 * inject raw HTML rather than plain text.
 *
 * @param array  $block    Block to modify.
 * @param string $new_text New plain-text content (will be HTML-encoded).
 * @return array Modified block.
 */
function update_block_text_content( array $block, string $new_text ) : array {
	$html = $block['innerHTML'] ?? '';

	if ( empty( $html ) ) {
		return $block;
	}

	$processor = new WP_HTML_Tag_Processor( $html );

	// Walk tokens until the first non-empty text node is found, then replace it.
	while ( $processor->next_token() ) {
		if ( '#text' !== $processor->get_token_type() ) {
			continue;
		}

		// Skip whitespace-only text nodes so we land on the real content.
		if ( '' === trim( $processor->get_modifiable_text() ) ) {
			continue;
		}

		// set_modifiable_text() HTML-encodes the value, keeping surrounding
		// markup (tags, attributes, sibling elements) intact.
		$processor->set_modifiable_text( $new_text );
		break;
	}

	$new_html = $processor->get_updated_html();

	$block['innerHTML'] = $new_html;
	$block['innerContent'] = [ $new_html ];

	return $block;
}

/**
 * Rebuild innerContent array to match innerBlocks structure.
 *
 * WordPress block serialization requires innerContent to have null placeholders
 * for each inner block. This rebuilds it when innerBlocks has been modified.
 *
 * Tries to use wrapper chunks the parser already recorded in innerContent, then
 * falls back to reconstructing a single wrapper tag with WP_HTML_Tag_Processor.
 *
 * Special handling for cover blocks to preserve image and overlay elements.
 *
 * @param array $block Block with potentially modified innerBlocks.
 * @return array Block with corrected innerContent.
 */
function rebuild_inner_content( array $block ) : array {
	if ( empty( $block['innerBlocks'] ) ) {
		// No inner blocks - innerContent should just be innerHTML.
		if ( isset( $block['innerHTML'] ) ) {
			$block['innerContent'] = [ $block['innerHTML'] ];
		}
		return $block;
	}

	$inner_html = $block['innerHTML'] ?? '';
	$block_name = $block['blockName'] ?? '';

	// Special handling for cover blocks - preserve image and overlay HTML.
	if ( $block_name === 'core/cover' && ! empty( $inner_html ) ) {
		// For cover blocks, preserve the original innerContent structure.
		// The innerHTML contains the image, overlay, and inner-container div.
		// We need to keep this structure intact.
		return $block;
	}

	// If there's no wrapper HTML, innerContent is just nulls.
	if ( empty( $inner_html ) ) {
		$block['innerContent'] = array_fill( 0, count( $block['innerBlocks'] ), null );
		return $block;
	}

	// Reuse wrapper chunks recorded by the block parser when a block has them.
	// This captures more varieties of wrapping markup around nested inner blocks
	// than we can infer from the top node of innerHTML alone.
	$existing = (array) ( $block['innerContent'] ?? [] );
	$opening  = $existing[0] ?? null;
	$closing  = count( $existing ) > 1 ? end( $existing ) : null;

	if ( is_string( $opening ) && is_string( $closing ) ) {
		$block['innerContent'] = array_merge(
			[ $opening ],
			array_fill( 0, count( $block['innerBlocks'] ), null ),
			[ $closing ]
		);

		return $block;
	}

	// Use WP_HTML_Tag_Processor to extract the tag name and attributes.
	$processor = new WP_HTML_Tag_Processor( $inner_html );

	if ( ! $processor->next_tag() ) {
		// No valid HTML tag found - just use nulls.
		$block['innerContent'] = array_fill( 0, count( $block['innerBlocks'] ), null );
		return $block;
	}

	// Reconstruct opening tag with all attributes.
	// Use lowercase tag names for consistency with WordPress standards.
	$tag_name = strtolower( $processor->get_tag() );
	$attributes = '';

	foreach ( $processor->get_attribute_names_with_prefix( '' ) as $attr_name ) {
		$attr_value = $processor->get_attribute( $attr_name );
		if ( is_string( $attr_value ) ) {
			$attributes .= sprintf( ' %s="%s"', $attr_name, esc_attr( $attr_value ) );
		} else {
			// Boolean attribute (true) or missing value (null).
			$attributes .= sprintf( ' %s', $attr_name );
		}
	}

	$opening_tag = "<{$tag_name}{$attributes}>";
	$closing_tag = "</{$tag_name}>";

	// Build innerContent: opening tag, null for each block, closing tag.
	$inner_content = [ $opening_tag ];

	foreach ( $block['innerBlocks'] as $inner_block ) {
		$inner_content[] = null;
	}

	$inner_content[] = $closing_tag;

	$block['innerContent'] = $inner_content;

	return $block;
}
