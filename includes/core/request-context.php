<?php

namespace WP_DUAL_CHECK\core;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * HTTP context for the current request (login / code step).
 */
final class Request_Context {

	/**
	 * Best-effort client IP (may be empty behind some proxies unless filtered).
	 */
	public static function client_ip(): string {
		$ip = '';
		if (isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
			$ip = wp_unslash($_SERVER['REMOTE_ADDR']);
		}

		/**
		 * Filters the IP address stored for dual-check audit rows.
		 *
		 * @param string $ip Raw REMOTE_ADDR (or empty).
		 */
		$ip = (string) apply_filters('wp_dual_check_client_ip', $ip);

		if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
			return '';
		}

		return $ip;
	}

	public static function user_agent(): string {
		if (!isset($_SERVER['HTTP_USER_AGENT']) || !is_string($_SERVER['HTTP_USER_AGENT'])) {
			return '';
		}

		$ua = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']));

		return substr($ua, 0, 2000);
	}
}
