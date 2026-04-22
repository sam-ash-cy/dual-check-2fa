<?php

namespace DualCheck2FA\auth;

use DualCheck2FA\admin\Settings_Page;
use DualCheck2FA\core\Request_Context;
use function DualCheck2FA\db\dual_check_settings;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * When IP binding is enabled: lock the code step after repeated wrong codes for the same IP + user,
 * and key the “new code” resend cooldown by IP + user instead of user only.
 */
final class Code_Step_Rate_Limit {

	private const COUNT_PREFIX = 'dc2fa_cslc_';

	private const LOCK_PREFIX = 'dc2fa_csll_';

	/**
	 * Whether IP + user binding (code-step lockout + resend cooldown key) is active.
	 *
	 * @return bool
	 */
	public static function is_binding_enabled(): bool {
		$settings = dual_check_settings();
		$on        = !empty($settings['code_step_ip_rate_limit_enabled']);

		/**
		 * Filters whether IP + user binding features apply (after reading the option).
		 *
		 * @param bool $on Stored setting.
		 */
		return (bool) apply_filters('dual_check_2fa_code_step_ip_binding_enabled', $on);
	}

	/**
	 * Stable opaque suffix for transients (IP + user when binding on, else user id).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	public static function rate_bucket_suffix(int $user_id): string {
		if ($user_id <= 0) {
			return '0';
		}
		if (!self::is_binding_enabled()) {
			return (string) $user_id;
		}

		return self::hash_bucket($user_id);
	}

	/**
	 * Seconds remaining on the code-step lockout for this IP + user (0 if none).
	 *
	 * @param int $user_id Account in the pending session.
	 * @return int
	 */
	public static function lock_seconds_remaining(int $user_id): int {
		if ($user_id <= 0 || !self::is_binding_enabled()) {
			return 0;
		}

		$hk    = self::hash_bucket($user_id);
		$until = get_transient(self::LOCK_PREFIX . $hk);
		if ($until === false || !is_numeric($until)) {
			return 0;
		}

		$until = (int) $until;
		$now   = time();
		if ($until <= 0) {
			delete_transient(self::LOCK_PREFIX . $hk);

			return 0;
		}
		if ($until <= $now) {
			delete_transient(self::LOCK_PREFIX . $hk);

			return 0;
		}

		return $until - $now;
	}

	/**
	 * Records one failed code verification; may set lockout transient when threshold is reached.
	 *
	 * @param int $user_id Account tied to the pending session.
	 * @return void
	 */
	public static function record_failed_verify(int $user_id): void {
		if ($user_id <= 0 || !self::is_binding_enabled()) {
			return;
		}

		$settings = dual_check_settings();
		$d        = Settings_Page::defaults();
		$max      = max(
			Settings_Page::CODE_STEP_IP_MAX_FAILS_MIN,
			min(
				Settings_Page::CODE_STEP_IP_MAX_FAILS_MAX,
				absint($settings['code_step_ip_max_fails'] ?? $d['code_step_ip_max_fails'])
			)
		);

		/**
		 * Filters how many wrong code submissions (per IP + user) trigger a lockout.
		 *
		 * @param int $max     Clamped value from settings.
		 * @param int $user_id User ID from the pending session.
		 */
		$max = (int) apply_filters('dual_check_2fa_code_step_ip_max_fails', $max, $user_id);
		if ($max < 1) {
			return;
		}

		$lockout = max(
			Settings_Page::CODE_STEP_IP_LOCKOUT_MIN,
			min(
				Settings_Page::CODE_STEP_IP_LOCKOUT_MAX,
				absint($settings['code_step_ip_lockout_seconds'] ?? $d['code_step_ip_lockout_seconds'])
			)
		);

		/**
		 * Filters lockout length in seconds after too many wrong codes (IP + user).
		 *
		 * @param int $lockout Clamped value from settings.
		 * @param int $user_id User ID from the pending session.
		 */
		$lockout = (int) apply_filters('dual_check_2fa_code_step_ip_lockout_seconds', $lockout, $user_id);
		if ($lockout < 1) {
			return;
		}

		$hk        = self::hash_bucket($user_id);
		$count_key = self::COUNT_PREFIX . $hk;
		$raw_n     = get_transient($count_key);
		$n         = is_numeric($raw_n) ? max(0, (int) $raw_n) : 0;
		$n++;
		set_transient($count_key, $n, $lockout + 120);

		if ($n >= $max) {
			delete_transient($count_key);
			$until = time() + $lockout;
			set_transient(self::LOCK_PREFIX . $hk, $until, $lockout + 120);
		}
	}

	/**
	 * Clears failure counters and lock for this IP + user after a successful code step.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public static function clear_counters(int $user_id): void {
		if ($user_id <= 0 || !self::is_binding_enabled()) {
			return;
		}

		$hk = self::hash_bucket($user_id);
		delete_transient(self::COUNT_PREFIX . $hk);
		delete_transient(self::LOCK_PREFIX . $hk);
	}

	/**
	 * @param int $user_id User ID.
	 * @return string 40 hex chars (fits transient option_name limits with prefix).
	 */
	private static function hash_bucket(int $user_id): string {
		$ip  = Request_Context::client_ip();
		$raw = $ip !== '' ? $ip : '0';

		return substr(hash_hmac('sha256', $raw . '|' . $user_id, wp_salt('nonce')), 0, 40);
	}
}
