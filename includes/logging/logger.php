<?php

namespace DualCheck2FA\Logging;

use function DualCheck2FA\db\dual_check_settings;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * File debug log when “Debug logging” is enabled (General → Limits & debug).
 *
 * Login / 2FA events (grep the log for these `event` names):
 * - twofa_triggered — password OK, 2FA required, pipeline starting
 * - twofa_challenge_ready — code emailed, session stored, redirect to code step
 * - twofa_failed — second step failed; context includes `reason`: cooldown, issue_token,
 *   mail_send, session_invalid, invalid_nonce, empty_code, wrong_code, consume_failed, missing_user,
 *   code_step_locked
 * - login_success — full session established after correct code
 */
final class Logger {

	/**
	 * Appends one JSON line to the debug log when the option is enabled.
	 *
	 * @param string               $event   Short event key for grepping.
	 * @param array<string, mixed> $context Arbitrary structured fields.
	 * @return void
	 */
	public static function debug(string $event, array $context = array()): void {
		if ($event === 'login_success') {
			/**
			 * Fires for login activity persistence (mirrors other security events).
			 *
			 * @param string               $event   Event key.
			 * @param array<string, mixed> $context Context.
			 */
			do_action('dual_check_2fa_security_event', $event, $context);
		}

		if (empty(dual_check_settings()['debug_logging'])) {
			return;
		}

		$dir = self::log_directory();
		if ($dir === '') {
			return;
		}

		if (!is_dir($dir)) {
			wp_mkdir_p($dir);
		}

		$file = trailingslashit($dir) . 'debug.log';
		$line = gmdate('Y-m-d\TH:i:s\Z') . "\t" . $event;
		if ($context !== array()) {
			$line .= "\t" . wp_json_encode($context, JSON_UNESCAPED_SLASHES);
		}
		$line .= "\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
	}

	/**
	 * Writable directory for log files (under uploads).
	 *
	 * @return string Empty if uploads are unavailable.
	 */
	public static function log_directory(): string {
		if (!function_exists('wp_upload_dir')) {
			return '';
		}

		$upload = wp_upload_dir();
		if (!empty($upload['error']) || empty($upload['basedir'])) {
			return '';
		}

		return trailingslashit((string) $upload['basedir']) . 'dual-check-2fa/logs';
	}
}
