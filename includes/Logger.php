<?php
/**
 * Optional debug logging: WP_DEBUG and/or WP Dual Check → Debug logging.
 * Context is redacted (no codes, tokens, or DSNs).
 *
 * @package WPDualCheck
 */

namespace WPDualCheck;

final class Logger {

    /**
     * Check if logging is enabled.
     * 
     * @return bool True if logging is enabled, false otherwise.
     */
	private static function enabled(): bool {
		$wp_debug = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$opt_in   = ! empty( Admin_Settings::merged()['debug_logging'] );

		return $wp_debug || $opt_in;
	}

	/**
	 * Log one JSON line to the PHP error log.
	 *
	 * @param array<string, mixed> $context Arbitrary context; sensitive keys and 6-digit codes are redacted.
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
