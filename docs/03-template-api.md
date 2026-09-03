---
layout: home
title: Template API
nav_order: 3
permalink: /template-api
---

# Template API

The `Template` class is the primary interface for pattern-based content transformation. It provides a fluent (chainable) API for loading a pattern, declaring transformations, and serializing the result.

```php
use HM\Rehydrator\Template;

$transformer = new Template( 'theme/template-article' );
```

The constructor takes a pattern slug. The pattern is not loaded immediately — it's resolved lazily when you call `get_content()` or `get_blocks()`, so all transformations can be registered before resolution begins.

---

## Text and attribute transformations

### `replace_text()`

Replace the text content of a specific block, preserving its HTML structure (tag name, attributes, classes).

```php
->replace_text(
    pattern_slug: 'theme/hero',
    block_type:   'core/heading',
    occurrence:   0,
    text:         $title
)
```

| Parameter | Type | Description |
|---|---|---|
| `$pattern_slug` | `string` | Slug of the pattern containing the target block |
| `$block_type` | `string` | Block type name (e.g. `'core/heading'`) |
| `$occurrence` | `int` | Zero-indexed position of the block within the pattern |
| `$text` | `string` | New text content |

---

### `replace_attributes()`

Merge new attributes into a specific block, preserving any existing attributes not included in `$attrs`.

```php
->replace_attributes(
    pattern_slug: 'theme/hero',
    block_type:   'core/image',
    occurrence:   0,
    attrs: [
        'id'  => $image_id,
        'url' => $image_url,
        'alt' => $alt_text,
    ]
)
```

| Parameter | Type | Description |
|---|---|---|
| `$pattern_slug` | `string` | Slug of the pattern containing the target block |
| `$block_type` | `string` | Block type name |
| `$occurrence` | `int` | Zero-indexed position of the block within the pattern |
| `$attrs` | `array` | Attributes to merge into the block |

---

### `replace_html()`

Replace the full `innerHTML` of a specific block. Use this when you need to control the complete HTML output, not just the text content.

```php
->replace_html(
    pattern_slug: 'theme/byline',
    block_type:   'core/paragraph',
    occurrence:   0,
    html:         '<p>By <a href="' . $author_url . '">' . $author_name . '</a></p>'
)
```

_Note that the replacement HTML is not escaped or sanitized - you must handle data sanitization yourself._ 

| Parameter | Type | Description |
|---|---|---|
| `$pattern_slug` | `string` | Slug of the pattern containing the target block |
| `$block_type` | `string` | Block type name |
| `$occurrence` | `int` | Zero-indexed position of the block within the pattern |
| `$html` | `string` | Complete replacement innerHTML |

---

### `search_replace()`

Search and replace a string within a specific block's innerHTML.

```php
->search_replace(
    pattern_slug: 'theme/hero',
    block_type:   'core/paragraph',
    occurrence:   0,
    search:       '{{placeholder}}',
    replace:      $value
)
```

| Parameter | Type | Description |
|---|---|---|
| `$pattern_slug` | `string` | Slug of the pattern containing the target block |
| `$block_type` | `string` | Block type name |
| `$occurrence` | `int` | Zero-indexed position of the block within the pattern |
| `$search` | `string` | String to search for |
| `$replace` | `string` | Replacement string |

---

## Classes

A block's classes live in two places that have to stay synchronized: the `className` attribute in the serialized JSON, and the `class` attribute of the block's HTML markup. These three methods provide an easy way to change block classes while ensuring these attributes stay properly aligned.

Classes may be given as a single string name, a space-separated list, or an array of either. Repeated calls against the same block modify a running list of classes rather than starting over from the ones declared in a pattern.

Calls apply in the order they are chained. If the same class is both added and removed, the last call naming it wins:

```php
->add_class( 'theme/hero', 'core/heading', 0, 'is-style-display' )
->remove_class( 'theme/hero', 'core/heading', 0, 'is-style-display' )
// Class will NOT be present on the rendered heading.
```

This matters when the two calls are separated by conditional logic and the class name comes from source data. It is the one place in this API where registration order is significant: every other transformation method applies in a fixed sequence regardless of how it was chained.

> [!NOTE]
> **Avoid removing generated classes for core blocks.** Blocks emit their own classes from `save()`, like `wp-block-heading`, `wp-block-group`, and so on. Removing one produces content which will fail in-editor validation. To avoid these errors, only adjust a block's additional or stylistic classes.

### `add_class()`

```php
->add_class(
    pattern_slug: 'theme/hero',
    block_type:   'core/heading',
    occurrence:   0,
    classes:      'is-style-display'
)
```

| Parameter | Type | Description |
|---|---|---|
| `$pattern_slug` | `string` | Slug of the pattern containing the target block |
| `$block_type` | `string` | Block type name |
| `$occurrence` | `int` | Zero-indexed position of the block within the pattern |
| `$classes` | `string\|string[]` | Classes to add |

Classes the block already has are left alone, so this is safe to apply across a pattern whose markup varies (or to omit mention of classes that you don't care about).

---

### `remove_class()`

```php
->remove_class( 'theme/hero', 'core/paragraph', occurrence: 0, classes: 'is-style-lede' )
```

Receives the same parameters as `add_class()`. Classes the block doesn't have are ignored, and `className` is dropped from the attributes once nothing is left in it. Generated classes the block needs (`wp-block-heading` and friends) are untouched unless named explicitly.

---

### `replace_class()`

```php
->replace_class(
    pattern_slug: 'theme/hero',
    block_type:   'core/heading',
    occurrence:   0,
    old_classes:  'is-style-default',
    new_classes:  'is-style-display'
)
```

| Parameter | Type | Description |
|---|---|---|
| `$pattern_slug` | `string` | Slug of the pattern containing the target block |
| `$block_type` | `string` | Block type name |
| `$occurrence` | `int` | Zero-indexed position of the block within the pattern |
| `$old_classes` | `string\|string[]` | Classes to remove |
| `$new_classes` | `string\|string[]` | Classes to add |

The new classes are added whether or not the old ones were present, so this reads as "make sure that when we're done, _this_ class is removed and _this other_ class is present".

---

## Custom transformations

### `transform_callback()`

Apply an arbitrary callback to every block of a given type within a pattern. The callback receives the block array and must return the modified block array.

```php
->transform_callback(
    pattern_slug: 'theme/stats',
    block_type:   'core/columns',
    callback:     function( array $block ) use ( $stats ) : array {
        // Modify $block['innerBlocks'] based on $stats...
        return $block;
    }
)
```

| Parameter | Type | Description |
|---|---|---|
| `$pattern_slug` | `string` | Slug of the pattern containing the target block |
| `$block_type` | `string` | Block type name |
| `$callback` | `callable` | Callback receiving the block array and returning a modified block array |

Unlike the targeted methods above, `transform_callback` does not take an `$occurrence` parameter — it applies to all blocks of that type within the pattern.

---

## Block removal

### `remove_block()`

Remove a specific block occurrence from the output.

```php
->remove_block(
    pattern_slug: 'theme/hero',
    block_type:   'core/paragraph',
    occurrence:   1
)
```

---

### `remove_if_empty()`

Remove a block only if the provided value is empty. A convenient shorthand for conditionally stripping optional fields.

```php
->remove_if_empty(
    pattern_slug: 'theme/hero',
    block_type:   'core/paragraph',
    occurrence:   1,
    value:        $subtitle  // Block is removed if $subtitle is empty
)
```

| Parameter | Type | Description |
|---|---|---|
| `$pattern_slug` | `string` | Slug of the pattern containing the target block |
| `$block_type` | `string` | Block type name |
| `$occurrence` | `int` | Zero-indexed position of the block |
| `$value` | `mixed` | If `empty()`, the block is removed |

---

### `remove_if()`

Remove a block based on an arbitrary condition callback.

```php
->remove_if(
    pattern_slug: 'theme/video',
    block_type:   'core/group',
    occurrence:   0,
    condition:    fn() => empty( $video_url )
)
```

| Parameter | Type | Description |
|---|---|---|
| `$pattern_slug` | `string` | Slug of the pattern containing the target block |
| `$block_type` | `string` | Block type name |
| `$occurrence` | `int` | Zero-indexed position of the block |
| `$condition` | `callable` | Callback returning `true` if the block should be removed |

---

## Content insertion

### `replace_placeholder()`

Replace a named placeholder block with an array of blocks. The placeholder is any block with `metadata.name` set to the given name.

In your pattern file:
```html
<!-- wp:paragraph {"metadata":{"name":"content-placeholder"}} -->
<p></p>
<!-- /wp:paragraph -->
```

In your migration script:
```php
->replace_placeholder(
    placeholder_name: 'content-placeholder',
    content_blocks:   $body_blocks
)
```

| Parameter | Type | Description |
|---|---|---|
| `$placeholder_name` | `string` | The `metadata.name` value of the placeholder block |
| `$content_blocks` | `array` | Array of block arrays to insert in place of the placeholder |

The placeholder block is replaced entirely by the provided blocks. If `$content_blocks` is an empty array, the placeholder is removed.

---

## Synced patterns

### `replace_with_synced_pattern()`

Convert a pattern reference in the template to a [synced pattern](https://developer.wordpress.org/news/2023/03/synced-patterns-the-new-reusable-blocks/) (formerly "reusable block") instead of resolving it inline.

Use this for sections that should stay shared and editable across multiple posts — sidebars, footer CTAs, resource sections, etc.

```php
->replace_with_synced_pattern(
    pattern_slug: 'theme/footer-cta',
    key:          'site-footer-cta',
    title:        'Footer CTA'
)
```

If a synced pattern with the given `key` already exists in the database, it's reused. If not, a new `wp_block` post is created from the pattern's content.

| Parameter | Type | Description |
|---|---|---|
| `$pattern_slug` | `string` | The pattern reference to convert (must appear as `wp:pattern` in the template) |
| `$key` | `string` | Unique identifier for this synced pattern instance — used for lookup on subsequent runs |
| `$title` | `string` | Display title shown in the editor's synced patterns list |

---

## Getting results

### `get_content()`

Apply all pending transformations and return the serialized block markup, ready to write to `post_content`.

```php
$content = $transformer->get_content();

if ( ! is_wp_error( $content ) ) {
    wp_update_post( [
        'ID'           => $post_id,
        'post_content' => $content,
    ] );
}
```

Returns `string` on success, or a `WP_Error` if the pattern could not be found.

---

### `get_blocks()`

Apply all pending transformations and return the block array instead of serialized markup. Useful for inspecting the output structure or applying further processing before serialization.

```php
$blocks = $transformer->get_blocks();
```

Returns `array`.

---

### `has_error()` and `get_error()`

Check for errors after processing, for example if the pattern slug wasn't found.

```php
if ( $transformer->has_error() ) {
    $error = $transformer->get_error(); // WP_Error
    WP_CLI::warning( $error->get_error_message() );
}
```

---

## Combining transformations

Multiple transformation methods can be chained on the same block. They are applied in the order they're registered:

```php
$transformer
    // Replace text and attributes on the same block in theme/hero
    ->replace_text( 'theme/hero', 'core/heading', occurrence: 0, text: $title )
    ->replace_attributes( 'theme/hero', 'core/image', occurrence: 0, attrs: [ 'url' => $image_url ] )
    // Conditionally clean up optional blocks
    ->remove_if_empty( 'theme/hero', 'core/paragraph', occurrence: 1, value: $subtitle )
    // Handle the body content
    ->replace_placeholder( 'content-placeholder', $body_blocks )
    // Keep the footer CTA as a shared synced pattern
    ->replace_with_synced_pattern( 'theme/footer-cta', key: 'site-footer-cta', title: 'Footer CTA' );
```

## Error handling

If the initial pattern slug isn't found in the WordPress pattern registry, all transformation methods still return `$this` safely. The error is stored and returned by `get_content()` as a `WP_Error`. Check `has_error()` after the call if you need to handle failures gracefully.
