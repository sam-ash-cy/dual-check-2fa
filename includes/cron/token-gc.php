<?php

namespace DualCheck2FA\cron;

use DualCheck2FA\admin\Settings_Page;
use function DualCheck2FA\db\dual_check_settings;
use function DualCheck2FA\db\get_events_table_name;
use function DualCheck2FA\db\get_table_name;
use function DualCheck2FA\db\get_trusted_devices_table_name;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Daily maintenance: token table cleanup, expired trusted devices, login activity retention.
 */
final class Token_Gc {

	public const CRON_HOOK = 'dual_check_2fa_token_gc';

	/**
	 * @return void
	 */
	public static function register(): void {
		add_action('init', array(self::class, 'maybe_schedule'));
		add_action(self::CRON_HOOK, array(self::class, 'run'));
	}

	/**
	 * @return void
	 */
	public static function maybe_schedule(): void {
		if (!wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
		}
	}

	/**
	 * Clears the scheduled GC event (deactivation).
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled(self::CRON_HOOK);
		while ($timestamp) {
			wp_unschedule_event($timestamp, self::CRON_HOOK);
			$timestamp = wp_next_scheduled(self::CRON_HOOK);
		}
	}

	/**
	 * @return void
	 */
	public static function run(): void {
		$settings = dual_check_settings();
		$token_gc = (bool) apply_filters(
			'dual_check_2fa_token_gc_enabled',
			!empty($settings['token_gc_enabled'])
		);
		if ($token_gc) {
			self::run_token_table_gc();
		}

	global $wpdb;
	$trusted_table = get_trusted_devices_table_name();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from get_trusted_devices_table_name(); cron GC query on custom table.
	$wpdb->query("DELETE FROM `{$trusted_table}` WHERE expires_at < UTC_TIMESTAMP()");

	$activity_on = (bool) apply_filters(
		'dual_check_2fa_login_activity_enabled',
		!empty($settings['activity_enabled'])
	);
	if ($activity_on) {
		$days = (int) apply_filters(
			'dual_check_2fa_login_activity_retention_days',
			max(1, min(3650, (int) ($settings['activity_retention_days'] ?? 90)))
		);
		$events = get_events_table_name();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from get_events_table_name(); cron GC query on custom table.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$events}` WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				$days
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}
	}

	/**
	 * @return void
	 */
	private static function run_token_table_gc(): void {
		global $wpdb;

		$consumed_days = (int) apply_filters('dual_check_2fa_token_gc_consumed_days', 30);
		$expired_days  = (int) apply_filters('dual_check_2fa_token_gc_expired_days', 7);
		$keep_per_user = (int) apply_filters('dual_check_2fa_token_gc_keep_per_user', 20);
		$consumed_days = max(1, $consumed_days);
		$expired_days  = max(1, $expired_days);
		$keep_per_user = max(1, $keep_per_user);

		$table = get_table_name();

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from get_table_name(); GC query on custom table.
	$sql = $wpdb->prepare(
		"DELETE FROM `{$table}` WHERE id IN (
			SELECT id FROM (
				SELECT t.id
				FROM `{$table}` t
				WHERE (
					(t.consumed_at IS NOT NULL AND t.consumed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY))
					OR (t.expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY))
				)
				AND t.id NOT IN (
					SELECT id FROM (
						SELECT id FROM (
							SELECT id, ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY id DESC) AS rn
							FROM `{$table}`
						) sub WHERE sub.rn <= %d
					) inner_keep
				)
			) outer_sel
		)",
		$consumed_days,
		$expired_days,
		$keep_per_user
	);

	$wpdb->query($sql);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}
}
