<?php
/**
 * Default login email strings when “Use custom HTML” is off.
 * One file: one function per part (subject, body, header, footer).
 *
 * @package WP_DUAL_CHECK
 */

defined('ABSPATH') || exit;

/**
 * Plain subject line (placeholders replaced at send time).
 */
function wp_dual_check_email_default_subject(): string {
	return '[site-name] ' . __('Your login security code', 'wp-dual-check');
}

/**
 * HTML body fragment (inner table cell).
 */
function wp_dual_check_email_default_body(): string {
	/* translators: Placeholder [expires] is replaced at send time. */
	$expiry_line = esc_html__('Valid until [expires].', 'wp-dual-check');

	return '<p>' . esc_html__('Your sign-in code is:', 'wp-dual-check') . '</p>'
		. '<p style="font-size:24px;font-weight:700;letter-spacing:0.08em;margin:16px 0;">[code]</p>'
		. '<p>' . $expiry_line . '</p>'
		. '<p>' . esc_html__('Account: [user-login]', 'wp-dual-check') . '</p>'
		. '<p>' . esc_html__('Site: [site-url]', 'wp-dual-check') . '</p>';
}

/** Header bar inner HTML. */
function wp_dual_check_email_default_header(): string {
	return '<p style="margin:0;font-size:16px;font-weight:600;">' . esc_html__('Security code', 'wp-dual-check') . '</p>';
}

/** Footer strip inner HTML. */
function wp_dual_check_email_default_footer(): string {
	return '<p style="margin:0;font-size:12px;color:#50575e;">' . esc_html__('If you did not try to sign in, you can ignore this email.', 'wp-dual-check') . '</p>';
}
