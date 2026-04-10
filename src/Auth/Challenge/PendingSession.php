<?php
/**
 * Pending login state in transients (opaque token in URL, hashed code only in DB).
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Auth\Challenge;

use WPDualCheck\Auth\Code\CodeGenerator;
use WPDualCheck\Auth\Code\CodeVerifier;
use WPDualCheck\Core\Config;

/**
 * Persists second-step login state until verification, expiry, or too many failures.
 */
final class PendingSession {

	private const PREFIX = 'wdc_p_';

	/**
	 * Creates a new pending session, stores it under an opaque token, and returns data for mailing.
	 *
	 * @param \WP_User $user        User who passed the password step.
	 * @param bool     $remember    Whether to use a persistent auth cookie after success.
	 * @param string   $redirect_to Validated redirect URL after login (may be empty).
	 * @return array{token:string,user_id:int,code_hash:string,attempts:int,remember:bool,redirect_to:string,plain_code:string}
	 */
	public static function start_pending_challenge( \WP_User $user, bool $remember, string $redirect_to ): array {
		$plaintext_code = CodeGenerator::generate_plain();
		$session_data   = array(
			'user_id'     => (int) $user->ID,
			'code_hash'   => CodeVerifier::hash_plain( $plaintext_code ),
			'attempts'    => 0,
			'remember'    => $remember,
			'redirect_to' => $redirect_to,
		);
		$opaque_token = wp_generate_password( 48, false, false );
		$transient_key = self::key_for_token( $opaque_token );
		set_transient( $transient_key, $session_data, Config::code_ttl_seconds() );
		return array_merge( array( 'token' => $opaque_token ), $session_data, array( 'plain_code' => $plaintext_code ) );
	}

	/**
	 * Loads session payload for a challenge URL token.
	 *
	 * @param string $token Raw token from the query string.
	 * @return array{user_id:int,code_hash:string,attempts:int,remember:bool,redirect_to:string}|null
	 */
	public static function get_by_token( string $token ): ?array {
		$sanitized_token = sanitize_text_field( $token );
		if ( '' === $sanitized_token ) {
			return null;
		}
		$transient_key = self::key_for_token( $sanitized_token );
		$stored        = get_transient( $transient_key );
		if ( ! is_array( $stored ) ) {
			return null;
		}
		return $stored;
	}

	/**
	 * Removes the pending session so the token cannot be reused.
	 *
	 * @param string $token Challenge token from URL or POST.
	 */
	public static function delete_by_token( string $token ): void {
		$sanitized_token = sanitize_text_field( $token );
		if ( '' === $sanitized_token ) {
			return;
		}
		delete_transient( self::key_for_token( $sanitized_token ) );
	}

	/**
	 * Refreshes session data (e.g. after a failed attempt or new code on resend).
	 *
	 * @param string               $token Challenge token.
	 * @param array{user_id:int,code_hash:string,attempts:int,remember:bool,redirect_to:string} $session_data Payload without the outer token key.
	 */
	public static function save( string $token, array $session_data ): void {
		set_transient( self::key_for_token( $token ), $session_data, Config::code_ttl_seconds() );
	}

	/**
	 * Whether the user may request another code yet (cooldown enforced separately).
	 */
	public static function is_resend_allowed( int $user_id ): bool {
		$cooldown_flag_key = self::PREFIX . 'resend_' . $user_id;
		return false === get_transient( $cooldown_flag_key );
	}

	/**
	 * Sets a short-lived flag so resend cannot be spammed.
	 *
	 * @param int $user_id  WordPress user ID tied to the pending session.
	 * @param int $seconds  TTL for the cooldown transient.
	 */
	public static function mark_resend( int $user_id, int $seconds = 60 ): void {
		set_transient( self::PREFIX . 'resend_' . $user_id, 1, $seconds );
	}

	/**
	 * Stable transient key derived from the opaque token (not reversible to token).
	 */
	private static function key_for_token( string $token ): string {
		return self::PREFIX . md5( $token );
	}
}
