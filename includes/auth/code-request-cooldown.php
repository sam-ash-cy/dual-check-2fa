<?php

namespace WP_DUAL_CHECK\auth;

use function WP_DUAL_CHECK\db\dual_check_settings;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Limits how often a new login code can be requested (per user account).
 */
final class Code_Request_Cooldown {

	private const TRANSIENT_USER = 'wpdc_cd_u_';

	public static function cooldown_seconds(): int {
		$s = (int) (dual_check_settings()['code_resend_cooldown_seconds'] ?? 30);

		return max(
			\WP_DUAL_CHECK\admin\Settings_Page::CODE_RESEND_COOLDOWN_MIN,
			min(\WP_DUAL_CHECK\admin\Settings_Page::CODE_RESEND_COOLDOWN_MAX, $s)
		);
	}

	/** Seconds until a new code may be sent; 0 if allowed now. */
	public static function seconds_remaining(int $user_id): int {
		if ($user_id <= 0) {
			return 0;
		}

		$cd  = self::cooldown_seconds();
		$now = time();

		$tu = get_transient(self::TRANSIENT_USER . $user_id);
		if ($tu !== false && is_numeric($tu)) {
			$elapsed = $now - (int) $tu;
			if ($elapsed < $cd) {
				return $cd - $elapsed;
			}
		}

		return 0;
	}

	/** Call after a login code email was sent successfully. */
	public static function mark_sent(int $user_id): void {
		if ($user_id <= 0) {
			return;
		}

		$cd  = self::cooldown_seconds();
		$t   = time();
		$ttl = $cd + 120;
		set_transient(self::TRANSIENT_USER . $user_id, $t, $ttl);
	}
}
