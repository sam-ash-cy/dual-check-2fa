<?php
/**
 * Stores login codes with password_hash (PASSWORD_DEFAULT: bcrypt / Argon2 per PHP).
 * Verified with password_verify (built-in salt, adaptive cost, algorithm upgrades in PHP).
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Auth\Code;

/**
 * Hashing and verification for one-time login codes (never store plaintext in transients).
 */
final class CodeVerifier {

	/**
	 * Produces a password_hash suitable for storage alongside the pending session.
	 *
	 * @param string $plaintext_code User-entered digits as submitted (normalized upstream).
	 * @return string Non-empty bcrypt/Argon2 hash.
	 */
	public static function hash_plain( string $plaintext_code ): string {
		return password_hash( $plaintext_code, PASSWORD_DEFAULT );
	}

	/**
	 * Checks a submitted code against a stored hash from PendingSession.
	 *
	 * @param string $plaintext_code Submitted code (digits only recommended).
	 * @param string $stored_hash    Value previously returned by hash_plain().
	 * @return bool True when the code matches and the hash is valid for password_verify.
	 */
	public static function verify_plain_against_hash( string $plaintext_code, string $stored_hash ): bool {
		if ( '' === $stored_hash || '' === $plaintext_code ) {
			return false;
		}

		return password_verify( $plaintext_code, $stored_hash );
	}
}
