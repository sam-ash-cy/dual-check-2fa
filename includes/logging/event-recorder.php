<?php

namespace DualCheck2FA\logging;

use DualCheck2FA\admin\Settings_Page;
use DualCheck2FA\core\Request_Context;
use function DualCheck2FA\db\get_events_table_name;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Persists {@see 'dual_check_2fa_security_event'} to the login activity table.
 */
final class Event_Recorder {

	/**
	 * @return void
	 */
	public static function register(): void {
		add_action('dual_check_2fa_security_event', array(self::class, 'record'), 20, 2);
	}

	/**
	 * @param string               $event   Event key.
	 * @param array<string, mixed> $context Structured context.
	 * @return void
	 */
	public static function record(string $event, array $context): void {
		$opts = wp_parse_args(get_option(Settings_Page::OPTION_NAME, array()), Settings_Page::defaults());
		$on   = (bool) apply_filters(
			'dual_check_2fa_login_activity_enabled',
			!empty($opts['activity_enabled'])
		);
		if (!$on) {
			return;
		}

		/**
		 * Filters whether a single security event is written to login activity.
		 *
		 * @param bool                 $record  Whether to persist.
		 * @param string               $event   Event key.
		 * @param array<string, mixed> $context Context array.
		 */
		if (!(bool) apply_filters('dual_check_2fa_login_activity_record_event', true, $event, $context)) {
			return;
		}

		$user_id = 0;
		if (isset($context['user_id'])) {
			$user_id = absint($context['user_id']);
		}

		$reason = '';
		if (isset($context['reason']) && is_string($context['reason'])) {
			$reason = substr($context['reason'], 0, 64);
		}

		$ip = Request_Context::client_ip();
		if (isset($context['ip']) && is_string($context['ip']) && $context['ip'] !== '') {
			$ip = substr($context['ip'], 0, 45);
		}

		$ua = Request_Context::user_agent();
		if (isset($context['user_agent']) && is_string($context['user_agent']) && $context['user_agent'] !== '') {
			$ua = substr($context['user_agent'], 0, 2000);
		}

		$event = substr(sanitize_key($event), 0, 64);
		if ($event === '') {
			return;
		}

		global $wpdb;
		$table = get_events_table_name();
		$wpdb->insert(
			$table,
			array(
				'user_id'    => $user_id,
				'event'      => $event,
				'reason'     => $reason,
				'ip_address' => $ip,
				'user_agent' => $ua,
				'context'    => wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				'created_at' => current_time('mysql', true),
			),
			array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
		);
	}
}
