<?php
/**
 * Pending login state in transients (opaque token in URL, hashed code only in DB).
 *
 * @package WP2FA
 */

namespace WP2FA;

final class Pending_Session {

	private const PREFIX = 'wp2fa_p_';

	/**
	 * @return array{token:string,user_id:int,code_hash:string,attempts:int,remember:bool,redirect_to:string}|null
	 */
	public static function start_pending_challenge( \WP_User $user, bool $remember, string $redirect_to ): array {
		$plain = Code::generate_plain();
		$data  = array(
			'user_id'     => (int) $user->ID,
			'code_hash'   => Code::hash_plain( $plain ),
			'attempts'    => 0,
			'remember'    => $remember,
			'redirect_to' => $redirect_to,
		);
		$token = wp_generate_password( 48, false, false );
		$key   = self::key_for_token( $token );
		set_transient( $key, $data, Config::code_ttl_seconds() );
		return array_merge( array( 'token' => $token ), $data, array( 'plain_code' => $plain ) );
	}

	/**
	 * @return array{user_id:int,code_hash:string,attempts:int,remember:bool,redirect_to:string}|null
	 */
	public static function get_by_token( string $token ): ?array {
		$token = sanitize_text_field( $token );
		if ( '' === $token ) {
			return null;
		}
		$key  = self::key_for_token( $token );
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			return null;
		}
		return $data;
	}

	public static function delete_by_token( string $token ): void {
		$token = sanitize_text_field( $token );
		if ( '' === $token ) {
			return;
		}
		delete_transient( self::key_for_token( $token ) );
	}

	/**
	 * @param array{user_id:int,code_hash:string,attempts:int,remember:bool,redirect_to:string} $data
	 */
	public static function save( string $token, array $data ): void {
		set_transient( self::key_for_token( $token ), $data, Config::code_ttl_seconds() );
	}

	public static function is_resend_allowed( int $user_id ): bool {
		$key = self::PREFIX . 'resend_' . $user_id;
		return false === get_transient( $key );
	}

	public static function mark_resend( int $user_id, int $seconds = 60 ): void {
		set_transient( self::PREFIX . 'resend_' . $user_id, 1, $seconds );
	}

	private static function key_for_token( string $token ): string {
		return self::PREFIX . md5( $token );
	}
}
