<?php
/**
 * WP Dual Check — uninstall cleanup.
 *
 * Runs when the plugin is deleted from WordPress (not on deactivate).
 *
 * @package WP_Dual_Check
 */


if (!defined('ABSPATH')) {
	exit;
}

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

/**
 * Removes per-site data: DB table, options, uploads log directory.
 *
 * @global \wpdb $wpdb
 * @return void
 */
function wp_dual_check_uninstall_site_data(): void {
	global $wpdb;

	$table = $wpdb->prefix . 'dual_check';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from trusted prefix.
	$wpdb->query("DROP TABLE IF EXISTS `{$table}`");

	delete_option('wp_dual_check_settings');
	delete_option('wp_dual_check_db_version');

	if (function_exists('wp_upload_dir')) {
		$upload = wp_upload_dir();
		if (empty($upload['error']) && !empty($upload['basedir'])) {
			$log_root = trailingslashit((string) $upload['basedir']) . 'wp-dual-check';
			wp_dual_check_uninstall_delete_tree($log_root);
		}
	}
}

/**
 * Best-effort recursive delete of a directory created under uploads.
 *
 * @param string $dir Absolute path.
 * @return void
 */
function wp_dual_check_uninstall_delete_tree(string $dir): void {
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
			wp_dual_check_uninstall_delete_tree($path);
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
	wp_dual_check_uninstall_site_data();
} else {
	$blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
	if (is_array($blog_ids)) {
		foreach ($blog_ids as $blog_id) {
			switch_to_blog((int) $blog_id);
			wp_dual_check_uninstall_site_data();
			restore_current_blog();
		}
	}
}

// User meta is stored once per user (network-wide on multisite).
delete_metadata('user', 0, 'wp_dual_check_2fa_email', '', true);
