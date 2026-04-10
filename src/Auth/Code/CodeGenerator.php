<?php
/**
 * One-time numeric codes: generate plaintext digit string.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Auth\Code;

use WPDualCheck\Core\Config;

/**
 * Builds a zero-padded numeric string suitable for email login challenges.
 */
final class CodeGenerator {

	/**
	 * Generates a random code with length from site settings (clamped 4–12 digits).
	 *
	 * Uses random_int when available; falls back to wp_rand on failure.
	 *
	 * @return string Digits only, left-padded with zeros to the configured length.
	 */
	public static function generate_plain(): string {
		$digit_count     = Config::code_length_digits();
		$max_numeric     = (int) pow( 10, $digit_count ) - 1;
		if ( $max_numeric < 1 ) {
			$max_numeric = 9;
			$digit_count = 1;
		}

		try {
			$random_value = random_int( 0, $max_numeric );
		} catch ( \Throwable $e ) {
			$random_value = wp_rand( 0, min( $max_numeric, (int) getrandmax() ) );
		}

		return str_pad( (string) $random_value, $digit_count, '0', STR_PAD_LEFT );
	}
}
