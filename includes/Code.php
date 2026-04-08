<?php
/**
 * One-time numeric codes: generate, hash for storage, timing-safe verify.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck;

final class Code {

	public static function generate_plain(): string {
		$len = Config::code_length_digits();
		$max = (int) pow( 10, $len ) - 1;
		if ( $max < 1 ) {
			$max = 9;
			$len = 1;
		}

		try {
			$n = random_int( 0, $max );
		} catch ( \Throwable $e ) {
			$n = wp_rand( 0, min( $max, (int) getrandmax() ) );
		}

		return str_pad( (string) $n, $len, '0', STR_PAD_LEFT );
	}

	public static function hash_plain( string $plain ): string {
		$key = Config::secret_key();
		return hash_hmac( 'sha256', $plain, $key, false );
	}

	public static function verify_plain_against_hash( string $plain, string $stored_hash ): bool {
		if ( '' === $stored_hash || '' === $plain ) {
			return false;
		}
		$calc = self::hash_plain( $plain );
		return hash_equals( $stored_hash, $calc );
	}
}
