<?php
/**
 * Uninstall handler — runs when the operator clicks "Delete" on the plugin
 * in the WordPress admin Plugins screen.
 *
 * Intentionally a no-op for now. The plugin's tables hold operator curation
 * work (canonical ingredients, approved offers, click history) that should
 * NOT be silently destroyed because a plugin file was deleted. Once Phase 2+
 * begins producing real data, this file should grow either:
 *
 *   1. A "drop all supcomp_* tables and options" implementation — only after
 *      we're confident the operator has a backup workflow, OR
 *
 *   2. A WP-CLI command (or admin tool) that explicitly tears down the
 *      schema, leaving uninstall.php as a true no-op forever.
 *
 * The decision is tracked alongside the rest in OPEN_QUESTIONS.md.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
