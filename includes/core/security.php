<?php

namespace WP_DUAL_CHECK\core;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Small shared helpers for input handling and capability checks.
 */
final class Security {

	/**
	 * Sanitise the code from the request.
	 *
	 * @param string $post_key `$_POST` key holding the submitted code.
	 * @return string Trimmed text field, or empty string if missing/invalid.
	 */
	public static function sanitise_code_from_request(string $post_key): string {
		if (!isset($_POST[$post_key])) {
			return '';
		}
		$raw = wp_unslash($_POST[$post_key]);
		if (!is_string($raw)) {
			return '';
		}

		return trim(sanitize_text_field($raw));
	}

	/**
	 * Whether the current user may change plugin settings (WP admin).
	 *
	 * @return bool
	 */
	public static function current_user_can_manage_plugin(): bool {
		return current_user_can('manage_options');
	}
}
