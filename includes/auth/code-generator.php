<?php

namespace DualCheck2FA\auth;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Random codes for non-DB flows (backup codes, SMS, etc.).
 * Login email codes normally come from {@see \DualCheck2FA\db\add_dual_check_token()} (length from settings).
 */
final class Code_Generator {

	/**
	 * Numeric OTP (each digit 0–9). Does not touch the dual_check table.
	 *
	 * @param int $length Desired length (clamped between 4 and 12).
	 * @return string
	 */
	public static function numeric_digits(int $length): string {
		$length = max(4, min(12, abs((int) $length)));
		$digits  = '';
		for ($i = 0; $i < $length; $i++) {
			$digits .= (string) random_int(0, 9);
		}

		return $digits;
	}
}
