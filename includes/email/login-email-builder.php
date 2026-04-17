<?php

namespace WP_DUAL_CHECK\email;

use WP_DUAL_CHECK\core\Plugin;
use function WP_DUAL_CHECK\db\dual_check_settings;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Builds HTML login code emails from settings and placeholder tokens.
 */
final class Login_Email_Builder {

	private const PLACEHOLDER_KEYS = array(
		'[site-name]',
		'[code]',
		'[user-login]',
		'[expires]',
		'[site-url]',
	);

	/** @var array<string, string> */
	private const DEFAULT_TEMPLATE_FN = array(
		'subject' => 'wp_dual_check_email_default_subject',
		'body'    => 'wp_dual_check_email_default_body',
		'header'  => 'wp_dual_check_email_default_header',
		'footer'  => 'wp_dual_check_email_default_footer',
	);

	/**
	 * @return array{subject: string, html: string}
	 */
	public static function build(string $code, string $user_login): array {
		$settings = dual_check_settings();
		$ctx      = self::context_values($code, $user_login);

		return array(
			'subject' => self::build_subject($settings, $ctx),
			'html'    => self::build_html_wrapper($settings, self::build_body_inner($settings, $ctx), $ctx),
		);
	}

	private static function use_custom_email_template(array $settings): bool {
		return !empty($settings['email_use_custom_template']);
	}

	/** Load templates/email/default-template.php once and call the part function. */
	private static function default_template_part(string $part): string {
		if (!isset(self::DEFAULT_TEMPLATE_FN[ $part ])) {
			return '';
		}
		static $loaded = false;
		if (!$loaded) {
			$loaded = true;
			$path = Plugin::path('templates/email/default-template.php');
			if (is_readable($path)) {
				require_once $path;
			}
		}
		$fn = self::DEFAULT_TEMPLATE_FN[ $part ];
		if (!function_exists($fn)) {
			return '';
		}
		$out = $fn();

		return is_string($out) ? trim($out) : '';
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, string> $ctx
	 */
	private static function build_subject(array $settings, array $ctx): string {
		if (!self::use_custom_email_template($settings)) {
			$tpl = self::default_template_part('subject');
			if ($tpl === '') {
				return sprintf(
					/* translators: %s: site name */
					__('[%s] Your login security code', 'wp-dual-check'),
					wp_strip_all_tags($ctx['[site-name]'])
				);
			}
			$plain = array();
			foreach (self::PLACEHOLDER_KEYS as $key) {
				$plain[ $key ] = wp_strip_all_tags($ctx[ $key ] ?? '');
			}

			return wp_strip_all_tags(self::replace_tokens($tpl, $plain));
		}

		$tpl = isset($settings['email_subject_template']) ? trim((string) $settings['email_subject_template']) : '';
		if ($tpl === '') {
			return sprintf(
				/* translators: %s: site name */
				__('[%s] Your login security code', 'wp-dual-check'),
				wp_strip_all_tags($ctx['[site-name]'])
			);
		}

		$plain = array();
		foreach (self::PLACEHOLDER_KEYS as $key) {
			$plain[ $key ] = wp_strip_all_tags($ctx[ $key ] ?? '');
		}

		return wp_strip_all_tags(self::replace_tokens($tpl, $plain));
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, string> $ctx
	 */
	private static function build_body_inner(array $settings, array $ctx): string {
		if (!self::use_custom_email_template($settings)) {
			$tpl = self::default_template_part('body');
			if ($tpl === '') {
				$tpl = self::default_body_template();
			}

			return self::replace_tokens($tpl, self::html_placeholder_values($ctx));
		}

		$tpl = isset($settings['email_body_template']) ? trim((string) $settings['email_body_template']) : '';
		if ($tpl === '') {
			$tpl = self::default_body_template();
		}

		return self::replace_tokens($tpl, self::html_placeholder_values($ctx));
	}

	/**
	 * @param array<string, string> $ctx Raw placeholder values from {@see context_values()}.
	 * @return array<string, string>
	 */
	private static function html_placeholder_values(array $ctx): array {
		$html_ctx = array();
		foreach (self::PLACEHOLDER_KEYS as $key) {
			$html_ctx[ $key ] = self::escape_placeholder_for_html($key, $ctx[ $key ] ?? '');
		}

		return $html_ctx;
	}

	private static function escape_placeholder_for_html(string $token, string $value): string {
		if ($token === '[site-url]') {
			return esc_url($value);
		}

		return esc_html($value);
	}

	/**
	 * @param array<string, string> $pairs Token => replacement (already escaped for context).
	 */
	private static function replace_tokens(string $template, array $pairs): string {
		$out = $template;
		foreach ($pairs as $token => $replacement) {
			$out = str_ireplace($token, $replacement, $out);
		}

		return $out;
	}

	/**
	 * @return array<string, string>
	 */
	private static function context_values(string $code, string $user_login): array {
		$settings = dual_check_settings();
		$minutes  = (int) $settings['code_lifetime_minutes'];
		$expires  = wp_date(
			get_option('date_format') . ' ' . get_option('time_format'),
			time() + $minutes * MINUTE_IN_SECONDS
		);

		return array(
			'[site-name]'   => wp_specialchars_decode(get_option('blogname'), ENT_QUOTES),
			'[code]'        => $code,
			'[user-login]'  => $user_login,
			'[expires]'     => $expires,
			'[site-url]'    => home_url('/'),
		);
	}

	private static function default_body_template(): string {
		/* translators: Placeholder [expires] is replaced at send time; do not translate bracket tokens. */
		$expiry_line = esc_html__('Valid until [expires].', 'wp-dual-check');

		return '<p>' . esc_html__('Your sign-in code is:', 'wp-dual-check') . '</p>'
			. '<p style="font-size:24px;font-weight:700;letter-spacing:0.08em;margin:16px 0;">[code]</p>'
			. '<p>' . $expiry_line . '</p>'
			. '<p>' . esc_html__('Account: [user-login]', 'wp-dual-check') . '</p>'
			. '<p>' . esc_html__('Site: [site-url]', 'wp-dual-check') . '</p>';
	}

	/**
	 * @param array<string, mixed>   $settings
	 * @param array<string, string> $ctx Raw values for header/footer placeholders.
	 */
	private static function build_html_wrapper(array $settings, string $inner_html, array $ctx): string {
		$link   = isset($settings['email_color_link']) ? (string) $settings['email_color_link'] : '#2271b1';
		$head_bg = isset($settings['email_color_header_bg']) ? (string) $settings['email_color_header_bg'] : '#2271b1';
		$foot_bg = isset($settings['email_color_footer_bg']) ? (string) $settings['email_color_footer_bg'] : '#f0f0f1';

		$link    = sanitize_hex_color($link) ?: '#2271b1';
		$head_bg = sanitize_hex_color($head_bg) ?: '#2271b1';
		$foot_bg = sanitize_hex_color($foot_bg) ?: '#f0f0f1';

		$html_vals = self::html_placeholder_values($ctx);

		if (!self::use_custom_email_template($settings)) {
			$header_raw = self::default_template_part('header');
			$footer_raw = self::default_template_part('footer');
			$header_inner = $header_raw !== ''
				? wp_kses_post(self::replace_tokens($header_raw, $html_vals))
				: '<p style="margin:0;font-size:16px;font-weight:600;">' . esc_html__('Security code', 'wp-dual-check') . '</p>';
			$footer_inner = $footer_raw !== ''
				? wp_kses_post(self::replace_tokens($footer_raw, $html_vals))
				: '<p style="margin:0;font-size:12px;color:#50575e;">' . esc_html__('If you did not try to sign in, you can ignore this email.', 'wp-dual-check') . '</p>';
		} else {
			$header_raw = isset($settings['email_header_html']) ? trim((string) $settings['email_header_html']) : '';
			$footer_raw = isset($settings['email_footer_html']) ? trim((string) $settings['email_footer_html']) : '';
			$header_inner = $header_raw !== ''
				? wp_kses_post(self::replace_tokens($header_raw, $html_vals))
				: '<p style="margin:0;font-size:16px;font-weight:600;">' . esc_html__('Security code', 'wp-dual-check') . '</p>';
			$footer_inner = $footer_raw !== ''
				? wp_kses_post(self::replace_tokens($footer_raw, $html_vals))
				: '<p style="margin:0;font-size:12px;color:#50575e;">' . esc_html__('If you did not try to sign in, you can ignore this email.', 'wp-dual-check') . '</p>';
		}

		$inner_safe = wp_kses_post($inner_html);

		$link_esc  = esc_attr($link);
		$head_esc  = esc_attr($head_bg);
		$foot_esc  = esc_attr($foot_bg);

		return '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" /></head>'
			. '<body style="margin:0;padding:0;background:#f0f0f1;">'
			. '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f0f0f1;padding:24px 12px;">'
			. '<tr><td align="center">'
			. '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:560px;background:#ffffff;border-radius:4px;overflow:hidden;border:1px solid #dcdcde;">'
			. '<tr><td bgcolor="' . $head_esc . '" style="background:' . esc_attr($head_bg) . ';color:#ffffff;padding:18px 20px;">' . $header_inner . '</td></tr>'
			. '<tr><td style="padding:24px 20px;color:#1d2327;font-size:15px;line-height:1.5;" class="wpdc-mail-body">'
			. '<style type="text/css">.wpdc-mail-body a{color:' . $link_esc . ';}</style>'
			. $inner_safe
			. '</td></tr>'
			. '<tr><td bgcolor="' . $foot_esc . '" style="background:' . esc_attr($foot_bg) . ';padding:14px 20px;color:#1d2327;">' . $footer_inner . '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}
}
