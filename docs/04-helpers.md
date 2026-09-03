---
layout: home
title: Helper Namespaces
nav_order: 4
permalink: /helpers
---

# Helper Namespaces

In addition to the `Template` class, the plugin provides four namespaces with utilities for common migration tasks.

---

## Blocks

```php
use HM\Rehydrator\Blocks;
```

Functions for creating block arrays programmatically. These are designed for trusted content and apply `wp_kses` sanitization to paragraph content.

### `create_heading()`

```php
$block = Blocks\create_heading( content: 'My Heading', level: 2 );
```

Creates a `core/heading` block. Strips any existing heading tags from `$content` before wrapping.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$content` | `string` | — | Heading text (may include inline HTML) |
| `$level` | `int` | `2` | Heading level (1–6) |

---

### `create_paragraph()`

```php
$block = Blocks\create_paragraph( 'Some content here.' );
```

Creates a `core/paragraph` block. Content is sanitized with `wp_kses` allowing common inline elements (`a`, `strong`, `em`, `br`, `code`, etc.). The allowed tags can be filtered via `hm.rehydrator.paragraph_allowed_html`.

---

### `create_paragraphs()`

```php
$blocks = Blocks\create_paragraphs( $multi_paragraph_text );
```

Splits text on double line breaks or `</p><p>` boundaries and returns an array of `core/paragraph` blocks. Useful for converting multi-paragraph plain text or classic editor body content into separate paragraph blocks.

---

### `create_block()`

```php
$block = Blocks\create_block(
    block_name: 'theme/hero',
    attrs:      [ 'className' => 'is-featured' ],
    inner_html: '<div class="wp-block-theme-hero"></div>'
);
```

Creates a leaf block (no inner blocks). Use this for any block type that doesn't contain other blocks.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$block_name` | `string` | — | Block name (e.g. `'core/image'`) |
| `$attrs` | `array` | `[]` | Block attributes |
| `$inner_html` | `string` | `''` | Block's innerHTML |

---

### `create_wrapper_block()`

```php
$group = Blocks\create_wrapper_block(
    block_name:   'core/group',
    opening_html: '<div class="wp-block-group">',
    closing_html: '</div>',
    attrs:        [ 'className' => 'my-group' ],
    inner_blocks: $inner_blocks
);
```

Creates a container block with inner blocks. Handles the `innerContent` structure WordPress requires (opening HTML, `null` placeholders for each inner block, closing HTML).

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$block_name` | `string` | — | Block name |
| `$opening_html` | `string` | — | Opening wrapper HTML |
| `$closing_html` | `string` | — | Closing wrapper HTML |
| `$attrs` | `array` | `[]` | Block attributes |
| `$inner_blocks` | `array` | `[]` | Array of inner block arrays |

---

## Content_Parser

```php
use HM\Rehydrator\Content_Parser;
```

Functions for parsing and converting HTML content into block arrays. Designed for processing classic editor content, ACF HTML fields, and other legacy HTML during migration.

### `parse_content_with_conversion()`

The main entry point for handling mixed content. Detects whether the content is already in block format or is classic HTML, and converts it accordingly.

```php
$blocks = Content_Parser\parse_content_with_conversion( $old_post->post_content );
```

- If content already contains block markers (`<!-- wp:`), it's parsed as-is using `parse_blocks()`.
- If content is classic HTML, it's passed through `convert_html_to_blocks()`.
- Freeform blocks (HTML between block markers) are converted to proper blocks.

Returns an array of block arrays.

---

### `convert_html_to_blocks()`

Convert classic HTML content to an array of blocks. Handles a wide range of HTML elements:

| HTML element | Converted to |
|---|---|
| `h1`–`h6` | `core/heading` |
| `p` | `core/paragraph` |
| `ul`, `ol` | `core/list` |
| `blockquote` | `core/quote` |
| `img` | `core/image` |
| `figure` | `core/image` or `core/embed` |
| `table` | `core/table` |
| `pre` | `core/code` |
| YouTube/Vimeo `iframe` | `core/embed` |
| Other `iframe` | `core/html` |
| Unrecognised `div` | `core/paragraph` (inline content) or skipped |

```php
$blocks = Content_Parser\convert_html_to_blocks( $html );
```

---

### `content_has_blocks()`

Check whether a string of content already contains block markers.

```php
if ( Content_Parser\content_has_blocks( $post->post_content ) ) {
    $blocks = parse_blocks( $post->post_content );
} else {
    $blocks = Content_Parser\convert_html_to_blocks( $post->post_content );
}
```

---

### `is_freeform_block()`

Check whether a block array is a freeform (classic editor) block — either `core/freeform` or a nameless block produced by `parse_blocks()` from HTML between block markers.

```php
$blocks = array_filter(
    parse_blocks( $content ),
    fn( $block ) => ! Content_Parser\is_freeform_block( $block )
);
```

---

### `serialize_blocks()`

Serialize a block array to markup with editor-compatible JSON encoding. WordPress core's `serialize_blocks()` encodes `&` as `\u0026` (via `JSON_HEX_AMP`), which causes block validation errors in the editor. This wrapper function fixes that encoding.

```php
$markup = Content_Parser\serialize_blocks( $blocks );
```

Use this instead of `serialize_blocks()` when you need the output to pass block validation in the editor. The `Template::get_content()` method uses this automatically.

---

## Synced_Patterns

```php
use HM\Rehydrator\Synced_Patterns;
```

Functions for creating and referencing [synced patterns](https://developer.wordpress.org/news/2023/03/synced-patterns-the-new-reusable-blocks/) (stored as `wp_block` posts).

### `get_or_create()`

Find or create a synced pattern. On the first migration run, this creates a `wp_block` post from the registered pattern's content and stores a lookup key in post meta. On subsequent runs, it finds the existing post by that key.

```php
$synced_id = Synced_Patterns\get_or_create(
    key:          'site-footer-cta',
    pattern_slug: 'theme/footer-cta',
    title:        'Footer CTA'
);
```

| Parameter | Type | Description |
|---|---|---|
| `$key` | `string` | Unique identifier for this synced pattern. Use a stable, descriptive key — it's stored in post meta for future lookups. |
| `$pattern_slug` | `string` | The registered pattern to use as the initial content |
| `$title` | `string` | Display title shown in the editor |

Returns the `wp_block` post ID as `int`, or `false` on failure.

The same pattern can be used to create multiple distinct synced patterns with different keys — for example, different CTA variants for different post types.

---

### `create_block_reference()`

Create a `core/block` block array that references a synced pattern by its post ID.

```php
$block = Synced_Patterns\create_block_reference( $synced_id );
```

This is what the `Template::replace_with_synced_pattern()` method uses internally. Use it directly when you need to insert a synced pattern reference into a manually constructed block array.

---

## Pattern_Transformer

```php
use HM\Rehydrator\Pattern_Transformer;
```

Low-level functions for pattern loading and block tree manipulation. You won't typically need these directly — the `Template` class uses them internally — but they're available for custom workflows.

### `get_pattern_by_slug()`

Get the HTML markup for a registered pattern by its slug.

```php
$markup = Pattern_Transformer\get_pattern_by_slug( 'theme/hero' );
```

Returns the pattern's `content` string from `WP_Block_Patterns_Registry`, or an empty string if the pattern isn't registered.

---

### `resolve_and_tag_patterns()`

Recursively resolve `wp:pattern` block references into their constituent blocks, tagging each block with its origin pattern slug in `_source_pattern`. This is what enables the `Template` class to target blocks by pattern slug.

```php
$blocks    = parse_blocks( $markup );
$resolved  = Pattern_Transformer\resolve_and_tag_patterns( $blocks );
```

---

### `apply_pattern_transformations()`

Apply a transformations map to a resolved block tree. The map is structured as `pattern_slug → block_type → occurrence → transformation`.

```php
$transformations = [
    'theme/hero' => [
        'core/heading' => [
            0 => [ 'textContent' => 'New Title' ],
        ],
    ],
];

$transformed = Pattern_Transformer\apply_pattern_transformations( $resolved, $transformations );
```

---

### `add_block_class()`, `remove_block_class()`, `replace_block_class()`

Change the classes on a single block, keeping its `className` attribute and its markup in step.

```php
$block = Pattern_Transformer\add_block_class( $block, 'is-style-display' );
$block = Pattern_Transformer\remove_block_class( $block, [ 'is-style-lede', 'legacy' ] );
$block = Pattern_Transformer\replace_block_class( $block, 'is-style-default', 'is-style-display' );
```

Classes may be given as a single name, a space-separated list, or an array of either. The class is added to or removed from `attrs['className']` and from the `class` attribute of the first tag in the block's own markup — for a container block that's the wrapper, not its inner blocks. Blocks that render their own markup get the attribute update only, which is all WordPress needs to output the class.

These are the block-level counterparts to the `Template` class's `add_class()`, `remove_class()` and `replace_class()` methods. Use them when walking a block tree yourself — styling every heading in a body of imported content, for instance — rather than targeting a block by pattern and occurrence.

As with the `Template` methods, avoid removing required generated classes from a block (such as `wp-block-heading`): the block's `save()` still emits them, so content without them fails editor validation.

For a sequence of changes on one block, `apply_block_class_ops()` takes an ordered list and folds it into a single attribute write. (This is what the three functions above use internally.)

```php
$block = Pattern_Transformer\apply_block_class_ops( $block, [
    [ 'action' => 'add',    'classes' => 'is-style-display legacy' ],
    [ 'action' => 'remove', 'classes' => 'legacy' ],
] );
```

---

### `rebuild_inner_content()`

Rebuild the `innerContent` array for a block after its `innerBlocks` have been modified. WordPress block serialization requires `innerContent` to have `null` placeholders for each inner block interleaved with the wrapper HTML strings.

```php
$block = Pattern_Transformer\rebuild_inner_content( $block );
```

Use this when directly manipulating `innerBlocks` outside of the `Template` API.
