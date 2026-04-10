<?php
/**
 * Secrets: wp-config constants, getenv(), optional .env. Other values from merged settings.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Core;

use Dotenv\Dotenv;
use WPDualCheck\Admin\Settings\SettingsRepository;

/**
 * Central access to environment-backed secrets and derived plugin runtime values.
 */
final class Config {

	/**
	 * Loads `.env` from the plugin root when the file exists and is readable.
	 */
	public static function maybe_load_dotenv(): void {
		$env_file_path = WP_DUAL_CHECK_PATH . '.env';
		if ( ! is_readable( $env_file_path ) ) {
			return;
		}
		Dotenv::createImmutable( WP_DUAL_CHECK_PATH )->safeLoad();
	}

	/**
	 * Sensitive string from env/constants only (not wp_options).
	 *
	 * @param string $name    Constant or environment variable name.
	 * @param string $default Returned when unset or non-string constant.
	 */
	public static function get_secret_string( string $name, string $default = '' ): string {
		if ( defined( $name ) ) {
			/** @var mixed $constant_value */
			$constant_value = constant( $name );
			return is_string( $constant_value ) ? $constant_value : $default;
		}
		$env_value = getenv( $name );
		if ( false !== $env_value && '' !== $env_value ) {
			return $env_value;
		}
		if ( isset( $_ENV[ $name ] ) && is_scalar( $_ENV[ $name ] ) && '' !== (string) $_ENV[ $name ] ) {
			return (string) $_ENV[ $name ];
		}
		if ( isset( $_SERVER[ $name ] ) && is_scalar( $_SERVER[ $name ] ) && '' !== (string) $_SERVER[ $name ] ) {
			return (string) $_SERVER[ $name ];
		}
		return $default;
	}

	/**
	 * Symfony Mailer DSN from env (prefers WP_DUAL_CHECK_MAILER_DSN, then legacy WP2FA_MAILER_DSN).
	 */
	public static function mailer_dsn(): string {
		$dsn = self::get_secret_string( 'WP_DUAL_CHECK_MAILER_DSN', '' );
		if ( '' !== $dsn ) {
			return $dsn;
		}
		return self::get_secret_string( 'WP2FA_MAILER_DSN', '' );
	}

	/**
	 * Optional explicit site secret (WP_DUAL_CHECK_SECRET or legacy WP2FA_SECRET), else derived from AUTH_KEY / salt.
	 *
	 * Reserved for plugin cryptography; login codes use password_hash separately.
	 */
	public static function secret_key(): string {
		$explicit_secret = self::get_secret_string( 'WP_DUAL_CHECK_SECRET', '' );
		if ( '' === $explicit_secret ) {
			$explicit_secret = self::get_secret_string( 'WP2FA_SECRET', '' );
		}
		if ( '' !== $explicit_secret ) {
			return $explicit_secret;
		}
		if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			return hash( 'sha256', (string) AUTH_KEY . '|wp_dual_check', true );
		}
		return hash( 'sha256', wp_salt( 'auth' ) . '|wp_dual_check', true );
	}

	/**
	 * Pending challenge + code lifetime in seconds (minimum 60).
	 */
	public static function code_ttl_seconds(): int {
		$ttl = (int) SettingsRepository::merged()['code_ttl'];
		return max( 60, $ttl );
	}

	/**
	 * Number of digits in generated login codes (clamped 4–12).
	 */
	public static function code_length_digits(): int {
		$length = (int) ( SettingsRepository::merged()['code_length'] ?? 6 );
		return max( 4, min( 12, $length ) );
	}

	/**
	 * Failed verification attempts before the session is invalidated.
	 */
	public static function max_attempts(): int {
		$attempts = (int) SettingsRepository::merged()['max_attempts'];
		return max( 1, $attempts );
	}

	/**
	 * Minimum seconds between “Resend code” for the same user id.
	 */
	public static function resend_cooldown_seconds(): int {
		$cooldown = (int) SettingsRepository::merged()['resend_cooldown'];
		return max( 15, $cooldown );
	}

	/**
	 * From address for outbound mail; falls back to site admin email.
	 */
	public static function from_email(): string {
		$candidate = (string) SettingsRepository::merged()['from_email'];
		if ( '' !== $candidate && is_email( $candidate ) ) {
			return $candidate;
		}
		return (string) get_option( 'admin_email' );
	}

	/**
	 * From display name; falls back to blog name.
	 */
	public static function from_name(): string {
		$name = (string) SettingsRepository::merged()['from_name'];
		if ( '' !== $name ) {
			return $name;
		}
		return (string) wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
	}
}
