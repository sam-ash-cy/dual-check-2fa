<?php
/**
 * Amazon SES API DSN for Symfony Mailer.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Email\Providers;

use WPDualCheck\Admin\Settings\SettingsRepository;
use WPDualCheck\Core\Config;
use WPDualCheck\Security\ProviderSecrets;

final class SesProvider {

	/**
	 * @return string Empty when access key or secret is missing.
	 */
	public static function build_dsn(): string {
		$access_key = ProviderSecrets::line( 'api_ses_access_key', 'WP_DUAL_CHECK_SES_ACCESS_KEY' );
		$secret_key = ProviderSecrets::line( 'api_ses_secret_key', 'WP_DUAL_CHECK_SES_SECRET_KEY' );
		if ( '' === $access_key || '' === $secret_key ) {
			return '';
		}
		$merged_settings = SettingsRepository::merged();
		$region          = isset( $merged_settings['api_ses_region'] ) ? trim( (string) $merged_settings['api_ses_region'] ) : 'us-east-1';
		$region          = preg_replace( '/[^a-z0-9\-]/i', '', $region );
		if ( '' === $region ) {
			$region = 'us-east-1';
		}
		$env_region = Config::get_secret_string( 'WP_DUAL_CHECK_SES_REGION', '' );
		if ( '' !== $env_region ) {
			$region = preg_replace( '/[^a-z0-9\-]/i', '', $env_region ) ?: $region;
		}

		return sprintf(
			'ses+api://%s:%s@default?region=%s',
			rawurlencode( $access_key ),
			rawurlencode( $secret_key ),
			rawurlencode( $region )
		);
	}
}
