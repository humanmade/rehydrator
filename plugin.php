<?php
/**
 * Plugin Name: Rehydrator
 * Plugin URI: https://github.com/humanmade/rehydrator
 * Description: Pattern-based content transformation for WordPress block migrations. Provides a fluent API for loading patterns, resolving nested references, and applying targeted transformations.
 * Version: 1.1.0
 * Author: Human Made
 * Author URI: https://humanmade.com
 * License: GPL-2.0-or-later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: rehydrator
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package HM\Rehydrator
 */

namespace HM\Rehydrator;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load plugin files.
require_once __DIR__ . '/inc/blocks.php';
require_once __DIR__ . '/inc/content-parser.php';
require_once __DIR__ . '/inc/pattern-transformer.php';
require_once __DIR__ . '/inc/synced-patterns.php';
require_once __DIR__ . '/inc/class-template.php';
