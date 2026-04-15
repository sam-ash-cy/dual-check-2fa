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

	public static function path(string $relative = ''): string {
		return WP_DUAL_CHECK_PATH . ltrim(str_replace('\\', '/', $relative), '/');
	}

	public static function file(): string {
		return WP_DUAL_CHECK_FILE;
	}
}
