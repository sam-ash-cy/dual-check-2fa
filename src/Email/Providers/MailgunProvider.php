<?php
/**
 * Mailgun HTTP API DSN for Symfony Mailer.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Email\Providers;

use WPDualCheck\Admin\Settings\SettingsRepository;
use WPDualCheck\Core\Config;
use WPDualCheck\Security\ProviderSecrets;

final class MailgunProvider {

	/**
	 * @return string Empty when key or domain is missing.
	 */
	public static function build_dsn(): string {
		$api_key    = ProviderSecrets::line( 'api_mailgun_key', 'WP_DUAL_CHECK_MAILGUN_API_KEY' );
		$domain     = ProviderSecrets::line( 'api_mailgun_domain', 'WP_DUAL_CHECK_MAILGUN_DOMAIN' );
		if ( '' === $api_key || '' === $domain ) {
			return '';
		}
		$env_region = Config::get_secret_string( 'WP_DUAL_CHECK_MAILGUN_REGION', '' );
		if ( '' !== $env_region ) {
			$region = 'eu' === strtolower( trim( $env_region ) ) ? 'eu' : 'us';
		} else {
			$merged_settings = SettingsRepository::merged();
			$region_option   = isset( $merged_settings['api_mailgun_region'] ) ? strtolower( (string) $merged_settings['api_mailgun_region'] ) : 'us';
			$region          = 'eu' === $region_option ? 'eu' : 'us';
		}
		$dsn = sprintf(
			'mailgun+api://%s:%s@default',
			rawurlencode( $api_key ),
			rawurlencode( $domain )
		);
		if ( 'eu' === $region ) {
			$dsn .= '?region=eu';
		}

		return $dsn;
	}
}
