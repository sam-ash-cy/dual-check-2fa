<?php
/**
 * Postmark HTTP API DSN for Symfony Mailer.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Email\Providers;

use WPDualCheck\Security\ProviderSecrets;

final class PostmarkProvider {

	/**
	 * @return string Empty when no server token is configured.
	 */
	public static function build_dsn(): string {
		$server_token = ProviderSecrets::line( 'api_postmark_token', 'WP_DUAL_CHECK_POSTMARK_TOKEN' );
		if ( '' === $server_token ) {
			return '';
		}

		return 'postmark+api://' . rawurlencode( $server_token ) . '@default';
	}
}
