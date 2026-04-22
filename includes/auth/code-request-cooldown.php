<?php

namespace DualCheck2FA\auth;

use function DualCheck2FA\db\dual_check_settings;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Limits how often a new login code can be requested (per user account, or per IP + user when binding is on).
 */
final class Code_Request_Cooldown {

	private const TRANSIENT_USER = 'dc2fa_cd_u_';

	private const TRANSIENT_IP_USER = 'dc2fa_cd_iu_';

	/**
	 * Minimum seconds between new login codes for one user (from settings, clamped).
	 *
	 * @return int
	 */
	public static function cooldown_seconds(): int {
		$s = (int) (dual_check_settings()['code_resend_cooldown_seconds'] ?? 30);

		return max(
			\DualCheck2FA\admin\Settings_Page::CODE_RESEND_COOLDOWN_MIN,
			min(\DualCheck2FA\admin\Settings_Page::CODE_RESEND_COOLDOWN_MAX, $s)
		);
	}

	/**
	 * Seconds until a new code may be sent; 0 if allowed now.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int
	 */
	public static function seconds_remaining(int $user_id): int {
		if ($user_id <= 0 ) {
			return 0;
		}

		$cd  = self::cooldown_seconds();
		$now = time();

		$suffix = Code_Step_Rate_Limit::rate_bucket_suffix($user_id);
		$prefix = Code_Step_Rate_Limit::is_binding_enabled() ? self::TRANSIENT_IP_USER : self::TRANSIENT_USER;
		$key    = $prefix . $suffix;

		// Transient stores the Unix time the last code was sent; TTL is cooldown + slack so the key does not vanish mid-window.
		$tu = get_transient($key);
		if ($tu !== false && is_numeric($tu)) {
			$elapsed = $now - (int) $tu;
			if ($elapsed < $cd) {
				return $cd - $elapsed;
			}
		}

		return 0;
	}

	/**
	 * Records send time after a login code email was sent successfully.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public static function mark_sent(int $user_id): void {
		if ($user_id <= 0) {
			return;
		}

		$cd  = self::cooldown_seconds();
		$t   = time();
		$ttl = $cd + 120;
		$suffix = Code_Step_Rate_Limit::rate_bucket_suffix($user_id);
		$prefix = Code_Step_Rate_Limit::is_binding_enabled() ? self::TRANSIENT_IP_USER : self::TRANSIENT_USER;
		set_transient($prefix . $suffix, $t, $ttl);
	}
}
