<?php

namespace WP_DUAL_CHECK\core;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Static facts about this plugin (keep in sync with wp-dual-check.php header).
 */
final class Plugin {

	public const VERSION = '1.0.0';

	/**
	 * Absolute filesystem path under the plugin root.
	 *
	 * @param string $relative Path relative to the plugin directory (use forward slashes).
	 * @return string
	 */
	public static function path(string $relative = ''): string {
		return WP_DUAL_CHECK_PATH . ltrim(str_replace('\\', '/', $relative), '/');
	}

	/**
	 * Absolute path to the main plugin file (bootstrap).
	 *
	 * @return string
	 */
	public static function file(): string {
		return WP_DUAL_CHECK_FILE;
	}
}
