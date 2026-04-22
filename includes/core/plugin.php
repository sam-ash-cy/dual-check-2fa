<?php

namespace DualCheck2FA\core;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Static facts about this plugin (keep in sync with main plugin file header).
 */
final class Plugin {

	public const VERSION = '1.0.5';

	/**
	 * Absolute filesystem path under the plugin root.
	 *
	 * @param string $relative Path relative to the plugin directory (use forward slashes).
	 * @return string
	 */
	public static function path(string $relative = ''): string {
		return DUAL_CHECK_2FA_PATH . ltrim(str_replace('\\', '/', $relative), '/');
	}

	/**
	 * Absolute path to the main plugin file (bootstrap).
	 *
	 * @return string
	 */
	public static function file(): string {
		return DUAL_CHECK_2FA_FILE;
	}
}
