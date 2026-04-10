<?php
/**
 * Optional debug logging: WP_DEBUG and/or WP Dual Check → Debug logging.
 * Context is redacted (no codes, tokens, or DSNs).
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Core;

use WPDualCheck\Admin\Settings\SettingsRepository;

/**
 * Structured JSON lines to PHP error_log when debug logging is enabled.
 */
final class Logger {

	/**
	 * True when WP_DEBUG is on or the plugin “Debug logging” option is enabled.
	 */
	private static function enabled(): bool {
		$wp_debug = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$opt_in   = ! empty( SettingsRepository::merged()['debug_logging'] );

		return $wp_debug || $opt_in;
	}

	/**
	 * Removes or masks values that should not appear in logs.
	 *
	 * @param array<string, mixed> $context Raw context from callers.
	 * @return array<string, mixed>
	 */
	private static function redact_context( array $context ): array {
		$sensitive_substrings = array( 'token', 'code', 'dsn', 'secret', 'password', 'key', 'auth' );
		$redacted             = array();
		foreach ( $context as $context_key => $context_value ) {
			$key_lower = strtolower( (string) $context_key );
			$should_redact = false;
			foreach ( $sensitive_substrings as $fragment ) {
				if ( str_contains( $key_lower, $fragment ) ) {
					$should_redact = true;
					break;
				}
			}
			if ( $should_redact ) {
				$redacted[ $context_key ] = '[redacted]';
				continue;
			}
			if ( is_string( $context_value ) ) {
				$redacted[ $context_key ] = preg_replace( '/\b\d{4,12}\b/', '[code]', $context_value );
			} else {
				$redacted[ $context_key ] = $context_value;
			}
		}
		return $redacted;
	}

	/**
	 * Log one JSON line to the PHP error log.
	 *
	 * @param string               $message Event name or short description.
	 * @param array<string, mixed> $context Arbitrary context; sensitive keys and digit runs are redacted.
	 * @return bool True if logging ran and error_log accepted the line.
	 */
	public static function log( string $message, array $context = array() ): bool {
		if ( ! self::enabled() ) {
			return false;
		}

		$line = wp_json_encode(
			array(
				'plugin'  => 'wp-dual-check',
				'message' => $message,
				'context' => self::redact_context( $context ),
			)
		);

		if ( false === $line ) {
			return false;
		}

		return false !== error_log( $line );
	}
}
