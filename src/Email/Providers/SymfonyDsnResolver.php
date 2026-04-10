<?php
/**
 * Maps transport id to Symfony Mailer DSN string.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Email\Providers;

use WPDualCheck\Core\Config;
use WPDualCheck\Email\MailTransport;

/**
 * Maps a transport id to a Symfony Mailer DSN string.
 */
final class SymfonyDsnResolver {

	/**
	 * Built-in DSN fragments that need no user configuration.
	 *
	 * @var array<string, string>
	 */
	private const EMBEDDED_DSN = array(
		MailTransport::TRANSPORT_PHP_MAIL => 'native://default',
		MailTransport::TRANSPORT_SENDMAIL => 'sendmail://default',
	);

	/**
	 * @param string $transport One of {@see MailTransport} id constants.
	 * @return string DSN or empty when credentials are missing.
	 */
	public static function resolve( string $transport ): string {
		if ( isset( self::EMBEDDED_DSN[ $transport ] ) ) {
			return self::EMBEDDED_DSN[ $transport ];
		}
		if ( MailTransport::TRANSPORT_DSN === $transport ) {
			return Config::mailer_dsn();
		}
		if ( MailTransport::TRANSPORT_SENDGRID_API === $transport ) {
			return SendGridProvider::build_dsn();
		}
		if ( MailTransport::TRANSPORT_MAILGUN_API === $transport ) {
			return MailgunProvider::build_dsn();
		}
		if ( MailTransport::TRANSPORT_SES_API === $transport ) {
			return SesProvider::build_dsn();
		}
		if ( MailTransport::TRANSPORT_POSTMARK_API === $transport ) {
			return PostmarkProvider::build_dsn();
		}
		if ( MailTransport::TRANSPORT_GMAIL_SMTP === $transport ) {
			return GmailSmtpProvider::build_dsn();
		}

		return '';
	}
}
