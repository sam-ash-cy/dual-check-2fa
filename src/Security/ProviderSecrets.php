<?php
/**
 * API keys / secrets: wp-config or env first, then WP Dual Check settings (empty password field = keep previous).
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Security;

use WPDualCheck\Admin\Settings\SettingsRepository;
use WPDualCheck\Core\Config;

/**
 * Resolves a single credential line from environment or stored settings.
 */
final class ProviderSecrets {

	/**
	 * First non-empty env constant wins; otherwise returns trimmed option value.
	 *
	 * @param string            $option_key Key in merged plugin settings.
	 * @param non-empty-string ...$env_names Tried in order (e.g. new name, legacy).
	 */
	public static function line( string $option_key, string ...$env_names ): string {
		foreach ( $env_names as $env_name ) {
			$from_env = Config::get_secret_string( $env_name, '' );
			if ( '' !== $from_env ) {
				return $from_env;
			}
		}
		$merged_settings = SettingsRepository::merged();

		return isset( $merged_settings[ $option_key ] ) ? trim( (string) $merged_settings[ $option_key ] ) : '';
	}
}
