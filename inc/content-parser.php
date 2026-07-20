<?php
/**
 * Content Parser Functions
 *
 * Utilities for parsing and converting HTML content to Gutenberg blocks.
 * Handles mixed content (classic editor HTML and block content), detecting
 * freeform blocks, and converting classic HTML to proper block structures.
 *
 * @package HM\Rehydrator
 */

namespace HM\Rehydrator\Content_Parser;

/**
 * Check if content has Gutenberg block markers.
 *
 * Simple check for the presence of block comment markers.
 * Use this to determine if content needs conversion.
 *
 * @param string $content Content to check.
 * @return bool True if content appears to have block markers.
 */
function content_has_blocks( string $content ) : bool {
	return strpos( $content, '<!-- wp:' ) !== false;
}

/**
 * Check if a block is a freeform (classic) block.
 *
 * Freeform blocks are either explicitly core/freeform, or have no blockName
 * (which parse_blocks() returns for HTML between block markers).
 *
 * @param array $block Block to check.
 * @return bool True if the block is freeform content.
 */
function is_freeform_block( array $block ) : bool {
	$block_name = $block['blockName'] ?? null;

	return $block_name === null || $block_name === 'core/freeform';
}

/**
 * Check if a URL is from a known oEmbed provider.
 *
 * Uses WordPress core's oEmbed provider registry, which includes all built-in
 * providers plus any custom providers added via wp_oembed_add_provider().
 *
 * @param string $url URL to check.
 * @return bool True if the URL is from an oembed provider.
 */
function is_oembed_url( string $url ) : bool {
	$oembed = _wp_oembed_get_object();

	// get_provider() returns the provider URL if found, false otherwise.
	return (bool) $oembed->get_provider( $url, [ 'discover' => false ] );
}

/**
 * Detect if HTML content can be converted to an embed block.
 *
 * Extracts URLs from iframes and checks if they're from oEmbed providers.
 * Converts embed URLs (e.g., youtube.com/embed/ID) to canonical URLs for oEmbed.
 *
 * @param string $html HTML content to check.
 * @return array|false Array with 'type' and 'url' if embeddable, false otherwise.
 */
function detect_embeddable_html( string $html ) {
	// Extract URL from iframe src attribute.
	if ( ! preg_match( '/<iframe[^>]+src=["\']([^"\']+)["\']/', $html, $matches ) ) {
		return false;
	}

	$url = $matches[1];

	// Check if WordPress core recognizes this URL directly.
	if ( is_oembed_url( $url ) ) {
		return [
			'type' => 'oembed',
			'url' => $url,
		];
	}

	// Try converting embed URLs to canonical URLs that oEmbed expects.
	$canonical_url = convert_embed_url_to_canonical( $url );

	if ( $canonical_url && is_oembed_url( $canonical_url ) ) {
		return [
			'type' => 'oembed',
			'url' => $canonical_url,
		];
	}

	return false;
}

/**
 * Convert embed/player URLs to canonical URLs for oEmbed.
 *
 * Embed iframes often use different URL formats than what oEmbed expects.
 * For example, YouTube uses youtube.com/embed/ID but oEmbed expects youtube.com/watch?v=ID.
 *
 * @param string $url Embed URL to convert.
 * @return string|false Canonical URL or false if no conversion available.
 */
function convert_embed_url_to_canonical( string $url ) {
	// YouTube: /embed/ID → /watch?v=ID
	if ( preg_match( '/youtube\.com\/embed\/([a-zA-Z0-9_-]+)/', $url, $matches ) ) {
		return 'https://www.youtube.com/watch?v=' . $matches[1];
	}

	// Vimeo: player.vimeo.com/video/ID → vimeo.com/ID
	if ( preg_match( '/player\.vimeo\.com\/video\/(\d+)/', $url, $matches ) ) {
		return 'https://vimeo.com/' . $matches[1];
	}

	return false;
}

/**
 * Get the provider name from an embed URL.
 *
 * @param string $url Embed URL.
 * @return string Provider slug.
 */
function get_embed_provider_name( string $url ) : string {
	if ( str_contains( $url, 'youtube.com' ) || str_contains( $url, 'youtu.be' ) ) {
		return 'youtube';
	}
	if ( str_contains( $url, 'vimeo.com' ) ) {
		return 'vimeo';
	}
	if ( str_contains( $url, 'twitter.com' ) || str_contains( $url, 'x.com' ) ) {
		return 'twitter';
	}
	if ( str_contains( $url, 'facebook.com' ) ) {
		return 'facebook';
	}
	if ( str_contains( $url, 'instagram.com' ) ) {
		return 'instagram';
	}
	if ( str_contains( $url, 'soundcloud.com' ) ) {
		return 'soundcloud';
	}
	if ( str_contains( $url, 'spotify.com' ) ) {
		return 'spotify';
	}
	if ( str_contains( $url, 'wistia.com' ) ) {
		return 'wistia';
	}

	return 'generic';
}

/**
 * Get the embed type for a provider.
 *
 * WordPress embed blocks use a 'type' attribute to categorize embeds.
 * Most are 'video' or 'rich' depending on the provider.
 *
 * @param string $provider Provider slug from get_embed_provider_name().
 * @return string Embed type ('video', 'rich', or 'wp-embed').
 */
function get_embed_type( string $provider ) : string {
	$video_providers = [ 'youtube', 'vimeo', 'wistia' ];
	$rich_providers = [ 'twitter', 'facebook', 'instagram', 'spotify', 'soundcloud' ];

	if ( in_array( $provider, $video_providers, true ) ) {
		return 'video';
	}

	if ( in_array( $provider, $rich_providers, true ) ) {
		return 'rich';
	}

	return 'wp-embed';
}

/**
 * Create an embed block for a given URL.
 *
 * Produces consistent embed block markup used by both convert_html_to_embed_block()
 * and convert_iframe_element().
 *
 * @param string $url      Canonical embed URL.
 * @param string $provider Provider slug from get_embed_provider_name().
 * @return array Embed block array.
 */
function create_embed_block( string $url, string $provider ) : array {
	$embed_type = get_embed_type( $provider );

	$attrs = [
		'url'              => $url,
		'type'             => $embed_type,
		'providerNameSlug' => $provider,
		'responsive'       => true,
	];

	if ( $embed_type === 'video' ) {
		$attrs['className'] = 'wp-embed-aspect-16-9 wp-has-aspect-ratio';
	}

	$class_parts = [
		'wp-block-embed',
		'is-type-' . $embed_type,
		'is-provider-' . $provider,
		'wp-block-embed-' . $provider,
	];

	if ( $embed_type === 'video' ) {
		$class_parts[] = 'wp-embed-aspect-16-9';
		$class_parts[] = 'wp-has-aspect-ratio';
	}

	$figure_class = implode( ' ', $class_parts );

	$html = sprintf(
		'<figure class="%s"><div class="wp-block-embed__wrapper">%s</div></figure>',
		esc_attr( $figure_class ),
		"\n" . esc_url( $url ) . "\n"
	);

	return [
		'blockName'    => 'core/embed',
		'attrs'        => $attrs,
		'innerBlocks'  => [],
		'innerHTML'    => $html,
		'innerContent' => [ $html ],
	];
}

/**
 * Convert HTML block to embed block if possible.
 *
 * @param array $block HTML block to convert.
 * @return array|null Embed block if conversion successful, null otherwise.
 */
function convert_html_to_embed_block( array $block ) {
	if ( ( $block['blockName'] ?? '' ) !== 'core/html' ) {
		return null;
	}

	$html = $block['innerHTML'] ?? '';
	$embed_data = detect_embeddable_html( $html );

	if ( ! $embed_data ) {
		return null;
	}

	$url = $embed_data['url'];
	$provider = get_embed_provider_name( $url );

	return create_embed_block( $url, $provider );
}

/**
 * Get the inner HTML of a DOM node.
 *
 * @param \DOMNode     $node    Node to extract content from.
 * @param \DOMDocument $dom     Parent document.
 * @param array        $options Processing options.
 * @return string Inner HTML.
 */
function get_node_inner_html( \DOMNode $node, \DOMDocument $dom, array $options ) : string {
	$inner_html = '';

	foreach ( $node->childNodes as $child ) {
		$inner_html .= $dom->saveHTML( $child );
	}

	// Clean up if not preserving inline styles.
	if ( empty( $options['preserve_inline_styles'] ) ) {
		// Remove style attributes but preserve the tags.
		$inner_html = preg_replace( '/(<[^>]+)\s+style="[^"]*"([^>]*>)/i', '$1$2', $inner_html );
		// Remove aria-level attributes.
		$inner_html = preg_replace( '/\s*aria-level="[^"]*"/', '', $inner_html );
	}

	return $inner_html;
}

/**
 * Get the outer HTML of a DOM node (including the node itself).
 *
 * @param \DOMNode     $node    Node to extract.
 * @param \DOMDocument $dom     Parent document.
 * @param array        $options Processing options.
 * @return string Outer HTML.
 */
function get_node_outer_html( \DOMNode $node, \DOMDocument $dom, array $options ) : string {
	// For elements, use the node's ownerDocument.
	$owner = $node->ownerDocument;
	if ( ! $owner ) {
		return '';
	}

	$html = $owner->saveHTML( $node );

	// Clean up if not preserving inline styles.
	if ( empty( $options['preserve_inline_styles'] ) ) {
		$html = preg_replace( '/(<[^>]+)\s+style="[^"]*"([^>]*>)/i', '$1$2', $html );
		$html = preg_replace( '/\s*aria-level="[^"]*"/', '', $html );
	}

	return $html;
}

/**
 * Create a paragraph block.
 *
 * Internal function for the content parser. For user-facing content creation
 * with sanitization, use HM\Rehydrator\Blocks\create_paragraph().
 *
 * @param string $content Paragraph content.
 * @return array Paragraph block.
 */
function create_paragraph_block( string $content ) : array {
	return [
		'blockName'    => 'core/paragraph',
		'attrs'        => [],
		'innerBlocks'  => [],
		'innerHTML'    => '<p>' . $content . '</p>',
		'innerContent' => [ '<p>' . $content . '</p>' ],
	];
}

/**
 * Create a separator block.
 *
 * @return array Separator block.
 */
function create_separator_block() : array {
	return [
		'blockName'    => 'core/separator',
		'attrs'        => [],
		'innerBlocks'  => [],
		'innerHTML'    => '<hr class="wp-block-separator"/>',
		'innerContent' => [ '<hr class="wp-block-separator"/>' ],
	];
}

/**
 * Create a freeform (classic) block.
 *
 * Used as a fallback when HTML cannot be parsed into proper blocks.
 *
 * @param string $html HTML content.
 * @return array Freeform block.
 */
function create_freeform_block( string $html ) : array {
	return [
		'blockName'    => 'core/freeform',
		'attrs'        => [],
		'innerBlocks'  => [],
		'innerHTML'    => $html,
		'innerContent' => [ $html ],
	];
}

/**
 * Create a group block wrapping inner blocks.
 *
 * @param array $inner_blocks Inner block arrays.
 * @return array Group block.
 */
function create_group_block( array $inner_blocks ) : array {
	// innerContent interleaves the wrapper HTML with a null per inner block.
	$inner_content = [ '<div class="wp-block-group">' ];
	foreach ( $inner_blocks as $ignored ) {
		$inner_content[] = null;
	}
	$inner_content[] = '</div>';

	return [
		'blockName'    => 'core/group',
		'attrs'        => [],
		'innerBlocks'  => $inner_blocks,
		'innerHTML'    => '<div class="wp-block-group"></div>',
		'innerContent' => $inner_content,
	];
}

/**
 * Convert a heading element to a heading block.
 *
 * @param \DOMNode     $node    Heading element.
 * @param \DOMDocument $dom     Parent document.
 * @param array        $options Processing options.
 * @return array Heading block.
 */
function convert_heading_element( \DOMNode $node, \DOMDocument $dom, array $options ) : array {
	$level = (int) substr( $node->nodeName, 1 );
	$content = get_node_inner_html( $node, $dom, $options );

	// Strip <strong> tags from heading content (common in classic editor).
	$content = preg_replace( '/<strong>(.*?)<\/strong>/is', '$1', $content );
	$content = preg_replace( '/<b>(.*?)<\/b>/is', '$1', $content );
	$content = trim( $content );

	return [
		'blockName'    => 'core/heading',
		'attrs'        => [ 'level' => $level ],
		'innerBlocks'  => [],
		'innerHTML'    => sprintf( '<h%d>%s</h%d>', $level, $content, $level ),
		'innerContent' => [ sprintf( '<h%d>%s</h%d>', $level, $content, $level ) ],
	];
}

/**
 * Convert a paragraph element to a paragraph block.
 *
 * @param \DOMNode     $node    Paragraph element.
 * @param \DOMDocument $dom     Parent document.
 * @param array        $options Processing options.
 * @return array|null Paragraph block or null if empty.
 */
function convert_paragraph_element( \DOMNode $node, \DOMDocument $dom, array $options ) {
	// Check if paragraph contains only a single image - convert to image block.
	$img = $node->getElementsByTagName( 'img' )->item( 0 );
	if ( $img && $node->getElementsByTagName( 'img' )->length === 1 ) {
		// Check if the paragraph only contains the image (plus whitespace).
		$text_content = '';
		foreach ( $node->childNodes as $child ) {
			if ( $child->nodeType === XML_TEXT_NODE ) {
				$text_content .= $child->textContent;
			} elseif ( $child->nodeType === XML_ELEMENT_NODE && strtolower( $child->nodeName ) !== 'img' ) {
				// Has other elements besides img.
				$text_content .= 'x'; // Mark as having other content.
			}
		}
		if ( empty( trim( $text_content ) ) ) {
			return convert_image_element( $img );
		}
	}

	// Check if paragraph contains only a single iframe - convert to appropriate block.
	$iframe = $node->getElementsByTagName( 'iframe' )->item( 0 );
	if ( $iframe && $node->getElementsByTagName( 'iframe' )->length === 1 ) {
		$text_content = '';
		foreach ( $node->childNodes as $child ) {
			if ( $child->nodeType === XML_TEXT_NODE ) {
				$text_content .= $child->textContent;
			} elseif ( $child->nodeType === XML_ELEMENT_NODE && strtolower( $child->nodeName ) !== 'iframe' ) {
				$text_content .= 'x';
			}
		}
		if ( empty( trim( $text_content ) ) ) {
			return convert_iframe_element( $iframe );
		}
	}

	$content = get_node_inner_html( $node, $dom, $options );
	$content = trim( $content );

	// Skip empty paragraphs.
	if ( $options['clean_empty_paragraphs'] && empty( trim( strip_tags( $content ) ) ) ) {
		return null;
	}

	if ( empty( $content ) ) {
		return null;
	}

	return create_paragraph_block( $content );
}

/**
 * Convert a list element to a list block.
 *
 * @param \DOMNode     $node     List element (ul or ol).
 * @param \DOMDocument $dom      Parent document.
 * @param string       $tag_name Tag name (ul or ol).
 * @param array        $options  Processing options.
 * @return array List block.
 */
function convert_list_element( \DOMNode $node, \DOMDocument $dom, string $tag_name, array $options ) : array {
	$is_ordered = ( $tag_name === 'ol' );
	$attrs = $is_ordered ? [ 'ordered' => true ] : [];

	// Process list items into inner blocks.
	$inner_blocks = [];
	$list_html_parts = [ $is_ordered ? '<ol>' : '<ul>' ];

	foreach ( $node->childNodes as $child ) {
		if ( $child->nodeType !== XML_ELEMENT_NODE || strtolower( $child->nodeName ) !== 'li' ) {
			continue;
		}

		$item_content = get_node_inner_html( $child, $dom, $options );
		// Clean up aria-level and style attributes from list items.
		$item_content = preg_replace( '/\s*aria-level="[^"]*"/', '', $item_content );

		$inner_blocks[] = [
			'blockName'    => 'core/list-item',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => '<li>' . trim( $item_content ) . '</li>',
			'innerContent' => [ '<li>' . trim( $item_content ) . '</li>' ],
		];

		$list_html_parts[] = null; // Placeholder for inner block.
	}

	$list_html_parts[] = $is_ordered ? '</ol>' : '</ul>';

	return [
		'blockName'    => 'core/list',
		'attrs'        => $attrs,
		'innerBlocks'  => $inner_blocks,
		'innerHTML'    => '', // List blocks use innerContent for structure.
		'innerContent' => $list_html_parts,
	];
}

/**
 * Convert a blockquote element to a quote block.
 *
 * @param \DOMNode     $node    Blockquote element.
 * @param \DOMDocument $dom     Parent document.
 * @param array        $options Processing options.
 * @return array Quote block.
 */
function convert_blockquote_element( \DOMNode $node, \DOMDocument $dom, array $options ) : array {
	$inner_blocks = [];
	$citation = '';

	// Process children - paragraphs become inner blocks, cite becomes citation.
	foreach ( $node->childNodes as $child ) {
		if ( $child->nodeType !== XML_ELEMENT_NODE ) {
			continue;
		}

		$child_tag = strtolower( $child->nodeName );

		if ( $child_tag === 'cite' ) {
			$citation = get_node_inner_html( $child, $dom, $options );
		} elseif ( $child_tag === 'p' ) {
			$p_content = get_node_inner_html( $child, $dom, $options );
			if ( ! empty( trim( $p_content ) ) ) {
				$inner_blocks[] = create_paragraph_block( $p_content );
			}
		}
	}

	// If no paragraph children, treat entire content as one paragraph.
	if ( empty( $inner_blocks ) ) {
		$content = get_node_inner_html( $node, $dom, $options );
		// Remove cite if present.
		$content = preg_replace( '/<cite[^>]*>.*?<\/cite>/is', '', $content );
		$content = trim( $content );
		if ( ! empty( $content ) ) {
			$inner_blocks[] = create_paragraph_block( $content );
		}
	}

	// Build the quote HTML.
	$inner_content = [ '<blockquote class="wp-block-quote">' ];

	foreach ( $inner_blocks as $block ) {
		$inner_content[] = null; // Placeholder for inner block.
	}

	if ( ! empty( $citation ) ) {
		$cite_html = '<cite>' . $citation . '</cite>';
		$inner_content[] = $cite_html . '</blockquote>';
	} else {
		$inner_content[] = '</blockquote>';
	}

	return [
		'blockName'    => 'core/quote',
		'attrs'        => [],
		'innerBlocks'  => $inner_blocks,
		'innerHTML'    => '',
		'innerContent' => $inner_content,
	];
}

/**
 * Convert an image element to an image block.
 *
 * @param \DOMNode $node Image element.
 * @return array Image block.
 */
function convert_image_element( \DOMNode $node ) : array {
	$src = $node->getAttribute( 'src' );
	$alt = $node->getAttribute( 'alt' );
	$width = $node->getAttribute( 'width' );
	$height = $node->getAttribute( 'height' );

	$attrs = [];
	if ( ! empty( $width ) ) {
		$attrs['width'] = (int) $width;
	}
	if ( ! empty( $height ) ) {
		$attrs['height'] = (int) $height;
	}

	$img_html = sprintf(
		'<img src="%s" alt="%s"%s%s/>',
		esc_url( $src ),
		esc_attr( $alt ),
		! empty( $width ) ? ' width="' . (int) $width . '"' : '',
		! empty( $height ) ? ' height="' . (int) $height . '"' : ''
	);

	$figure_html = '<figure class="wp-block-image">' . $img_html . '</figure>';

	return [
		'blockName'    => 'core/image',
		'attrs'        => $attrs,
		'innerBlocks'  => [],
		'innerHTML'    => $figure_html,
		'innerContent' => [ $figure_html ],
	];
}

/**
 * Convert a figure element to an appropriate block.
 *
 * @param \DOMNode     $node    Figure element.
 * @param \DOMDocument $dom     Parent document.
 * @param array        $options Processing options.
 * @return array|null Block or null if not convertible.
 */
function convert_figure_element( \DOMNode $node, \DOMDocument $dom, array $options ) {
	// Check if figure contains an image.
	$img = $node->getElementsByTagName( 'img' )->item( 0 );
	if ( $img ) {
		$block = convert_image_element( $img );

		// Check for figcaption.
		$figcaption = $node->getElementsByTagName( 'figcaption' )->item( 0 );
		if ( $figcaption ) {
			$caption = get_node_inner_html( $figcaption, $dom, $options );
			// Add caption to the figure HTML.
			$block['innerHTML'] = str_replace(
				'</figure>',
				'<figcaption>' . $caption . '</figcaption></figure>',
				$block['innerHTML']
			);
			$block['innerContent'] = [ $block['innerHTML'] ];
		}

		return $block;
	}

	// Check for iframe (embedded content).
	$iframe = $node->getElementsByTagName( 'iframe' )->item( 0 );
	if ( $iframe ) {
		return convert_iframe_element( $iframe );
	}

	return null;
}

/**
 * Convert a table element to a table block.
 *
 * @param \DOMNode     $node    Table element.
 * @param \DOMDocument $dom     Parent document.
 * @param array        $options Processing options.
 * @return array Table block.
 */
function convert_table_element( \DOMNode $node, \DOMDocument $dom, array $options ) : array {
	// Wrap in figure for block structure.
	$figure_html = '<figure class="wp-block-table"><table>' . get_node_inner_html( $node, $dom, $options ) . '</table></figure>';

	return [
		'blockName'    => 'core/table',
		'attrs'        => [],
		'innerBlocks'  => [],
		'innerHTML'    => $figure_html,
		'innerContent' => [ $figure_html ],
	];
}

/**
 * Convert a preformatted/code element to a code block.
 *
 * @param \DOMNode     $node Pre element.
 * @param \DOMDocument $dom  Parent document.
 * @return array Code block.
 */
function convert_preformatted_element( \DOMNode $node, \DOMDocument $dom ) : array {
	$content = $node->textContent;

	return [
		'blockName'    => 'core/code',
		'attrs'        => [],
		'innerBlocks'  => [],
		'innerHTML'    => '<pre class="wp-block-code"><code>' . htmlspecialchars( $content, ENT_QUOTES, 'UTF-8' ) . '</code></pre>',
		'innerContent' => [ '<pre class="wp-block-code"><code>' . htmlspecialchars( $content, ENT_QUOTES, 'UTF-8' ) . '</code></pre>' ],
	];
}

/**
 * Convert a div element to appropriate block(s).
 *
 * @param \DOMNode     $node    Div element.
 * @param \DOMDocument $dom     Parent document.
 * @param array        $options Processing options.
 * @return array|null Block or null.
 */
function convert_div_element( \DOMNode $node, \DOMDocument $dom, array $options ) {
	// Check if div contains block-level elements.
	$has_block_children = false;
	foreach ( $node->childNodes as $child ) {
		if ( $child->nodeType === XML_ELEMENT_NODE ) {
			$tag = strtolower( $child->nodeName );
			if ( in_array( $tag, [ 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'blockquote', 'table', 'figure', 'div' ], true ) ) {
				$has_block_children = true;
				break;
			}
		}
	}

	// If div contains only inline content, treat as paragraph.
	if ( ! $has_block_children ) {
		$content = get_node_inner_html( $node, $dom, $options );
		$content = trim( $content );

		if ( $options['clean_empty_paragraphs'] && empty( trim( strip_tags( $content ) ) ) ) {
			return null;
		}

		if ( ! empty( $content ) ) {
			return create_paragraph_block( $content );
		}
	}

	// Divs with block-level children: recurse so their content is preserved,
	// wrapping the result in a group block.
	$inner_blocks = convert_child_nodes_to_blocks( $node, $dom, $options );

	if ( empty( $inner_blocks ) ) {
		return null;
	}

	return create_group_block( $inner_blocks );
}

/**
 * Convert an iframe element to an embed block.
 *
 * @param \DOMNode $node Iframe element.
 * @return array Embed or HTML block.
 */
function convert_iframe_element( \DOMNode $node ) : array {
	$src = $node->getAttribute( 'src' );

	// Try to detect embed type.
	$embed_data = detect_embeddable_html( '<iframe src="' . $src . '"></iframe>' );

	if ( $embed_data ) {
		$url = $embed_data['url'];
		$provider = get_embed_provider_name( $url );

		return create_embed_block( $url, $provider );
	}

	// Fallback: create an HTML block with the iframe.
	$owner = $node->ownerDocument;
	$iframe_html = $owner ? $owner->saveHTML( $node ) : '';

	return [
		'blockName'    => 'core/html',
		'attrs'        => [],
		'innerBlocks'  => [],
		'innerHTML'    => $iframe_html,
		'innerContent' => [ $iframe_html ],
	];
}

/**
 * Convert a DOM node to a Gutenberg block.
 *
 * @param \DOMNode     $node             DOM node to convert.
 * @param \DOMDocument $dom              Parent DOM document.
 * @param array        $options          Processing options.
 * @param array        $paragraph_buffer Reference to paragraph content buffer.
 * @param callable     $flush_paragraph  Callback to flush paragraph buffer.
 * @return array|null Block array or null if node should be buffered.
 */
function convert_dom_node_to_block( \DOMNode $node, \DOMDocument $dom, array $options, array &$paragraph_buffer, callable $flush_paragraph ) {
	// Handle text nodes.
	if ( $node->nodeType === XML_TEXT_NODE ) {
		$text = $node->textContent;
		// Only buffer non-whitespace text.
		if ( ! empty( trim( $text ) ) ) {
			$paragraph_buffer[] = htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
		}
		return null;
	}

	// Skip non-element nodes.
	if ( $node->nodeType !== XML_ELEMENT_NODE ) {
		return null;
	}

	$tag_name = strtolower( $node->nodeName );

	// Handle block-level elements.
	switch ( $tag_name ) {
		case 'h1':
		case 'h2':
		case 'h3':
		case 'h4':
		case 'h5':
		case 'h6':
			return convert_heading_element( $node, $dom, $options );

		case 'p':
			return convert_paragraph_element( $node, $dom, $options );

		case 'ul':
		case 'ol':
			return convert_list_element( $node, $dom, $tag_name, $options );

		case 'blockquote':
			return convert_blockquote_element( $node, $dom, $options );

		case 'img':
			return convert_image_element( $node );

		case 'figure':
			return convert_figure_element( $node, $dom, $options );

		case 'hr':
			return create_separator_block();

		case 'table':
			return convert_table_element( $node, $dom, $options );

		case 'pre':
			return convert_preformatted_element( $node, $dom );

		case 'div':
			// Divs may contain block-level content or act as paragraphs.
			return convert_div_element( $node, $dom, $options );

		case 'iframe':
			return convert_iframe_element( $node );

		// Inline elements - buffer for paragraph.
		case 'span':
		case 'a':
		case 'strong':
		case 'b':
		case 'em':
		case 'i':
		case 'u':
		case 'mark':
		case 'code':
		case 'sub':
		case 'sup':
			$paragraph_buffer[] = get_node_outer_html( $node, $dom, $options );
			return null;

		case 'br':
			$paragraph_buffer[] = '<br>';
			return null;

		default:
			// Unknown elements: try to extract content as paragraph.
			$inner_html = get_node_inner_html( $node, $dom, $options );
			if ( ! empty( trim( strip_tags( $inner_html ) ) ) ) {
				$paragraph_buffer[] = $inner_html;
			}
			return null;
	}
}

/**
 * Pre-process HTML to clean up common issues.
 *
 * Uses WordPress's wpautop() to convert double newlines to proper paragraph
 * tags, ensuring correct paragraph splitting in the block converter.
 *
 * @param string $html    HTML to pre-process.
 * @param array  $options Processing options.
 * @return string Cleaned HTML.
 */
function preprocess_html( string $html, array $options ) : string {
	// Remove font-weight: 400 span wrappers (common in TinyMCE content).
	if ( $options['clean_font_weight_spans'] ) {
		$html = preg_replace(
			'/<span[^>]*style="[^"]*font-weight:\s*400[^"]*"[^>]*>(.*?)<\/span>/is',
			'$1',
			$html
		);
	}

	// Remove empty span tags.
	$html = preg_replace( '/<span[^>]*>\s*<\/span>/i', '', $html );

	// Normalize line breaks.
	$html = str_replace( [ "\r\n", "\r" ], "\n", $html );

	// Use WordPress's wpautop() to convert double newlines to <p> tags.
	// This handles paragraph splitting correctly, including edge cases.
	$html = wpautop( $html );

	return $html;
}

/**
 * Convert HTML content to Gutenberg blocks.
 *
 * Parses structured HTML (headings, paragraphs, lists, images, etc.) and
 * creates corresponding block markup.
 *
 * Supported HTML elements:
 * - Headings (h1-h6) → core/heading
 * - Paragraphs (p, div with text) → core/paragraph
 * - Lists (ul, ol) → core/list
 * - Blockquotes (blockquote) → core/quote
 * - Images (img) → core/image
 * - Horizontal rules (hr) → core/separator
 * - Tables (table) → core/table
 * - Preformatted text (pre, code blocks) → core/code
 * - Iframes → core/embed or core/html
 *
 * Inline elements (span, a, strong, em, etc.) are preserved within their
 * parent block elements.
 *
 * @param string $html    Raw HTML content to convert.
 * @param array  $options Optional configuration options:
 *                        - 'clean_font_weight_spans': Remove <span style="font-weight: 400"> wrappers (default: true).
 *                        - 'clean_empty_paragraphs': Remove paragraphs with only whitespace (default: true).
 *                        - 'preserve_inline_styles': Keep inline style attributes (default: false).
 * @return array Array of parsed block arrays ready for serialize_blocks().
 */
function convert_html_to_blocks( string $html, array $options = [] ) : array {
	$defaults = [
		'clean_font_weight_spans' => true,
		'clean_empty_paragraphs'  => true,
		'preserve_inline_styles'  => false,
	];
	$options = array_merge( $defaults, $options );

	// Pre-process HTML to clean up common issues.
	$html = preprocess_html( $html, $options );

	// Use DOMDocument to parse HTML with proper error handling.
	$dom = new \DOMDocument( '1.0', 'UTF-8' );

	$previous_error_state = libxml_use_internal_errors( true );
	libxml_clear_errors();

	try {
		// Wrap in container to preserve encoding and ensure valid parsing.
		$wrapped_html = '<?xml encoding="UTF-8"><div id="classic-html-wrapper">' . $html . '</div>';
		$dom->loadHTML( $wrapped_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_error_state );
	}

	$wrapper = $dom->getElementById( 'classic-html-wrapper' );
	if ( ! $wrapper ) {
		// Fallback: return as single freeform block.
		return [ create_freeform_block( $html ) ];
	}

	return convert_child_nodes_to_blocks( $wrapper, $dom, $options );
}

/**
 * Convert the child nodes of an element into a flat list of blocks.
 *
 * Inline content between block-level children is accumulated and flushed as
 * paragraph blocks, preserving document order. Used for the top-level document
 * and, recursively, for block-containing <div> wrappers.
 *
 * @param \DOMNode     $parent  Element whose children are converted.
 * @param \DOMDocument $dom     Parent document.
 * @param array        $options Processing options.
 * @return array List of block arrays.
 */
function convert_child_nodes_to_blocks( \DOMNode $parent, \DOMDocument $dom, array $options ) : array {
	$blocks = [];
	$paragraph_buffer = [];

	/**
	 * Flush accumulated inline content as a paragraph block.
	 */
	$flush_paragraph = function() use ( &$paragraph_buffer, &$blocks, $options ) {
		if ( empty( $paragraph_buffer ) ) {
			return;
		}

		$content = implode( '', $paragraph_buffer );
		$content = trim( $content );

		// Clean up whitespace.
		$content = preg_replace( '/\s+/', ' ', $content );
		$content = trim( $content );

		if ( ! empty( $content ) && ( ! $options['clean_empty_paragraphs'] || ! empty( trim( strip_tags( $content ) ) ) ) ) {
			$blocks[] = create_paragraph_block( $content );
		}

		$paragraph_buffer = [];
	};

	// Process child nodes.
	foreach ( $parent->childNodes as $node ) {
		$block = convert_dom_node_to_block( $node, $dom, $options, $paragraph_buffer, $flush_paragraph );

		if ( $block !== null ) {
			$flush_paragraph();
			$blocks[] = $block;
		}
	}

	// Flush any remaining paragraph content.
	$flush_paragraph();

	return $blocks;
}

/**
 * Parse content and convert freeform blocks to proper Gutenberg blocks.
 *
 * Handles mixed content where some parts are blocks and some are classic
 * TinyMCE HTML. Always parses with parse_blocks() first, then converts
 * any freeform (classic) blocks to proper Gutenberg blocks.
 *
 * @param string $content Post content (may be blocks, classic HTML, or mixed).
 * @return array Array of parsed and converted blocks.
 */
function parse_content_with_conversion( string $content ) : array {
	// Always parse first - this handles both pure classic and mixed content.
	$blocks = \parse_blocks( $content );

	// Process each block, converting freeform blocks to proper blocks.
	$converted_blocks = [];

	foreach ( $blocks as $block ) {
		if ( is_freeform_block( $block ) ) {
			// Extract the HTML content from the freeform block.
			$html = $block['innerHTML'] ?? '';

			if ( empty( trim( $html ) ) ) {
				continue;
			}

			// Convert the classic HTML to proper blocks.
			$new_blocks = convert_html_to_blocks( $html );

			// Merge the converted blocks.
			$converted_blocks = array_merge( $converted_blocks, $new_blocks );
		} else {
			// Keep recognized blocks as-is.
			$converted_blocks[] = $block;
		}
	}

	return $converted_blocks;
}

/**
 * Transform HTML blocks to embed blocks.
 *
 * Recursively processes blocks and converts HTML blocks to embed blocks where possible.
 * Optionally logs warnings for HTML blocks that cannot be converted.
 *
 * @param array         $blocks          Blocks to transform.
 * @param string        $post_identifier Post identifier for warning messages.
 * @param callable|null $logger          Optional logging callback for non-convertible blocks.
 *                                       Receives the warning message as a string.
 *                                       Example: [ 'WP_CLI', 'warning' ] or function($msg) { error_log($msg); }
 * @return array Transformed blocks.
 */
function transform_html_blocks( array $blocks, string $post_identifier = '', ?callable $logger = null ) : array {
	foreach ( $blocks as &$block ) {
		$block_name = $block['blockName'] ?? '';

		if ( $block_name === 'core/html' ) {
			$embed_block = convert_html_to_embed_block( $block );

			if ( $embed_block ) {
				// Successfully converted to embed block.
				$block = $embed_block;
			} elseif ( $logger !== null ) {
				// Could not convert - call logger if provided.
				$logger(
					sprintf(
						'HTML block in %s could not be converted to embed block. Please review manually.',
						$post_identifier
					)
				);
			}
		}

		// Recursively process inner blocks.
		if ( ! empty( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = transform_html_blocks( $block['innerBlocks'], $post_identifier, $logger );
		}
	}

	return $blocks;
}

/**
 * Serialize blocks with editor-compatible JSON encoding.
 *
 * WordPress's wp_json_encode() uses JSON_HEX_AMP which encodes & to \u0026.
 * However, the JavaScript block editor uses JSON.stringify() which keeps & as-is.
 * This causes block validation errors for content containing ampersands.
 *
 * This function converts \u0026 back to & to match the editor's behavior.
 *
 * @param array $blocks Array of blocks to serialize.
 * @return string Serialized block content.
 */
function serialize_blocks( array $blocks ) : string {
	$content = \serialize_blocks( $blocks );

	// Convert JSON-escaped ampersands back to literal ampersands.
	// This matches the behavior of the JavaScript block editor's JSON.stringify().
	// The \u0026 sequence only appears in JSON strings within block comments,
	// where a literal & is safe and expected.
	return str_replace( '\\u0026', '&', $content );
}
