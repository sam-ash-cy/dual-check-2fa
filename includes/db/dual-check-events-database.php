<?php
/**
 * @package DualCheck2FA
 */

namespace DualCheck2FA\db;

if (!defined('ABSPATH')) {
	exit;
}

const DUAL_CHECK_2FA_EVENTS_DB_SCHEMA_VERSION = '1.0.0';

/**
 * Prefixed table name for login activity rows.
 *
 * @global \wpdb $wpdb
 * @return string
 */
function get_events_table_name(): string {
	global $wpdb;

	return $wpdb->prefix . 'dual_check_events';
}

/**
 * @global \wpdb $wpdb
 */
$dual_check_events_database_install = static function (): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = get_events_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		event varchar(64) NOT NULL DEFAULT '',
		reason varchar(64) NOT NULL DEFAULT '',
		ip_address varchar(45) DEFAULT NULL,
		user_agent text,
		context longtext,
		created_at datetime NOT NULL,
		PRIMARY KEY (id),
		KEY user_id (user_id),
		KEY event (event),
		KEY created_at (created_at)
	) {$charset_collate};";

	dbDelta($sql);

	update_option('dual_check_2fa_events_db_version', DUAL_CHECK_2FA_EVENTS_DB_SCHEMA_VERSION, false);
};

if (defined('DUAL_CHECK_2FA_FILE')) {
	register_activation_hook(DUAL_CHECK_2FA_FILE, $dual_check_events_database_install);
}

add_action(
	'plugins_loaded',
	static function () use ($dual_check_events_database_install): void {
		if (get_option('dual_check_2fa_events_db_version', '') !== DUAL_CHECK_2FA_EVENTS_DB_SCHEMA_VERSION) {
			$dual_check_events_database_install();
		}
	},
	6
);
