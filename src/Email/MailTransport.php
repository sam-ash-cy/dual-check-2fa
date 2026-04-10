<?php
/**
 * Mail transport identifiers and human labels (no Symfony / sending).
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Email;

/**
 * Transport id constants, validation, and filterable choice list for UI.
 */
final class MailTransport {

	public const TRANSPORT_DSN          = 'dsn';
	public const TRANSPORT_WP_MAIL      = 'wp_mail';
	public const TRANSPORT_PHP_MAIL     = 'php_mail';
	public const TRANSPORT_SENDMAIL     = 'sendmail';
	public const TRANSPORT_SENDGRID_API = 'sendgrid_api';
	public const TRANSPORT_MAILGUN_API  = 'mailgun_api';
	public const TRANSPORT_SES_API      = 'ses_api';
	public const TRANSPORT_POSTMARK_API = 'postmark_api';
	public const TRANSPORT_GMAIL_SMTP   = 'gmail_smtp';

	public const USER_TRANSPORT_INHERIT = 'inherit';

	/**
	 * Transports that use HTTP APIs or Gmail SMTP bridge (not raw DSN-only).
	 *
	 * @return list<string>
	 */
	public static function api_transport_ids(): array {
		return array(
			self::TRANSPORT_SENDGRID_API,
			self::TRANSPORT_MAILGUN_API,
			self::TRANSPORT_SES_API,
			self::TRANSPORT_POSTMARK_API,
			self::TRANSPORT_GMAIL_SMTP,
		);
	}

	/**
	 * Whether the id refers to a built-in API-style transport.
	 */
	public static function is_api_transport( string $transport ): bool {
		return in_array( $transport, self::api_transport_ids(), true );
	}

	/**
	 * Human-readable labels keyed by transport id (filterable).
	 *
	 * @return array<string, string> value => label
	 */
	public static function transport_choices(): array {
		$choices = array(
			self::TRANSPORT_DSN          => __( 'DSN (Symfony — custom SMTP / Mailpit URL)', 'wp-dual-check' ),
			self::TRANSPORT_SENDGRID_API => __( 'SendGrid (HTTP API)', 'wp-dual-check' ),
			self::TRANSPORT_MAILGUN_API  => __( 'Mailgun (HTTP API)', 'wp-dual-check' ),
			self::TRANSPORT_SES_API      => __( 'Amazon SES (API)', 'wp-dual-check' ),
			self::TRANSPORT_POSTMARK_API => __( 'Postmark (HTTP API)', 'wp-dual-check' ),
			self::TRANSPORT_GMAIL_SMTP   => __( 'Gmail (Google SMTP + app password)', 'wp-dual-check' ),
			self::TRANSPORT_WP_MAIL      => __( 'WordPress wp_mail()', 'wp-dual-check' ),
			self::TRANSPORT_PHP_MAIL     => __( 'PHP mail() — server default', 'wp-dual-check' ),
			self::TRANSPORT_SENDMAIL     => __( 'Sendmail binary', 'wp-dual-check' ),
		);

		/**
		 * Mail transport methods for site default and per-user override.
		 *
		 * @param array<string, string> $choices value => human label.
		 */
		return apply_filters( 'wp_dual_check_mail_transport_choices', $choices );
	}

	/**
	 * All valid transport ids after filters.
	 *
	 * @return list<string>
	 */
	public static function valid_transport_ids(): array {
		return array_keys( self::transport_choices() );
	}

	/**
	 * Normalizes a submitted id to a known transport or returns fallback.
	 */
	public static function sanitize_transport_id( string $value, string $fallback ): string {
		$value = sanitize_key( $value );
		if ( in_array( $value, self::valid_transport_ids(), true ) ) {
			return $value;
		}
		return $fallback;
	}
}
