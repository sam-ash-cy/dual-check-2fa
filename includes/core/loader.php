<?php

namespace DualCheck2FA\core;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Require a file under the plugin root if it exists (avoids fatals during refactors).
 *
 * @param string $relative Path relative to the plugin directory.
 * @return void
 */
function require_plugin_file(string $relative): void {
	$path = Plugin::path($relative);
	if (is_readable($path)) {
		require_once $path;
	}
}
