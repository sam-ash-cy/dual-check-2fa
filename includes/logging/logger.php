<?php

namespace WP_DUAL_CHECK\Logging;

use function WP_DUAL_CHECK\db\dual_check_settings;

if (!defined('ABSPATH')) {
	exit;
}

/** File debug log when “Debug logging” is enabled in General settings. */
final class Logger {

	public static function debug(string $event, array $context = array()): void {
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

	/** Writable directory for log files (under uploads). */
	public static function log_directory(): string {
		if (!function_exists('wp_upload_dir')) {
			return '';
		}

		$upload = wp_upload_dir();
		if (!empty($upload['error']) || empty($upload['basedir'])) {
			return '';
		}

		return trailingslashit((string) $upload['basedir']) . 'wp-dual-check/logs';
	}
}
