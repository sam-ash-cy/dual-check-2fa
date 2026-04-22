<?php
/**
 * Default login email strings when “Use custom HTML” is off.
 * One file: one function per part (subject, body, header, footer).
 *
 * @package DualCheck2FA
 */

defined('ABSPATH') || exit;

/**
 * Plain subject line (placeholders replaced at send time).
 *
 * @return string
 */
function dual_check_2fa_email_default_subject(): string {
	return '[site-name] ' . __('Your login security code', 'dual-check-2fa');
}

/**
 * HTML body fragment (inner table cell).
 *
 * @return string
 */
function dual_check_2fa_email_default_body(): string {
	/* translators: Placeholder [expires] is replaced at send time. */
	$expiry_line = esc_html__('Valid until [expires].', 'dual-check-2fa');

	return '<p>' . esc_html__('Your sign-in code is:', 'dual-check-2fa') . '</p>'
		. '<p style="font-size:24px;font-weight:700;letter-spacing:0.08em;margin:16px 0;">[code]</p>'
		. '<p>' . $expiry_line . '</p>'
		. '<p>' . esc_html__('Account: [user-login]', 'dual-check-2fa') . '</p>'
		. '<p>' . esc_html__('Site: [site-url]', 'dual-check-2fa') . '</p>';
}

/**
 * Header bar inner HTML.
 *
 * @return string
 */
function dual_check_2fa_email_default_header(): string {
	return '<p style="margin:0;font-size:16px;font-weight:600;">' . esc_html__('Security code', 'dual-check-2fa') . '</p>';
}

/**
 * Footer strip inner HTML.
 *
 * @return string
 */
function dual_check_2fa_email_default_footer(): string {
	return '<p style="margin:0;font-size:12px;color:#50575e;">' . esc_html__('If you did not try to sign in, you can ignore this email.', 'dual-check-2fa') . '</p>';
}
