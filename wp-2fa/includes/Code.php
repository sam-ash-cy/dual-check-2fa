<?php
/**
 * One-time numeric codes: generate, hash for storage, timing-safe verify.
 *
 * @package WP2FA
 */

namespace WP2FA;

final class Code {

	public static function generate_plain(): string {
		// wp_rand is available early; 6 digits, avoid leading-zero loss by zero-padding.
		return str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
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
