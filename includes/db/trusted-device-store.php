<?php
/**
 * @package DualCheck2FA
 */

namespace DualCheck2FA\db;

if (!defined('ABSPATH')) {
	exit;
}

const DUAL_CHECK_2FA_TRUSTED_DEVICES_DB_SCHEMA_VERSION = '1.0.0';

/**
 * @global \wpdb $wpdb
 * @return string
 */
function get_trusted_devices_table_name(): string {
	global $wpdb;

	return $wpdb->prefix . 'dual_check_trusted_devices';
}

/**
 * @global \wpdb $wpdb
 */
$dual_check_trusted_devices_database_install = static function (): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = get_trusted_devices_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		token_hash varchar(64) NOT NULL,
		created_at datetime NOT NULL,
		last_used_at datetime NOT NULL,
		expires_at datetime NOT NULL,
		ip_address varchar(45) DEFAULT NULL,
		user_agent text,
		label varchar(191) NOT NULL DEFAULT '',
		PRIMARY KEY (id),
		UNIQUE KEY token_hash (token_hash),
		KEY user_id (user_id),
		KEY expires_at (expires_at)
	) {$charset_collate};";

	dbDelta($sql);

	update_option('dual_check_2fa_trusted_devices_db_version', DUAL_CHECK_2FA_TRUSTED_DEVICES_DB_SCHEMA_VERSION, false);
};

if (defined('DUAL_CHECK_2FA_FILE')) {
	register_activation_hook(DUAL_CHECK_2FA_FILE, $dual_check_trusted_devices_database_install);
}

add_action(
	'plugins_loaded',
	static function () use ($dual_check_trusted_devices_database_install): void {
		if (get_option('dual_check_2fa_trusted_devices_db_version', '') !== DUAL_CHECK_2FA_TRUSTED_DEVICES_DB_SCHEMA_VERSION) {
			$dual_check_trusted_devices_database_install();
		}
	},
	6
);
