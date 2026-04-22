<?php

namespace DualCheck2FA\admin;

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
 * Shared {@see add_settings_error()} / {@see settings_errors()} group for Dual Check 2FA screens.
 */
final class Settings_Notices {

	public const GROUP = 'dual_check_2fa';

	/**
	 * Queues an error notice for the next settings screen render.
	 *
	 * @param string $code    Stable notice code.
	 * @param string $message Human-readable message (escaped on output by core).
	 * @return void
	 */
	public static function error(string $code, string $message): void {
		add_settings_error(Settings_Notices::GROUP, $code, $message, 'error');
	}

	/**
	 * Queues a success notice for the next settings screen render.
	 *
	 * @param string $code    Stable notice code.
	 * @param string $message Human-readable message.
	 * @return void
	 */
	public static function success(string $code, string $message): void {
		add_settings_error(Settings_Notices::GROUP, $code, $message, 'success');
	}

	/**
	 * Prints queued notices for this plugin’s group.
	 *
	 * Core’s “Settings saved.” after {@see options.php} is registered under the
	 * {@see 'general'} group; this plugin’s custom notices use {@see Settings_Notices::GROUP}.
	 *
	 * @return void
	 */
	public static function render(): void {
		settings_errors('general');
		settings_errors(Settings_Notices::GROUP);
	}
}
