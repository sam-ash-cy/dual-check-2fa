<?php

namespace WP_DUAL_CHECK\auth;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Random codes for non-DB flows (backup codes, SMS, etc.).
 * Login email codes normally come from {@see \WP_DUAL_CHECK\db\add_dual_check_token()} (length from settings).
 */
final class Code_Generator {

	/**
	 * Numeric OTP (each digit 0–9). Does not touch the dual_check table.
	 */
	public static function numeric_digits(int $length): string {
		$length = max(4, min(12, $length));
		$digits  = '';
		for ($i = 0; $i < $length; $i++) {
			$digits .= (string) random_int(0, 9);
		}

		return $digits;
	}
}
