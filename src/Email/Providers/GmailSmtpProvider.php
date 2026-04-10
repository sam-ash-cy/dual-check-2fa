<?php
/**
 * Gmail SMTP (app password) DSN for Symfony Mailer.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Email\Providers;

use WPDualCheck\Security\ProviderSecrets;

final class GmailSmtpProvider {

	/**
	 * @return string Empty when address or app password is missing or address is invalid.
	 */
	public static function build_dsn(): string {
		$gmail_address = ProviderSecrets::line( 'api_gmail_user', 'WP_DUAL_CHECK_GMAIL_ADDRESS' );
		$app_password  = ProviderSecrets::line( 'api_gmail_app_password', 'WP_DUAL_CHECK_GMAIL_APP_PASSWORD' );
		if ( '' === $gmail_address || ! is_email( $gmail_address ) || '' === $app_password ) {
			return '';
		}

		return sprintf(
			'gmail+smtp://%s:%s@default',
			rawurlencode( $gmail_address ),
			rawurlencode( $app_password )
		);
	}
}
