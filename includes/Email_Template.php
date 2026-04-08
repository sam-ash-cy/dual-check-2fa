<?php
/**
 * Login code email: subject, plain text, HTML layout, placeholder replacement.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck;

final class Email_Template {

	public const FORMAT_TEXT      = 'text';
	public const FORMAT_HTML      = 'html';
	public const FORMAT_MULTIPART = 'multipart';

	/**
	 * @return array{subject:string,text:string,html:?string,multipart:bool}
	 */
	public static function build_login_code_email( \WP_User $user, string $plain_code ): array {
		$m = Admin_Settings::merged();

		$text_vars = self::placeholder_values( $user, $plain_code, 'text' );
		$html_vars = self::placeholder_values( $user, $plain_code, 'html' );

		$subject_tpl = isset( $m['email_subject_template'] ) && is_string( $m['email_subject_template'] ) && '' !== trim( $m['email_subject_template'] )
			? (string) $m['email_subject_template']
			: self::default_subject_template();
		$subject     = self::replace_placeholders( $subject_tpl, $text_vars );
		$subject     = wp_strip_all_tags( $subject );
		$subject     = (string) apply_filters( 'wp_dual_check_email_subject', $subject, $user );

		$text_tpl = isset( $m['email_body_text'] ) && is_string( $m['email_body_text'] ) && '' !== trim( $m['email_body_text'] )
			? (string) $m['email_body_text']
			: self::default_text_template();
		$text     = self::replace_placeholders( $text_tpl, $text_vars );
		$text     = (string) apply_filters( 'wp_dual_check_email_body', $text, $user, $plain_code );

		$format = isset( $m['email_format'] ) ? sanitize_key( (string) $m['email_format'] ) : self::FORMAT_MULTIPART;
		if ( ! in_array( $format, array( self::FORMAT_TEXT, self::FORMAT_HTML, self::FORMAT_MULTIPART ), true ) ) {
			$format = self::FORMAT_MULTIPART;
		}

		if ( self::FORMAT_TEXT === $format ) {
			return array(
				'subject'   => $subject,
				'text'      => $text,
				'html'      => null,
				'multipart' => false,
			);
		}

		$html_raw = isset( $m['email_body_html'] ) && is_string( $m['email_body_html'] ) ? trim( $m['email_body_html'] ) : '';
		if ( '' === $html_raw ) {
			$html_raw = self::default_inner_html_template();
		}
		$inner_html   = self::replace_placeholders( $html_raw, $html_vars );
		$wrapped_html = self::wrap_html_document( $inner_html, $m );

		if ( self::FORMAT_HTML === $format ) {
			return array(
				'subject'   => $subject,
				'text'      => '',
				'html'      => $wrapped_html,
				'multipart' => false,
			);
		}

		return array(
			'subject'   => $subject,
			'text'      => $text,
			'html'      => $wrapped_html,
			'multipart' => true,
		);
	}

	public static function default_subject_template(): string {
		return __( 'Your login code for {site_name}', 'wp-dual-check' );
	}

	public static function default_text_template(): string {
		return __( "Hello {user_name},\n\nYour login code is: {code}\n\nIt expires in about {expiry_minutes} minutes.\n\nIP: {ip_address}\nTime: {login_time}", 'wp-dual-check' );
	}

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
	 * @return array<string, string>
	 */
	private static function placeholder_values( \WP_User $user, string $plain_code, string $mode ): array {
		$site = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$exp  = (string) (int) ceil( Config::code_ttl_seconds() / 60 );
		$ip   = self::client_ip();
		$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), time() );

		if ( 'html' === $mode ) {
			return array(
				'{site_name}'       => esc_html( $site ),
				'{user_name}'       => esc_html( (string) $user->display_name ),
				'{code}'            => esc_html( $plain_code ),
				'{ip_address}'      => esc_html( $ip ),
				'{login_time}'      => esc_html( $when ),
				'{expiry_minutes}'  => esc_html( $exp ),
			);
		}

		return array(
			'{site_name}'       => $site,
			'{user_name}'       => (string) $user->display_name,
			'{code}'            => $plain_code,
			'{ip_address}'      => $ip,
			'{login_time}'      => $when,
			'{expiry_minutes}'  => $exp,
		);
	}

	/**
	 * @param array<string, string> $vars
	 */
	public static function replace_placeholders( string $template, array $vars ): string {
		return strtr( $template, $vars );
	}

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
	 * @param array<string, mixed> $m Merged plugin settings.
	 */
	private static function wrap_html_document( string $inner_html, array $m ): string {
		$accent = self::sanitize_hex( isset( $m['email_accent_color'] ) ? (string) $m['email_accent_color'] : '', '#2271b1' );
		$bg     = self::sanitize_hex( isset( $m['email_background_color'] ) ? (string) $m['email_background_color'] : '', '#f0f0f1' );
		$max    = self::clamp_px( isset( $m['email_container_max_width_px'] ) ? (int) $m['email_container_max_width_px'] : 600, 600 );
		$hw     = self::clamp_px( isset( $m['email_header_width_px'] ) ? (int) $m['email_header_width_px'] : 600, 600 );
		$fw     = self::clamp_px( isset( $m['email_footer_width_px'] ) ? (int) $m['email_footer_width_px'] : 600, 600 );

		$header_text = isset( $m['email_header_text'] ) ? wp_kses_post( (string) $m['email_header_text'] ) : '';
		$footer_text = isset( $m['email_footer_text'] ) ? wp_kses_post( (string) $m['email_footer_text'] ) : '';

		$header_img = isset( $m['email_header_image_url'] ) ? esc_url_raw( (string) $m['email_header_image_url'] ) : '';
		$footer_img = isset( $m['email_footer_image_url'] ) ? esc_url_raw( (string) $m['email_footer_image_url'] ) : '';

		ob_start();
		echo '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" /></head>';
		echo '<body style="margin:0;padding:0;background:' . esc_attr( $bg ) . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">';
		echo '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:' . esc_attr( $bg ) . ';padding:24px 12px;"><tr><td align="center">';
		echo '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="' . esc_attr( (string) $max ) . '" style="max-width:100%;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);">';

		if ( '' !== $header_img ) {
			echo '<tr><td align="center" style="padding:0;line-height:0;">';
			echo '<img src="' . esc_url( $header_img ) . '" alt="" width="' . esc_attr( (string) min( $hw, $max ) ) . '" style="display:block;max-width:100%;height:auto;width:' . esc_attr( (string) min( $hw, $max ) ) . 'px;" />';
			echo '</td></tr>';
		}

		if ( '' !== $header_text ) {
			echo '<tr><td style="padding:16px 24px;background:' . esc_attr( $accent ) . ';color:#ffffff;font-size:18px;font-weight:600;">';
			echo $header_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post above
			echo '</td></tr>';
		}

		echo '<tr><td style="padding:28px 24px;">';
		echo $inner_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped placeholders + wp_kses_post admin HTML
		echo '</td></tr>';

		if ( '' !== $footer_img ) {
			echo '<tr><td align="center" style="padding:0 0 16px;line-height:0;">';
			echo '<img src="' . esc_url( $footer_img ) . '" alt="" width="' . esc_attr( (string) min( $fw, $max ) ) . '" style="display:block;max-width:100%;height:auto;width:' . esc_attr( (string) min( $fw, $max ) ) . 'px;" />';
			echo '</td></tr>';
		}

		if ( '' !== $footer_text ) {
			echo '<tr><td style="padding:16px 24px;border-top:1px solid #e0e0e0;font-size:12px;line-height:1.5;color:#646970;text-align:center;">';
			echo $footer_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</td></tr>';
		}

		echo '</table></td></tr></table></body></html>';

		return (string) ob_get_clean();
	}

	private static function sanitize_hex( string $value, string $fallback ): string {
		$value = strtolower( trim( $value ) );
		$ok    = sanitize_hex_color( $value );

		return '' !== $ok ? $ok : $fallback;
	}

	private static function clamp_px( int $v, int $default ): int {
		if ( $v <= 0 ) {
			return max( 200, min( 920, $default ) );
		}

		return max( 200, min( 920, $v ) );
	}
}
