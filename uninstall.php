<?php
/**
 * Dual Check 2FA — uninstall cleanup.
 *
 * Runs when the plugin is deleted from WordPress (not on deactivate).
 *
 * @package DualCheck2FA
 */


if (!defined('ABSPATH')) {
	exit;
}

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

/**
 * Removes per-site data: DB table, options, uploads log directories.
 *
 * @global \wpdb $wpdb
 * @return void
 */
function dual_check_2fa_uninstall_site_data(): void {
	global $wpdb;

	$table = $wpdb->prefix . 'dual_check';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- uninstall DROP TABLE; prefix-based table name.
	$wpdb->query("DROP TABLE IF EXISTS `{$table}`");

	$events = $wpdb->prefix . 'dual_check_events';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- uninstall DROP TABLE; prefix-based table name.
	$wpdb->query("DROP TABLE IF EXISTS `{$events}`");

	$trusted = $wpdb->prefix . 'dual_check_trusted_devices';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- uninstall DROP TABLE; prefix-based table name.
	$wpdb->query("DROP TABLE IF EXISTS `{$trusted}`");

	delete_option('dual_check_2fa_settings');
	delete_option('dual_check_2fa_db_version');
	delete_option('dual_check_2fa_events_db_version');
	delete_option('dual_check_2fa_trusted_devices_db_version');

	wp_clear_scheduled_hook('dual_check_2fa_token_gc');

	if (function_exists('wp_upload_dir')) {
		$upload = wp_upload_dir();
		if (empty($upload['error']) && !empty($upload['basedir'])) {
			$base = trailingslashit((string) $upload['basedir']);
			dual_check_2fa_uninstall_delete_tree($base . 'dual-check-2fa');
		}
	}
}

/**
 * Best-effort recursive delete of a directory created under uploads.
 *
 * @param string $dir Absolute path.
 * @return void
 */
function dual_check_2fa_uninstall_delete_tree(string $dir): void {
	if ($dir === '' || !is_dir($dir)) {
		return;
	}

	$items = @scandir($dir);
	if ($items === false) {
		return;
	}

	foreach ($items as $item) {
		if ($item === '.' || $item === '..') {
			continue;
		}
		$path = $dir . DIRECTORY_SEPARATOR . $item;
		if (is_dir($path)) {
			dual_check_2fa_uninstall_delete_tree($path);
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink($path);
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	@rmdir($dir);
}

global $wpdb;

if (!is_multisite()) {
	dual_check_2fa_uninstall_site_data();
} else {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- core multisite blogs table; direct query needed in uninstall context.
	$dual_check_2fa_blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
	if (is_array($dual_check_2fa_blog_ids)) {
		foreach ($dual_check_2fa_blog_ids as $blog_id) {
			switch_to_blog((int) $blog_id);
			dual_check_2fa_uninstall_site_data();
			restore_current_blog();
		}
	}
}

// User meta is stored once per user (network-wide on multisite).
delete_metadata('user', 0, 'dual_check_2fa_email', '', true);
delete_metadata('user', 0, 'dual_check_2fa_exempt', '', true);
