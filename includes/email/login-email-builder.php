<?php

namespace DualCheck2FA\email;

use DualCheck2FA\core\Plugin;
use function DualCheck2FA\db\dual_check_settings;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Builds HTML login code emails from settings and placeholder tokens.
 *
 * When {@see use_custom_email_template()} is false, bundled defaults are used only; saved custom strings
 * and saved colours are not applied to outbound mail.
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
		'subject' => 'dual_check_2fa_email_default_subject',
		'body'    => 'dual_check_2fa_email_default_body',
		'header'  => 'dual_check_2fa_email_default_header',
		'footer'  => 'dual_check_2fa_email_default_footer',
	);

	/**
	 * Builds subject and full HTML for a login code email.
	 *
	 * @param string $code        Plaintext code to embed.
	 * @param string $user_login  Account login for placeholders.
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

	/**
	 * Whether saved custom subject/body/header/footer should be used instead of bundled defaults.
	 *
	 * @param array<string, mixed> $settings Merged plugin options.
	 * @return bool
	 */
	private static function use_custom_email_template(array $settings): bool {
		return !empty($settings['email_use_custom_template']);
	}

	/**
	 * Loads `templates/email/default-template.php` once and returns one part (subject/body/header/footer).
	 *
	 * @param string $part Key in {@see Login_Email_Builder::DEFAULT_TEMPLATE_FN}.
	 * @return string Trimmed string, or empty if missing.
	 */
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
	 * Builds the subject line with placeholders stripped to plain text.
	 *
	 * @param array<string, mixed>   $settings Merged plugin options.
	 * @param array<string, string> $ctx      Placeholder values from {@see context_values()}.
	 * @return string
	 */
	private static function build_subject(array $settings, array $ctx): string {
		if (!self::use_custom_email_template($settings)) {
			$tpl = self::default_template_part('subject');
			if ($tpl === '') {
				return sprintf(
					/* translators: %s: site name */
					__('[%s] Your login security code', 'dual-check-2fa'),
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
				__('[%s] Your login security code', 'dual-check-2fa'),
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
	 * Builds the inner HTML fragment (inside the styled wrapper) with escaped placeholders.
	 *
	 * @param array<string, mixed>   $settings Merged plugin options.
	 * @param array<string, string> $ctx      Raw placeholder values.
	 * @return string
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
	 * Maps raw placeholder values to HTML-safe replacements per token type.
	 *
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

	/**
	 * Escapes a placeholder value for safe HTML output (URL vs text).
	 *
	 * @param string $token Placeholder key, e.g. `[site-url]`.
	 * @param string $value Raw replacement.
	 * @return string
	 */
	private static function escape_placeholder_for_html(string $token, string $value): string {
		if ($token === '[site-url]') {
			return esc_url($value);
		}

		return esc_html($value);
	}

	/**
	 * Case-insensitive token replacement in a template string.
	 *
	 * @param string               $template Original text/HTML.
	 * @param array<string, string> $pairs    Token => replacement (already escaped for context).
	 * @return string
	 */
	private static function replace_tokens(string $template, array $pairs): string {
		$out = $template;
		foreach ($pairs as $token => $replacement) {
			$out = str_ireplace($token, $replacement, $out);
		}

		return $out;
	}

	/**
	 * Builds placeholder map for the current send (site name, code, human expiry, etc.).
	 *
	 * @param string $code        Plaintext code.
	 * @param string $user_login  Username for templates.
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

	/**
	 * Fallback inner HTML when neither custom nor default-template body is available.
	 *
	 * @return string
	 */
	private static function default_body_template(): string {
		/* translators: Placeholder [expires] is replaced at send time; do not translate bracket tokens. */
		$expiry_line = esc_html__('Valid until [expires].', 'dual-check-2fa');

		return '<p>' . esc_html__('Your sign-in code is:', 'dual-check-2fa') . '</p>'
			. '<p style="font-size:24px;font-weight:700;letter-spacing:0.08em;margin:16px 0;">[code]</p>'
			. '<p>' . $expiry_line . '</p>'
			. '<p>' . esc_html__('Account: [user-login]', 'dual-check-2fa') . '</p>'
			. '<p>' . esc_html__('Site: [site-url]', 'dual-check-2fa') . '</p>';
	}

	/**
	 * Wraps inner HTML in a simple responsive table layout with header/footer strips.
	 *
	 * @param array<string, mixed>   $settings   Colours and optional custom header/footer HTML.
	 * @param string                 $inner_html Body fragment (already token-replaced).
	 * @param array<string, string> $ctx        Raw values for header/footer placeholders.
	 * @return string Full HTML document string.
	 */
	private static function build_html_wrapper(array $settings, string $inner_html, array $ctx): string {
		if (!self::use_custom_email_template($settings)) {
			$link    = '#2271b1';
			$head_bg = '#2271b1';
			$foot_bg = '#f0f0f1';
		} else {
			$link    = isset($settings['email_color_link']) ? (string) $settings['email_color_link'] : '#2271b1';
			$head_bg = isset($settings['email_color_header_bg']) ? (string) $settings['email_color_header_bg'] : '#2271b1';
			$foot_bg = isset($settings['email_color_footer_bg']) ? (string) $settings['email_color_footer_bg'] : '#f0f0f1';

			$link    = sanitize_hex_color($link) ?: '#2271b1';
			$head_bg = sanitize_hex_color($head_bg) ?: '#2271b1';
			$foot_bg = sanitize_hex_color($foot_bg) ?: '#f0f0f1';
		}

		$html_vals = self::html_placeholder_values($ctx);

		if (!self::use_custom_email_template($settings)) {
			$header_raw = self::default_template_part('header');
			$footer_raw = self::default_template_part('footer');
			$header_inner = $header_raw !== ''
				? wp_kses_post(self::replace_tokens($header_raw, $html_vals))
				: '<p style="margin:0;font-size:16px;font-weight:600;">' . esc_html__('Security code', 'dual-check-2fa') . '</p>';
			$footer_inner = $footer_raw !== ''
				? wp_kses_post(self::replace_tokens($footer_raw, $html_vals))
				: '<p style="margin:0;font-size:12px;color:#50575e;">' . esc_html__('If you did not try to sign in, you can ignore this email.', 'dual-check-2fa') . '</p>';
		} else {
			$header_raw = isset($settings['email_header_html']) ? trim((string) $settings['email_header_html']) : '';
			$footer_raw = isset($settings['email_footer_html']) ? trim((string) $settings['email_footer_html']) : '';
			$header_inner = $header_raw !== ''
				? wp_kses_post(self::replace_tokens($header_raw, $html_vals))
				: '<p style="margin:0;font-size:16px;font-weight:600;">' . esc_html__('Security code', 'dual-check-2fa') . '</p>';
			$footer_inner = $footer_raw !== ''
				? wp_kses_post(self::replace_tokens($footer_raw, $html_vals))
				: '<p style="margin:0;font-size:12px;color:#50575e;">' . esc_html__('If you did not try to sign in, you can ignore this email.', 'dual-check-2fa') . '</p>';
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
			. '<tr><td style="padding:24px 20px;color:#1d2327;font-size:15px;line-height:1.5;" class="dc2fa-mail-body">'
			. '<style type="text/css">.dc2fa-mail-body a{color:' . $link_esc . ';}</style>'
			. $inner_safe
			. '</td></tr>'
			. '<tr><td bgcolor="' . $foot_esc . '" style="background:' . esc_attr($foot_bg) . ';padding:14px 20px;color:#1d2327;">' . $footer_inner . '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}
}
