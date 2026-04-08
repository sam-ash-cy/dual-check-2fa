<?php
/**
 * Login code email: Symfony Mailer (DSN, API providers, native/sendmail) or wp_mail().
 *
 * @package WPDualCheck
 */

namespace WPDualCheck;

use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class Mailer {

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
	 * Transports that use the “API email providers” settings (or matching env vars).
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

	public static function is_api_transport( string $transport ): bool {
		return in_array( $transport, self::api_transport_ids(), true );
	}

	/**
	 * Built-in Symfony DSNs (no env DSN required).
	 */
	private const EMBEDDED_DSN = array(
		self::TRANSPORT_PHP_MAIL => 'native://default',
		self::TRANSPORT_SENDMAIL => 'sendmail://default',
	);

	/**
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
	 * @return list<string>
	 */
	public static function valid_transport_ids(): array {
		return array_keys( self::transport_choices() );
	}

	public static function sanitize_transport_id( string $value, string $fallback ): string {
		$value = sanitize_key( $value );
		if ( in_array( $value, self::valid_transport_ids(), true ) ) {
			return $value;
		}
		return $fallback;
	}

	public static function resolve_transport_for_user( \WP_User $user ): string {
		$raw = (string) get_user_meta( $user->ID, User_Settings::META_MAIL_TRANSPORT, true );
		if ( '' === $raw ) {
			$raw = (string) get_user_meta( $user->ID, 'wp2fa_mailer_transport', true );
		}
		$raw = sanitize_key( $raw );
		if ( '' === $raw || self::USER_TRANSPORT_INHERIT === $raw ) {
			$site = (string) Admin_Settings::merged()['default_mailer_transport'];

			return self::sanitize_transport_id( $site, self::TRANSPORT_DSN );
		}

		return self::sanitize_transport_id( $raw, self::TRANSPORT_DSN );
	}

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

		$t = self::resolve_transport_for_user( $user );

		return self::dispatch( $t, $user, $to, $subject, $body, null, false, 'test' );
	}

	public static function send_code_email( \WP_User $user, string $plain_code ): \WP_Error|true {
		$to = self::resolve_recipient_email( $user );
		if ( '' === $to || ! is_email( $to ) ) {
			return new \WP_Error(
				'wdc_bad_email',
				__( 'Your account has no valid email address for the login code.', 'wp-dual-check' )
			);
		}

		$parts = Email_Template::build_login_code_email( $user, $plain_code );
		$t     = self::resolve_transport_for_user( $user );

		return self::dispatch(
			$t,
			$user,
			$to,
			$parts['subject'],
			$parts['text'],
			$parts['html'],
			$parts['multipart'],
			'login_code'
		);
	}

	private static function dispatch( string $transport, \WP_User $user, string $to, string $subject, string $body_text, ?string $body_html, bool $multipart, string $kind ): \WP_Error|true {
		if ( self::TRANSPORT_WP_MAIL === $transport ) {
			return self::send_via_wp_mail( $user, $to, $subject, $body_text, $body_html, $multipart, $kind );
		}

		$dsn = self::dsn_for_symfony_transport( $transport );
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

	private static function dsn_for_symfony_transport( string $transport ): string {
		if ( isset( self::EMBEDDED_DSN[ $transport ] ) ) {
			return self::EMBEDDED_DSN[ $transport ];
		}
		if ( self::TRANSPORT_DSN === $transport ) {
			return Config::mailer_dsn();
		}
		if ( self::TRANSPORT_SENDGRID_API === $transport ) {
			return self::dsn_sendgrid();
		}
		if ( self::TRANSPORT_MAILGUN_API === $transport ) {
			return self::dsn_mailgun();
		}
		if ( self::TRANSPORT_SES_API === $transport ) {
			return self::dsn_ses();
		}
		if ( self::TRANSPORT_POSTMARK_API === $transport ) {
			return self::dsn_postmark();
		}
		if ( self::TRANSPORT_GMAIL_SMTP === $transport ) {
			return self::dsn_gmail_smtp();
		}

		return '';
	}

	private static function dsn_sendgrid(): string {
		$key = Provider_Secrets::line( 'api_sendgrid_key', 'WP_DUAL_CHECK_SENDGRID_API_KEY' );
		if ( '' === $key ) {
			return '';
		}

		return 'sendgrid+api://' . rawurlencode( $key ) . '@default';
	}

	private static function dsn_mailgun(): string {
		$key    = Provider_Secrets::line( 'api_mailgun_key', 'WP_DUAL_CHECK_MAILGUN_API_KEY' );
		$domain = Provider_Secrets::line( 'api_mailgun_domain', 'WP_DUAL_CHECK_MAILGUN_DOMAIN' );
		if ( '' === $key || '' === $domain ) {
			return '';
		}
		$env_reg = Config::get_secret_string( 'WP_DUAL_CHECK_MAILGUN_REGION', '' );
		if ( '' !== $env_reg ) {
			$reg = 'eu' === strtolower( trim( $env_reg ) ) ? 'eu' : 'us';
		} else {
			$m   = Admin_Settings::merged();
			$opt = isset( $m['api_mailgun_region'] ) ? strtolower( (string) $m['api_mailgun_region'] ) : 'us';
			$reg = 'eu' === $opt ? 'eu' : 'us';
		}
		$dsn = sprintf(
			'mailgun+api://%s:%s@default',
			rawurlencode( $key ),
			rawurlencode( $domain )
		);
		if ( 'eu' === $reg ) {
			$dsn .= '?region=eu';
		}

		return $dsn;
	}

	private static function dsn_ses(): string {
		$access = Provider_Secrets::line( 'api_ses_access_key', 'WP_DUAL_CHECK_SES_ACCESS_KEY' );
		$secret = Provider_Secrets::line( 'api_ses_secret_key', 'WP_DUAL_CHECK_SES_SECRET_KEY' );
		if ( '' === $access || '' === $secret ) {
			return '';
		}
		$m      = Admin_Settings::merged();
		$region = isset( $m['api_ses_region'] ) ? trim( (string) $m['api_ses_region'] ) : 'us-east-1';
		$region = preg_replace( '/[^a-z0-9\-]/i', '', $region );
		if ( '' === $region ) {
			$region = 'us-east-1';
		}
		$env_region = Config::get_secret_string( 'WP_DUAL_CHECK_SES_REGION', '' );
		if ( '' !== $env_region ) {
			$region = preg_replace( '/[^a-z0-9\-]/i', '', $env_region ) ?: $region;
		}

		return sprintf(
			'ses+api://%s:%s@default?region=%s',
			rawurlencode( $access ),
			rawurlencode( $secret ),
			rawurlencode( $region )
		);
	}

	private static function dsn_postmark(): string {
		$token = Provider_Secrets::line( 'api_postmark_token', 'WP_DUAL_CHECK_POSTMARK_TOKEN' );
		if ( '' === $token ) {
			return '';
		}

		return 'postmark+api://' . rawurlencode( $token ) . '@default';
	}

	private static function dsn_gmail_smtp(): string {
		$user = Provider_Secrets::line( 'api_gmail_user', 'WP_DUAL_CHECK_GMAIL_ADDRESS' );
		$pass = Provider_Secrets::line( 'api_gmail_app_password', 'WP_DUAL_CHECK_GMAIL_APP_PASSWORD' );
		if ( '' === $user || ! is_email( $user ) || '' === $pass ) {
			return '';
		}

		return sprintf(
			'gmail+smtp://%s:%s@default',
			rawurlencode( $user ),
			rawurlencode( $pass )
		);
	}

	/** @var ?string */
	private static $wp_mail_alt_body = null;

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
	 * @param \PHPMailer\PHPMailer\PHPMailer|\PHPMailer $phpmailer WordPress core PHPMailer instance.
	 */
	public static function phpmailer_set_alt_body( object $phpmailer ): void {
		if ( null !== self::$wp_mail_alt_body && '' !== self::$wp_mail_alt_body ) {
			$phpmailer->AltBody = self::$wp_mail_alt_body;
		}
	}

	private static function format_rfc_from( string $name, string $email ): string {
		$name = trim( $name );
		if ( '' === $name ) {
			return $email;
		}

		return sprintf( '%s <%s>', self::encode_rfc2047_name( $name ), $email );
	}

	private static function encode_rfc2047_name( string $name ): string {
		if ( preg_match( '/[\r\n"]/', $name ) ) {
			$name = preg_replace( '/[\r\n"]+/', ' ', $name );
		}
		if ( preg_match( '/[^\x20-\x7E]/', $name ) ) {
			return '=?UTF-8?B?' . base64_encode( $name ) . '?=';
		}

		return '"' . addcslashes( $name, '\\"' ) . '"';
	}

	private static function resolve_recipient_email( \WP_User $user ): string {
		$override = get_user_meta( $user->ID, User_Settings::META_DELIVERY_EMAIL, true );
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
