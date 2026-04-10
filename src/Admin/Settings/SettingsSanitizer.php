<?php
/**
 * Sanitizes the plugin settings option array on save.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Admin\Settings;

use WPDualCheck\Email\Mailer;
use WPDualCheck\Email\Templates\EmailTemplateManager;

/**
 * Validates and normalizes option array on save.
 */
final class SettingsSanitizer {

	/**
	 * @param array<string, mixed> $input Raw POSTed option subset.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$prev  = SettingsRepository::merged();
		$input = is_array( $input ) ? $input : array();

		$transport = isset( $input['default_mailer_transport'] ) ? (string) $input['default_mailer_transport'] : (string) $prev['default_mailer_transport'];

		$mg_reg = isset( $input['api_mailgun_region'] ) ? strtolower( (string) $input['api_mailgun_region'] ) : (string) $prev['api_mailgun_region'];
		$mg_reg = 'eu' === $mg_reg ? 'eu' : 'us';

		return array(
			'require_all_logins'           => array_key_exists( 'require_all_logins', $input ) ? ( '1' === (string) $input['require_all_logins'] ) : (bool) $prev['require_all_logins'],
			'code_ttl'                     => isset( $input['code_ttl'] ) ? max( 60, min( 86400, (int) $input['code_ttl'] ) ) : (int) $prev['code_ttl'],
			'code_length'                  => isset( $input['code_length'] ) ? max( 4, min( 12, (int) $input['code_length'] ) ) : (int) ( $prev['code_length'] ?? 6 ),
			'max_attempts'                 => isset( $input['max_attempts'] ) ? max( 1, min( 50, (int) $input['max_attempts'] ) ) : (int) $prev['max_attempts'],
			'resend_cooldown'              => isset( $input['resend_cooldown'] ) ? max( 15, min( 600, (int) $input['resend_cooldown'] ) ) : (int) $prev['resend_cooldown'],
			'from_email'                   => isset( $input['from_email'] ) ? sanitize_email( (string) $input['from_email'] ) : (string) $prev['from_email'],
			'from_name'                    => isset( $input['from_name'] ) ? sanitize_text_field( (string) $input['from_name'] ) : (string) $prev['from_name'],
			'default_mailer_transport'     => Mailer::sanitize_transport_id( $transport, Mailer::TRANSPORT_DSN ),
			'debug_logging'                => array_key_exists( 'debug_logging', $input ) ? ( '1' === (string) $input['debug_logging'] ) : (bool) $prev['debug_logging'],
			'email_subject_template'       => isset( $input['email_subject_template'] ) ? sanitize_text_field( (string) $input['email_subject_template'] ) : (string) $prev['email_subject_template'],
			'email_format'                 => isset( $input['email_format'] ) ? self::sanitize_email_format( (string) $input['email_format'] ) : (string) $prev['email_format'],
			'email_accent_color'           => isset( $input['email_accent_color'] ) ? self::sanitize_hex_color_field( (string) $input['email_accent_color'], (string) $prev['email_accent_color'] ) : (string) $prev['email_accent_color'],
			'email_background_color'       => isset( $input['email_background_color'] ) ? self::sanitize_hex_color_field( (string) $input['email_background_color'], (string) $prev['email_background_color'] ) : (string) $prev['email_background_color'],
			'email_header_text'            => isset( $input['email_header_text'] ) ? wp_kses_post( (string) $input['email_header_text'] ) : (string) $prev['email_header_text'],
			'email_header_image_url'       => isset( $input['email_header_image_url'] ) ? esc_url_raw( (string) $input['email_header_image_url'] ) : (string) $prev['email_header_image_url'],
			'email_header_width_px'        => isset( $input['email_header_width_px'] ) ? max( 200, min( 920, (int) $input['email_header_width_px'] ) ) : (int) $prev['email_header_width_px'],
			'email_footer_image_url'       => isset( $input['email_footer_image_url'] ) ? esc_url_raw( (string) $input['email_footer_image_url'] ) : (string) $prev['email_footer_image_url'],
			'email_footer_width_px'        => isset( $input['email_footer_width_px'] ) ? max( 200, min( 920, (int) $input['email_footer_width_px'] ) ) : (int) $prev['email_footer_width_px'],
			'email_container_max_width_px' => isset( $input['email_container_max_width_px'] ) ? max( 200, min( 920, (int) $input['email_container_max_width_px'] ) ) : (int) $prev['email_container_max_width_px'],
			'email_footer_text'            => isset( $input['email_footer_text'] ) ? wp_kses_post( (string) $input['email_footer_text'] ) : (string) $prev['email_footer_text'],
			'email_body_html'              => isset( $input['email_body_html'] ) ? wp_kses_post( (string) $input['email_body_html'] ) : (string) $prev['email_body_html'],
			'email_body_text'              => isset( $input['email_body_text'] ) ? sanitize_textarea_field( (string) $input['email_body_text'] ) : (string) $prev['email_body_text'],
			'api_sendgrid_key'             => self::sanitize_secret_field( $input, 'api_sendgrid_key', $prev ),
			'api_mailgun_key'              => self::sanitize_secret_field( $input, 'api_mailgun_key', $prev ),
			'api_mailgun_domain'           => isset( $input['api_mailgun_domain'] ) ? sanitize_text_field( (string) $input['api_mailgun_domain'] ) : (string) $prev['api_mailgun_domain'],
			'api_mailgun_region'           => $mg_reg,
			'api_ses_access_key'           => self::sanitize_secret_field( $input, 'api_ses_access_key', $prev ),
			'api_ses_secret_key'           => self::sanitize_secret_field( $input, 'api_ses_secret_key', $prev ),
			'api_ses_region'               => self::sanitize_ses_region( $input, $prev ),
			'api_postmark_token'           => self::sanitize_secret_field( $input, 'api_postmark_token', $prev ),
			'api_gmail_user'               => isset( $input['api_gmail_user'] ) ? sanitize_email( (string) $input['api_gmail_user'] ) : (string) $prev['api_gmail_user'],
			'api_gmail_app_password'       => self::sanitize_secret_field( $input, 'api_gmail_app_password', $prev ),
		);
	}

	/**
	 * Strips invalid characters from SES region; defaults to us-east-1.
	 *
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $prev
	 */
	private static function sanitize_ses_region( array $input, array $prev ): string {
		$region = isset( $input['api_ses_region'] ) ? preg_replace( '/[^a-z0-9\-]/i', '', (string) $input['api_ses_region'] ) : (string) ( $prev['api_ses_region'] ?? 'us-east-1' );
		if ( '' === $region ) {
			$region = (string) ( $prev['api_ses_region'] ?? 'us-east-1' );
		}

		return '' !== $region ? $region : 'us-east-1';
	}

	/**
	 * Keeps previous secret when the submitted password field is empty.
	 *
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $prev
	 */
	private static function sanitize_secret_field( array $input, string $key, array $prev ): string {
		if ( ! isset( $input[ $key ] ) ) {
			return isset( $prev[ $key ] ) ? (string) $prev[ $key ] : '';
		}
		$trimmed = trim( (string) $input[ $key ] );
		if ( '' === $trimmed ) {
			return isset( $prev[ $key ] ) ? (string) $prev[ $key ] : '';
		}

		return $trimmed;
	}

	/** Whitelists email_format to text, html, or multipart. */
	private static function sanitize_email_format( string $value ): string {
		$value = sanitize_key( $value );
		$ok    = array( EmailTemplateManager::FORMAT_TEXT, EmailTemplateManager::FORMAT_HTML, EmailTemplateManager::FORMAT_MULTIPART );

		return in_array( $value, $ok, true ) ? $value : EmailTemplateManager::FORMAT_MULTIPART;
	}

	/** Validates hex or returns sanitized fallback / default blue. */
	private static function sanitize_hex_color_field( string $value, string $fallback ): string {
		$sanitized = sanitize_hex_color( trim( $value ) );
		if ( '' !== $sanitized ) {
			return $sanitized;
		}
		$fallback_sanitized = sanitize_hex_color( trim( $fallback ) );

		return '' !== $fallback_sanitized ? $fallback_sanitized : '#2271b1';
	}
}
