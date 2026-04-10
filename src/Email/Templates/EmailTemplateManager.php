<?php
/**
 * Login code email: subject, plain text, HTML layout, placeholder replacement.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Email\Templates;

use WPDualCheck\Admin\Settings\SettingsRepository;
use WPDualCheck\Core\Config;
use WPDualCheck\Email\ValueObjects\EmailMessage;

/**
 * Builds {@see EmailMessage} from saved templates and layout options.
 */
final class EmailTemplateManager {

	public const FORMAT_TEXT      = 'text';
	public const FORMAT_HTML      = 'html';
	public const FORMAT_MULTIPART = 'multipart';

	/**
	 * Composes subject, text, and HTML according to format and placeholder rules.
	 *
	 * @param string $plain_code Raw digit code for placeholders (escaped in HTML mode).
	 */
	public static function build_login_code_email( \WP_User $user, string $plain_code ): EmailMessage {
		$settings = SettingsRepository::merged();

		$text_placeholders = self::placeholder_values( $user, $plain_code, 'text' );
		$html_placeholders = self::placeholder_values( $user, $plain_code, 'html' );

		$subject_template = isset( $settings['email_subject_template'] ) && is_string( $settings['email_subject_template'] ) && '' !== trim( $settings['email_subject_template'] )
			? (string) $settings['email_subject_template']
			: self::default_subject_template();
		$subject          = self::replace_placeholders( $subject_template, $text_placeholders );
		$subject          = wp_strip_all_tags( $subject );
		$subject          = (string) apply_filters( 'wp_dual_check_email_subject', $subject, $user );

		$text_template = isset( $settings['email_body_text'] ) && is_string( $settings['email_body_text'] ) && '' !== trim( $settings['email_body_text'] )
			? (string) $settings['email_body_text']
			: self::default_text_template();
		$plain_body    = self::replace_placeholders( $text_template, $text_placeholders );
		$plain_body    = (string) apply_filters( 'wp_dual_check_email_body', $plain_body, $user, $plain_code );

		$format = isset( $settings['email_format'] ) ? sanitize_key( (string) $settings['email_format'] ) : self::FORMAT_MULTIPART;
		if ( ! in_array( $format, array( self::FORMAT_TEXT, self::FORMAT_HTML, self::FORMAT_MULTIPART ), true ) ) {
			$format = self::FORMAT_MULTIPART;
		}

		if ( self::FORMAT_TEXT === $format ) {
			return new EmailMessage( $subject, $plain_body, null, false );
		}

		$html_body_raw = isset( $settings['email_body_html'] ) && is_string( $settings['email_body_html'] ) ? trim( $settings['email_body_html'] ) : '';
		if ( '' === $html_body_raw ) {
			$html_body_raw = self::default_inner_html_template();
		}
		$inner_html    = self::replace_placeholders( $html_body_raw, $html_placeholders );
		$wrapped_html = self::wrap_html_document( $inner_html, $settings );

		if ( self::FORMAT_HTML === $format ) {
			return new EmailMessage( $subject, '', $wrapped_html, false );
		}

		return new EmailMessage( $subject, $plain_body, $wrapped_html, true );
	}

	/** Default translatable subject when the option is empty. */
	public static function default_subject_template(): string {
		return __( 'Your login code for {site_name}', 'wp-dual-check' );
	}

	/** Default translatable plain body when the option is empty. */
	public static function default_text_template(): string {
		return __( "Hello {user_name},\n\nYour login code is: {code}\n\nIt expires in about {expiry_minutes} minutes.\n\nIP: {ip_address}\nTime: {login_time}", 'wp-dual-check' );
	}

	/** Inner HTML fragment placed inside the email layout wrapper. */
	public static function default_inner_html_template(): string {
		return '<p style="margin:0 0 16px;font-size:16px;line-height:1.5;color:#1d2327;">'
			. esc_html__( 'Hello', 'wp-dual-check' ) . ' {user_name},</p>'
			. '<p style="margin:0 0 8px;font-size:15px;color:#1d2327;">' . esc_html__( 'Your login code is:', 'wp-dual-check' ) . '</p>'
			. '<p style="margin:0 0 24px;font-size:28px;font-weight:700;letter-spacing:0.2em;color:#1d2327;">{code}</p>'
			. '<p style="margin:0;font-size:13px;line-height:1.6;color:#646970;">'
			. esc_html__( 'This code expires in about', 'wp-dual-check' )
			. ' {expiry_minutes} '
			. esc_html__( 'minutes', 'wp-dual-check' )
			. '.<br />'
			. esc_html__( 'IP:', 'wp-dual-check' ) . ' {ip_address} · {login_time}'
			. '</p>';
	}

	/**
	 * Map of placeholder => value for strtr(); HTML mode escapes values.
	 *
	 * @param string $mode 'text' or 'html'.
	 * @return array<string, string>
	 */
	private static function placeholder_values( \WP_User $user, string $plain_code, string $mode ): array {
		$site_name_decoded   = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$expiry_minutes_str  = (string) (int) ceil( Config::code_ttl_seconds() / 60 );
		$client_ip           = self::client_ip();
		$login_time_formatted = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), time() );

		if ( 'html' === $mode ) {
			return array(
				'{site_name}'      => esc_html( $site_name_decoded ),
				'{user_name}'      => esc_html( (string) $user->display_name ),
				'{code}'           => esc_html( $plain_code ),
				'{ip_address}'     => esc_html( $client_ip ),
				'{login_time}'     => esc_html( $login_time_formatted ),
				'{expiry_minutes}' => esc_html( $expiry_minutes_str ),
			);
		}

		return array(
			'{site_name}'      => $site_name_decoded,
			'{user_name}'      => (string) $user->display_name,
			'{code}'           => $plain_code,
			'{ip_address}'     => $client_ip,
			'{login_time}'     => $login_time_formatted,
			'{expiry_minutes}' => $expiry_minutes_str,
		);
	}

	/**
	 * @param array<string, string> $placeholders Keys like {code}.
	 */
	public static function replace_placeholders( string $template, array $placeholders ): string {
		return strtr( $template, $placeholders );
	}

	/**
	 * Client IP for email copy; filterable.
	 */
	public static function client_ip(): string {
		/**
		 * IP address shown in login code emails (default: REMOTE_ADDR only).
		 *
		 * @param string $ip Current IP (may be empty).
		 */
		$ip = (string) apply_filters( 'wp_dual_check_client_ip', isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '' );

		return $ip;
	}

	/**
	 * Wraps inner HTML in the default email shell (colours, header/footer, images).
	 *
	 * @param array<string, mixed> $settings Merged plugin settings.
	 */
	private static function wrap_html_document( string $inner_html, array $settings ): string {
		$accent_color = self::sanitize_hex( isset( $settings['email_accent_color'] ) ? (string) $settings['email_accent_color'] : '', '#2271b1' );
		$outer_background = self::sanitize_hex( isset( $settings['email_background_color'] ) ? (string) $settings['email_background_color'] : '', '#f0f0f1' );
		$content_max_width_px = self::clamp_px( isset( $settings['email_container_max_width_px'] ) ? (int) $settings['email_container_max_width_px'] : 600, 600 );
		$header_image_width_px = self::clamp_px( isset( $settings['email_header_width_px'] ) ? (int) $settings['email_header_width_px'] : 600, 600 );
		$footer_image_width_px = self::clamp_px( isset( $settings['email_footer_width_px'] ) ? (int) $settings['email_footer_width_px'] : 600, 600 );

		$header_text = isset( $settings['email_header_text'] ) ? wp_kses_post( (string) $settings['email_header_text'] ) : '';
		$footer_text = isset( $settings['email_footer_text'] ) ? wp_kses_post( (string) $settings['email_footer_text'] ) : '';

		$header_image_url = isset( $settings['email_header_image_url'] ) ? esc_url_raw( (string) $settings['email_header_image_url'] ) : '';
		$footer_image_url = isset( $settings['email_footer_image_url'] ) ? esc_url_raw( (string) $settings['email_footer_image_url'] ) : '';

		// Aliases for templates/emails/default.php (presentation layer).
		$accent     = $accent_color;
		$bg         = $outer_background;
		$max        = $content_max_width_px;
		$hw         = $header_image_width_px;
		$fw         = $footer_image_width_px;
		$header_img = $header_image_url;
		$footer_img = $footer_image_url;

		ob_start();
		include WP_DUAL_CHECK_PATH . 'templates/emails/default.php';

		return (string) ob_get_clean();
	}

	private static function sanitize_hex( string $value, string $fallback ): string {
		$value = strtolower( trim( $value ) );
		$ok    = sanitize_hex_color( $value );

		return '' !== $ok ? $ok : $fallback;
	}

	private static function clamp_px( int $pixels, int $default ): int {
		if ( $pixels <= 0 ) {
			return max( 200, min( 920, $default ) );
		}

		return max( 200, min( 920, $pixels ) );
	}
}
