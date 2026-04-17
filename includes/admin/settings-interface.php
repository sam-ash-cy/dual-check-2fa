<?php

namespace WP_DUAL_CHECK\admin;

if (!defined('ABSPATH')) {
	exit;
}

/** Each class hooks itself into admin (menu, fields, etc.). */
interface Admin_Settings_Page {
	public function register(): void;
}

/** Shared {@see add_settings_error()} / {@see settings_errors()} group for WP Dual Check screens. */
final class Settings_Notices {

	public const GROUP = 'wp_dual_check';

	public static function error(string $code, string $message): void {
		add_settings_error(self::GROUP, $code, $message, 'error');
	}

	public static function success(string $code, string $message): void {
		add_settings_error(self::GROUP, $code, $message, 'success');
	}

	public static function render(): void {
		settings_errors(self::GROUP);
	}
}
