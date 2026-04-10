<?php
/**
 * Login code email: Symfony Mailer (DSN, API providers, native/sendmail) or wp_mail().
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Email;

use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use WPDualCheck\Admin\Settings\SettingsRepository;
use WPDualCheck\Core\Config;
use WPDualCheck\Core\Logger;
use WPDualCheck\Email\Providers\SymfonyDsnResolver;
use WPDualCheck\Email\Templates\EmailTemplateManager;
use WPDualCheck\User\MetaKeys;

/**
 * Sends login-code and test messages via Symfony Mailer or wp_mail().
 */
final class Mailer {

	public const TRANSPORT_DSN          = MailTransport::TRANSPORT_DSN;
	public const TRANSPORT_WP_MAIL      = MailTransport::TRANSPORT_WP_MAIL;
	public const TRANSPORT_PHP_MAIL     = MailTransport::TRANSPORT_PHP_MAIL;
	public const TRANSPORT_SENDMAIL     = MailTransport::TRANSPORT_SENDMAIL;
	public const TRANSPORT_SENDGRID_API = MailTransport::TRANSPORT_SENDGRID_API;
	public const TRANSPORT_MAILGUN_API  = MailTransport::TRANSPORT_MAILGUN_API;
	public const TRANSPORT_SES_API      = MailTransport::TRANSPORT_SES_API;
	public const TRANSPORT_POSTMARK_API = MailTransport::TRANSPORT_POSTMARK_API;
	public const TRANSPORT_GMAIL_SMTP   = MailTransport::TRANSPORT_GMAIL_SMTP;

	public const USER_TRANSPORT_INHERIT = MailTransport::USER_TRANSPORT_INHERIT;

	/** @return list<string> */
	public static function api_transport_ids(): array {
		return MailTransport::api_transport_ids();
	}

	/** @see MailTransport::is_api_transport() */
	public static function is_api_transport( string $transport ): bool {
		return MailTransport::is_api_transport( $transport );
	}

	/** @return array<string, string> */
	public static function transport_choices(): array {
		return MailTransport::transport_choices();
	}

	/** @return list<string> */
	public static function valid_transport_ids(): array {
		return MailTransport::valid_transport_ids();
	}

	/** @see MailTransport::sanitize_transport_id() */
	public static function sanitize_transport_id( string $value, string $fallback ): string {
		return MailTransport::sanitize_transport_id( $value, $fallback );
	}

	/**
	 * Effective transport for the user: profile override or site default.
	 */
	public static function resolve_transport_for_user( \WP_User $user ): string {
		$transport_raw = (string) get_user_meta( $user->ID, MetaKeys::MAIL_TRANSPORT, true );
		if ( '' === $transport_raw ) {
			$transport_raw = (string) get_user_meta( $user->ID, 'wp2fa_mailer_transport', true );
		}
		$transport_raw = sanitize_key( $transport_raw );
		if ( '' === $transport_raw || self::USER_TRANSPORT_INHERIT === $transport_raw ) {
			$site_default = (string) SettingsRepository::merged()['default_mailer_transport'];

			return self::sanitize_transport_id( $site_default, self::TRANSPORT_DSN );
		}

		return self::sanitize_transport_id( $transport_raw, self::TRANSPORT_DSN );
	}

	/**
	 * Sends a non-code test message using the user’s resolved transport.
	 */
	public static function send_test_email( \WP_User $user ): \WP_Error|true {
		$to = self::resolve_recipient_email( $user );
		if ( '' === $to || ! is_email( $to ) ) {
			return new \WP_Error(
				'wdc_bad_email',
				__( 'Your account has no valid email address for mail.', 'wp-dual-check' )
			);
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[WP Dual Check] Test message (%s)', 'wp-dual-check' ),
			wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES )
		);
		$body = __( "This is a test email from WP Dual Check.\n\nIf you received it, mail delivery for your selected transport is working. No login code is included.", 'wp-dual-check' );

		$transport_id = self::resolve_transport_for_user( $user );

		return self::dispatch( $transport_id, $user, $to, $subject, $body, null, false, 'test' );
	}

	/**
	 * Builds templated login-code email and sends it.
	 *
	 * @param string $plain_code Digits only; never logged by Logger in normal paths.
	 */
	public static function send_code_email( \WP_User $user, string $plain_code ): \WP_Error|true {
		$to = self::resolve_recipient_email( $user );
		if ( '' === $to || ! is_email( $to ) ) {
			return new \WP_Error(
				'wdc_bad_email',
				__( 'Your account has no valid email address for the login code.', 'wp-dual-check' )
			);
		}

		$email_parts   = EmailTemplateManager::build_login_code_email( $user, $plain_code );
		$transport_id = self::resolve_transport_for_user( $user );

		return self::dispatch(
			$transport_id,
			$user,
			$to,
			$email_parts->subject,
			$email_parts->text,
			$email_parts->html,
			$email_parts->multipart,
			'login_code'
		);
	}

	/**
	 * Routes to wp_mail or Symfony based on transport; logs failures when debug is on.
	 *
	 * @param string $kind 'test' or 'login_code' for logging keys.
	 */
	private static function dispatch( string $transport, \WP_User $user, string $to, string $subject, string $body_text, ?string $body_html, bool $multipart, string $kind ): \WP_Error|true {
		if ( self::TRANSPORT_WP_MAIL === $transport ) {
			return self::send_via_wp_mail( $user, $to, $subject, $body_text, $body_html, $multipart, $kind );
		}

		$dsn = SymfonyDsnResolver::resolve( $transport );
		/**
		 * Override Symfony DSN for a transport (e.g. custom OAuth bridge).
		 *
		 * @param string     $dsn        Built DSN or empty.
		 * @param string     $transport  Transport id.
		 * @param \WP_User   $user       Recipient context.
		 */
		$dsn = apply_filters( 'wp_dual_check_symfony_dsn', $dsn, $transport, $user );
		$dsn = is_string( $dsn ) ? $dsn : '';

		if ( '' === $dsn ) {
			return new \WP_Error(
				'wdc_no_dsn',
				self::missing_config_message( $transport )
			);
		}

		try {
			$symfony = Transport::fromDsn( $dsn );
			$mailer  = new SymfonyMailer( $symfony );

			$email = ( new Email() )
				->from( new Address( Config::from_email(), Config::from_name() ) )
				->to( new Address( $to ) )
				->subject( $subject );

			if ( null !== $body_html && '' !== $body_html ) {
				if ( $multipart && '' !== $body_text ) {
					$email->text( $body_text );
					$email->html( $body_html );
				} else {
					$email->html( $body_html );
					if ( '' !== $body_text ) {
						$email->text( $body_text );
					}
				}
			} else {
				$email->text( $body_text );
			}

			$mailer->send( $email );
		} catch ( \Throwable $e ) {
			$key = 'test' === $kind ? 'test_mail_failed' : 'login_code_mail_failed';
			Logger::log( $key, array( 'user_id' => (int) $user->ID, 'transport' => $transport, 'error' => $e->getMessage() ) );
			$msg = 'test' === $kind
				? __( 'Could not send the test email. Check debug logs or mail settings.', 'wp-dual-check' )
				: __( 'Could not send the login code. Try again later or contact an administrator.', 'wp-dual-check' );

			return new \WP_Error( 'wdc_send_failed', $msg );
		}

		$ok = 'test' === $kind ? 'test_mail_sent' : 'login_code_mail_sent';
		Logger::log( $ok, array( 'user_id' => (int) $user->ID, 'transport' => $transport ) );

		return true;
	}

	/**
	 * User-facing hint when DSN resolution returns empty.
	 */
	private static function missing_config_message( string $transport ): string {
		switch ( $transport ) {
			case self::TRANSPORT_DSN:
				return __( 'Set WP_DUAL_CHECK_MAILER_DSN (or choose another transport and fill API settings below).', 'wp-dual-check' );
			case self::TRANSPORT_SENDGRID_API:
				return __( 'SendGrid: add an API key under WP Dual Check → Mail Transport Providers, or set WP_DUAL_CHECK_SENDGRID_API_KEY.', 'wp-dual-check' );
			case self::TRANSPORT_MAILGUN_API:
				return __( 'Mailgun: add API key, sending domain, and region, or use the WP_DUAL_CHECK_MAILGUN_* environment variables.', 'wp-dual-check' );
			case self::TRANSPORT_SES_API:
				return __( 'Amazon SES: add access key, secret, and region, or use WP_DUAL_CHECK_SES_* environment variables.', 'wp-dual-check' );
			case self::TRANSPORT_POSTMARK_API:
				return __( 'Postmark: add a server API token, or set WP_DUAL_CHECK_POSTMARK_TOKEN.', 'wp-dual-check' );
			case self::TRANSPORT_GMAIL_SMTP:
				return __( 'Gmail: add the mailbox address and Google app password, or set WP_DUAL_CHECK_GMAIL_ADDRESS / WP_DUAL_CHECK_GMAIL_APP_PASSWORD. (OAuth2 Gmail API is not built in—use wp_mail with a Google plugin or a custom DSN filter.)', 'wp-dual-check' );
			default:
				return __( 'Mail transport is not fully configured.', 'wp-dual-check' );
		}
	}

	/** @var ?string */
	private static $wp_mail_alt_body = null;

	/**
	 * Sends through WordPress wp_mail / PHPMailer (optional multipart alt body).
	 */
	private static function send_via_wp_mail( \WP_User $user, string $to, string $subject, string $body_text, ?string $body_html, bool $multipart, string $kind ): \WP_Error|true {
		$from_email = Config::from_email();
		$from_name  = Config::from_name();
		$headers    = array();
		if ( is_email( $from_email ) ) {
			$headers[] = 'From: ' . self::format_rfc_from( $from_name, $from_email );
		}

		$body = $body_text;
		if ( null !== $body_html && '' !== $body_html ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
			$body      = $body_html;
			if ( $multipart && '' !== $body_text ) {
				self::$wp_mail_alt_body = $body_text;
				add_action( 'phpmailer_init', array( self::class, 'phpmailer_set_alt_body' ), 10, 1 );
			}
		} else {
			$headers[] = 'Content-Type: text/plain; charset=UTF-8';
		}

		$sent = wp_mail( $to, $subject, $body, $headers );

		remove_action( 'phpmailer_init', array( self::class, 'phpmailer_set_alt_body' ), 10 );
		self::$wp_mail_alt_body = null;
		if ( ! $sent ) {
			Logger::log(
				'test' === $kind ? 'test_mail_failed' : 'login_code_mail_failed',
				array( 'user_id' => (int) $user->ID, 'transport' => 'wp_mail', 'error' => 'wp_mail returned false' )
			);
			$msg = 'test' === $kind
				? __( 'Could not send the test email via wp_mail().', 'wp-dual-check' )
				: __( 'Could not send the login code. Try again later or contact an administrator.', 'wp-dual-check' );

			return new \WP_Error( 'wdc_send_failed', $msg );
		}

		Logger::log(
			'test' === $kind ? 'test_mail_sent' : 'login_code_mail_sent',
			array( 'user_id' => (int) $user->ID, 'transport' => 'wp_mail' )
		);

		return true;
	}

	/**
	 * Callback: sets AltBody when wp_mail is sending HTML with a separate plain part.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer|\PHPMailer $phpmailer WordPress core PHPMailer instance.
	 */
	public static function phpmailer_set_alt_body( object $phpmailer ): void {
		if ( null !== self::$wp_mail_alt_body && '' !== self::$wp_mail_alt_body ) {
			$phpmailer->AltBody = self::$wp_mail_alt_body;
		}
	}

	/**
	 * Builds a From header value with optional RFC 2047 encoding.
	 */
	private static function format_rfc_from( string $name, string $email ): string {
		$name = trim( $name );
		if ( '' === $name ) {
			return $email;
		}

		return sprintf( '%s <%s>', self::encode_rfc2047_name( $name ), $email );
	}

	/**
	 * Encodes non-ASCII display names for mail headers.
	 */
	private static function encode_rfc2047_name( string $name ): string {
		if ( preg_match( '/[\r\n"]/', $name ) ) {
			$name = preg_replace( '/[\r\n"]+/', ' ', $name );
		}
		if ( preg_match( '/[^\x20-\x7E]/', $name ) ) {
			return '=?UTF-8?B?' . base64_encode( $name ) . '?=';
		}

		return '"' . addcslashes( $name, '\\"' ) . '"';
	}

	/**
	 * Delivery override meta, legacy meta, or account email.
	 */
	private static function resolve_recipient_email( \WP_User $user ): string {
		$override = get_user_meta( $user->ID, MetaKeys::DELIVERY_EMAIL, true );
		if ( is_string( $override ) && '' !== $override && is_email( $override ) ) {
			return $override;
		}
		$legacy = get_user_meta( $user->ID, 'wp2fa_delivery_email', true );
		if ( is_string( $legacy ) && '' !== $legacy && is_email( $legacy ) ) {
			return $legacy;
		}

		return (string) $user->user_email;
	}
}
