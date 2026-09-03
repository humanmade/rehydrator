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

	// Add and remove classes last, so they apply to the output markup of above
	// transformations. The ops run in the order they were registered, and are
	// folded into one attribute write so that a swap doesn't temporarily empty
	// the class attribute.
	if ( ! empty( $transformation['classOps'] ) ) {
		$block = apply_block_class_ops( $block, $transformation['classOps'] );
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
 * Uses WP_HTML_Tag_Processor to safely update text while keeping all attributes.
 *
 * @param array  $block Block to modify.
 * @param string $new_text New text content.
 * @return array Modified block.
 */
function update_block_text_content( array $block, string $new_text ) : array {
	$html = $block['innerHTML'] ?? '';

	if ( empty( $html ) ) {
		return $block;
	}

	$processor = new WP_HTML_Tag_Processor( $html );

	// Find the first HTML tag and extract its details.
	if ( ! $processor->next_tag() ) {
		return $block;
	}

	$tag_name = $processor->get_tag();
	$attributes = '';

	// Collect all attributes.
	foreach ( $processor->get_attribute_names_with_prefix( '' ) as $attr_name ) {
		$attr_value = $processor->get_attribute( $attr_name );
		if ( is_string( $attr_value ) ) {
			$attributes .= sprintf( ' %s="%s"', $attr_name, esc_attr( $attr_value ) );
		} else {
			// Boolean attribute (true) or missing value (null).
			$attributes .= sprintf( ' %s', $attr_name );
		}
	}

	// Rebuild the HTML with the new text inside the tag.
	// Use lowercase tag names for consistency with WordPress standards.
	$tag_name_lower = strtolower( $tag_name );
	$html = sprintf( '<%s%s>%s</%s>', $tag_name_lower, $attributes, $new_text, $tag_name_lower );

	$block['innerHTML'] = $html;
	$block['innerContent'] = [ $html ];

	return $block;
}

/**
 * Add one or more classes to a block.
 *
 * Keeps the block's className attribute and its markup synced. The class is
 * added to attrs['className'] and to the class attribute of the first tag in
 * the block's own markup. Classes already present are left as-is.
 *
 * Dynamic blocks that render their own markup (no innerHTML) receive attribute
 * updates only, which is enough for WP to output the right class in PHP.
 *
 * @param array           $block Block to modify.
 * @param string|string[] $classes Class name, space-separated list, or array of either.
 * @return array Modified block.
 */
function add_block_class( array $block, string|array $classes ) : array {
	return apply_block_class_ops( $block, [
		[
			'action' => 'add',
			'classes' => $classes,
		],
	] );
}

/**
 * Remove one or more classes from a block.
 *
 * The inverse of add_block_class(): removes the class(es) from both HTML markup
 * and attrs['className']. Ignores missing removal classes, and removes className
 * if the list is empty at the end.
 *
 * @param array           $block Block to modify.
 * @param string|string[] $classes Class name, space-separated list, or array of either.
 * @return array Modified block.
 */
function remove_block_class( array $block, string|array $classes ) : array {
	return apply_block_class_ops( $block, [
		[
			'action' => 'remove',
			'classes' => $classes,
		],
	] );
}

/**
 * Swap one set of classes on a block for another.
 *
 * The new classes are added whether or not the old ones were present, so this
 * works as "make sure the block has this style in the end".
 *
 * @param array           $block Block to modify.
 * @param string|string[] $old_classes Classes to remove.
 * @param string|string[] $new_classes Classes to add.
 * @return array Modified block.
 */
function replace_block_class( array $block, string|array $old_classes, string|array $new_classes ) : array {
	return apply_block_class_ops( $block, [
		[
			'action' => 'remove',
			'classes' => $old_classes,
		],
		[
			'action' => 'add',
			'classes' => $new_classes,
		],
	] );
}

/**
 * Apply an ordered list of class operations to a block's attribute and markup.
 *
 * Each operation is [ 'action' => 'add'|'remove', 'classes' => string|string[] ].
 * They are applied in the order given, so the last operation naming a class
 * decides whether the block ends up with it.
 *
 * @param array $block Block to modify.
 * @param array $ops Ordered class operations.
 * @return array Modified block.
 */
function apply_block_class_ops( array $block, array $ops ) : array {
	if ( empty( $ops ) ) {
		return $block;
	}

	$block['attrs'] = $block['attrs'] ?? [];
	$class_names = apply_class_ops(
		normalize_class_list( $block['attrs']['className'] ?? '' ),
		$ops
	);

	if ( empty( $class_names ) ) {
		unset( $block['attrs']['className'] );
	} else {
		$block['attrs']['className'] = implode( ' ', $class_names );
	}

	$block['innerHTML'] = update_markup_classes( $block['innerHTML'] ?? '', $ops );

	// Only the first innerContent chunk can hold the block's own opening tag.
	// Later chunks are markup between inner blocks.
	if ( isset( $block['innerContent'][0] ) && is_string( $block['innerContent'][0] ) ) {
		$block['innerContent'][0] = update_markup_classes( $block['innerContent'][0], $ops );
	}

	return $block;
}

/**
 * Add and remove classes on the first tag in a fragment of markup.
 *
 * The operations are folded into a final class list and the class attribute is
 * written once, rather than mutated class by class. The tag processor's
 * remove_class() drops the attribute entirely when the last class goes, and a
 * subsequent add_class() then leaves stray whitespace behind in the tag.
 *
 * @param string $html Markup to update. May be a fragment, such as a block's opening tag.
 * @param array  $ops Ordered class operations.
 * @return string Updated markup, unchanged when it contains no tag or needs no edit.
 */
function update_markup_classes( string $html, array $ops ) : string {
	if ( $html === '' ) {
		return $html;
	}

	$processor = new WP_HTML_Tag_Processor( $html );

	if ( ! $processor->next_tag() ) {
		return $html;
	}

	$existing = $processor->get_attribute( 'class' );
	$existing = normalize_class_list( is_string( $existing ) ? $existing : '' );
	$updated = apply_class_ops( $existing, $ops );

	// Leave the markup byte-for-byte alone when the class list is unchanged.
	if ( $updated === $existing ) {
		return $html;
	}

	if ( empty( $updated ) ) {
		$processor->remove_attribute( 'class' );
	} else {
		$processor->set_attribute( 'class', implode( ' ', $updated ) );
	}

	return $processor->get_updated_html();
}

/**
 * Fold an ordered list of class operations into an existing class list.
 *
 * Operations are applied in order, so a class named by more than one of them
 * ends up as the last operation left it. Used for both the className attribute
 * and the markup, which is what keeps the two in step.
 *
 * @param string[] $existing Current classes.
 * @param array    $ops Ordered class operations, each [ 'action' => 'add'|'remove', 'classes' => string|string[] ].
 * @return string[] Resulting class names.
 */
function apply_class_ops( array $existing, array $ops ) : array {
	$result = $existing;

	foreach ( $ops as $op ) {
		$classes = normalize_class_list( $op['classes'] ?? [] );

		if ( ( $op['action'] ?? '' ) === 'remove' ) {
			$result = array_values( array_diff( $result, $classes ) );
			continue;
		}

		foreach ( $classes as $class ) {
			if ( ! in_array( $class, $result, true ) ) {
				$result[] = $class;
			}
		}
	}

	return $result;
}

/**
 * Normalize class input to a list of unique class names.
 *
 * Accepts a single class, a space-separated list, or an array of either, so
 * callers can pass whatever shape their source data is in.
 *
 * @param string|string[] $classes Classes to normalize.
 * @return string[] Unique class names.
 */
function normalize_class_list( string|array $classes ) : array {
	$result = [];

	foreach ( (array) $classes as $item ) {
		foreach ( preg_split( '/\s+/', trim( (string) $item ) ) as $class ) {
			if ( $class !== '' && ! in_array( $class, $result, true ) ) {
				$result[] = $class;
			}
		}
	}

	return $result;
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
