<?php
/**
 * SendGrid HTTP API DSN for Symfony Mailer.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Email\Providers;

use WPDualCheck\Security\ProviderSecrets;

final class SendGridProvider {

	/**
	 * @return string Empty when no API key is configured.
	 */
	public static function build_dsn(): string {
		$api_key = ProviderSecrets::line( 'api_sendgrid_key', 'WP_DUAL_CHECK_SENDGRID_API_KEY' );
		if ( '' === $api_key ) {
			return '';
		}

		return 'sendgrid+api://' . rawurlencode( $api_key ) . '@default';
	}
}
