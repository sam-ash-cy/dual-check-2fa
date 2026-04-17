<?php

namespace WP_DUAL_CHECK\admin;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Each class hooks itself into admin (menu, fields, etc.).
 */
interface Admin_Settings_Page {
	/**
	 * Registers WordPress admin hooks for this screen.
	 *
	 * @return void
	 */
	public function register(): void;
}

/**
 * Shared {@see add_settings_error()} / {@see settings_errors()} group for WP Dual Check screens.
 */
final class Settings_Notices {

	public const GROUP = 'wp_dual_check';

	/**
	 * Queues an error notice for the next settings screen render.
	 *
	 * @param string $code    Stable notice code.
	 * @param string $message Human-readable message (escaped on output by core).
	 * @return void
	 */
	public static function error(string $code, string $message): void {
		add_settings_error(self::GROUP, $code, $message, 'error');
	}

	/**
	 * Queues a success notice for the next settings screen render.
	 *
	 * @param string $code    Stable notice code.
	 * @param string $message Human-readable message.
	 * @return void
	 */
	public static function success(string $code, string $message): void {
		add_settings_error(self::GROUP, $code, $message, 'success');
	}

	/**
	 * Prints queued notices for this plugin’s group.
	 *
	 * Core’s “Settings saved.” after {@see options.php} is registered under the
	 * {@see 'general'} group; this plugin’s custom notices use {@see self::GROUP}.
	 *
	 * @return void
	 */
	public static function render(): void {
		settings_errors('general');
		settings_errors(self::GROUP);
	}
}
