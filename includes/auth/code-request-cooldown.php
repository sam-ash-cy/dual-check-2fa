<?php

namespace WP_DUAL_CHECK\auth;

use function WP_DUAL_CHECK\db\dual_check_settings;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Limits how often a new login code can be requested (per user and per IP).
 */
final class Code_Request_Cooldown {

	private const TRANSIENT_USER = 'wpdc_cd_u_';

	private const TRANSIENT_IP = 'wpdc_cd_i_';

	public static function cooldown_seconds(): int {
		$s = (int) (dual_check_settings()['code_resend_cooldown_seconds'] ?? 30);

		return max(
			\WP_DUAL_CHECK\admin\Settings_Page::CODE_RESEND_COOLDOWN_MIN,
			min(\WP_DUAL_CHECK\admin\Settings_Page::CODE_RESEND_COOLDOWN_MAX, $s)
		);
	}

	/** Seconds until a new code may be sent; 0 if allowed now. */
	public static function seconds_remaining(int $user_id, string $ip): int {
		if ($user_id <= 0) {
			return 0;
		}

		$cd  = self::cooldown_seconds();
		$now = time();
		$max = 0;

		$tu = get_transient(self::TRANSIENT_USER . $user_id);
		if ($tu !== false && is_numeric($tu)) {
			$elapsed = $now - (int) $tu;
			if ($elapsed < $cd) {
				$max = max($max, $cd - $elapsed);
			}
		}

		if ($ip !== '') {
			$key = self::TRANSIENT_IP . md5($ip);
			$ti  = get_transient($key);
			if ($ti !== false && is_numeric($ti)) {
				$elapsed = $now - (int) $ti;
				if ($elapsed < $cd) {
					$max = max($max, $cd - $elapsed);
				}
			}
		}

		return $max;
	}

	/** Call after a login code email was sent successfully. */
	public static function mark_sent(int $user_id, string $ip): void {
		if ($user_id <= 0) {
			return;
		}

		$cd = self::cooldown_seconds();
		$t  = time();
		// Keep transient a bit longer than cooldown so we can read the stored timestamp.
		$ttl = $cd + 120;
		set_transient(self::TRANSIENT_USER . $user_id, $t, $ttl);
		if ($ip !== '') {
			set_transient(self::TRANSIENT_IP . md5($ip), $t, $ttl);
		}
	}
}
