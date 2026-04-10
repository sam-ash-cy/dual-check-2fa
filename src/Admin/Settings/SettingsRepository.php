<?php
/**
 * Merged plugin options (single option array) and defaults.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Admin\Settings;

use WPDualCheck\Email\MailTransport;

/**
 * Single option array storage with defaults and legacy wp2fa migration.
 */
final class SettingsRepository {

	public const OPTION_KEY   = 'wp_dual_check_settings';
	public const OPTION_GROUP = 'wp_dual_check_settings_group';
	public const PAGE_SLUG    = 'wp-dual-check';
	public const PAGE_EMAIL_TEMPLATE = 'wp-dual-check-email-template';
	public const PAGE_MAIL_PROVIDERS  = 'wp-dual-check-mail-providers';

	public const DEFAULTS = array(
		'require_all_logins'       => false,
		'code_ttl'                 => 600,
		'code_length'              => 6,
		'max_attempts'             => 5,
		'from_email'               => '',
		'from_name'                => '',
		'default_mailer_transport' => 'dsn',
		'resend_cooldown'          => 60,
		'debug_logging'            => false,
		'api_sendgrid_key'         => '',
		'api_mailgun_key'          => '',
		'api_mailgun_domain'       => '',
		'api_mailgun_region'       => 'us',
		'api_ses_access_key'       => '',
		'api_ses_secret_key'       => '',
		'api_ses_region'           => 'us-east-1',
		'api_postmark_token'       => '',
		'api_gmail_user'           => '',
		'api_gmail_app_password'   => '',
		'email_subject_template'   => 'Your login code for {site_name}',
		'email_format'             => 'multipart',
		'email_accent_color'       => '#2271b1',
		'email_background_color'   => '#f0f0f1',
		'email_header_text'        => '',
		'email_header_image_url'   => '',
		'email_header_width_px'    => 600,
		'email_footer_image_url'   => '',
		'email_footer_width_px'    => 600,
		'email_container_max_width_px' => 600,
		'email_footer_text'        => '',
		'email_body_html'          => '',
		'email_body_text'          => "Hello {user_name},\n\nYour login code is: {code}\n\nIt expires in about {expiry_minutes} minutes.\n\nIP: {ip_address}\nTime: {login_time}",
	);

	/**
	 * Returns defaults merged with stored (or legacy) options and normalized transport id.
	 *
	 * @return array<string, mixed>
	 */
	public static function merged(): array {
		$stored = get_option( self::OPTION_KEY );
		if ( is_array( $stored ) && $stored !== array() ) {
			$out = array_merge( self::DEFAULTS, $stored );
		} else {
			$legacy = get_option( 'wp2fa_settings', array() );
			if ( is_array( $legacy ) && $legacy !== array() ) {
				$out = array_merge( self::DEFAULTS, $legacy );
			} else {
				$out = array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );
			}
		}
		unset( $out['rest_enabled'], $out['api_ops_notes'] );
		if ( ! isset( $out['default_mailer_transport'] ) || ! is_string( $out['default_mailer_transport'] ) ) {
			$out['default_mailer_transport'] = self::DEFAULTS['default_mailer_transport'];
		} else {
			$out['default_mailer_transport'] = MailTransport::sanitize_transport_id( $out['default_mailer_transport'], MailTransport::TRANSPORT_DSN );
		}
		foreach ( self::DEFAULTS as $option_key => $default_value ) {
			if ( ! array_key_exists( $option_key, $out ) ) {
				$out[ $option_key ] = $default_value;
			}
		}

		return $out;
	}
}
