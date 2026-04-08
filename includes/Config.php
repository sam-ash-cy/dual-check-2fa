<?php
/**
 * Secrets: wp-config constants, getenv(), optional .env. Everything else: WP Dual Check admin menu.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck;

use Dotenv\Dotenv;

final class Config {

	public static function maybe_load_dotenv(): void {
		$env_file = WP_DUAL_CHECK_PATH . '.env';
		if ( ! is_readable( $env_file ) ) {
			return;
		}
		Dotenv::createImmutable( WP_DUAL_CHECK_PATH )->safeLoad();
	}

	/**
	 * Sensitive string from env/constants only (not wp_options).
	 */
	public static function get_secret_string( string $name, string $default = '' ): string {
		if ( defined( $name ) ) {
			/** @var mixed $v */
			$v = constant( $name );
			return is_string( $v ) ? $v : $default;
		}
		$env = getenv( $name );
		if ( false !== $env && '' !== $env ) {
			return $env;
		}
		return $default;
	}

	public static function mailer_dsn(): string {
		$dsn = self::get_secret_string( 'WP_DUAL_CHECK_MAILER_DSN', '' );
		if ( '' !== $dsn ) {
			return $dsn;
		}
		return self::get_secret_string( 'WP2FA_MAILER_DSN', '' );
	}

	/**
	 * Optional explicit secret for code hashing (multi-server). Legacy WP2FA_SECRET still read if unset.
	 */
	public static function secret_key(): string {
		$explicit = self::get_secret_string( 'WP_DUAL_CHECK_SECRET', '' );
		if ( '' === $explicit ) {
			$explicit = self::get_secret_string( 'WP2FA_SECRET', '' );
		}
		if ( '' !== $explicit ) {
			return $explicit;
		}
		if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			return hash( 'sha256', (string) AUTH_KEY . '|wp_dual_check', true );
		}
		return hash( 'sha256', wp_salt( 'auth' ) . '|wp_dual_check', true );
	}

	public static function code_ttl_seconds(): int {
		$v = (int) Admin_Settings::merged()['code_ttl'];
		return max( 60, $v );
	}

	public static function max_attempts(): int {
		$v = (int) Admin_Settings::merged()['max_attempts'];
		return max( 1, $v );
	}

	public static function resend_cooldown_seconds(): int {
		$v = (int) Admin_Settings::merged()['resend_cooldown'];
		return max( 15, $v );
	}

	public static function from_email(): string {
		$addr = (string) Admin_Settings::merged()['from_email'];
		if ( '' !== $addr && is_email( $addr ) ) {
			return $addr;
		}
		return (string) get_option( 'admin_email' );
	}

	public static function from_name(): string {
		$name = (string) Admin_Settings::merged()['from_name'];
		if ( '' !== $name ) {
			return $name;
		}
		return (string) wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
	}

	public static function rest_enabled(): bool {
		return ! empty( Admin_Settings::merged()['rest_enabled'] );
	}
}
