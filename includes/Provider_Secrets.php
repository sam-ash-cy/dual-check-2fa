<?php
/**
 * API keys / secrets: wp-config or env first, then WP Dual Check settings (empty password field = keep previous).
 *
 * @package WPDualCheck
 */

namespace WPDualCheck;

final class Provider_Secrets {

	/**
	 * @param non-empty-string ...$env_names Tried in order (e.g. new name, legacy).
	 */
	public static function line( string $option_key, string ...$env_names ): string {
		foreach ( $env_names as $name ) {
			$v = Config::get_secret_string( $name, '' );
			if ( '' !== $v ) {
				return $v;
			}
		}
		$m = Admin_Settings::merged();

		return isset( $m[ $option_key ] ) ? trim( (string) $m[ $option_key ] ) : '';
	}
}
