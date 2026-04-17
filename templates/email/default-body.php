<?php
/**
 * Default login email body fragment (HTML) when custom template is off.
 * Placeholders are replaced at send time; do not remove bracket tokens you need.
 *
 * @package WP_DUAL_CHECK
 */

defined('ABSPATH') || exit;

/* translators: Placeholders [expires] and [timezone] are replaced at send time. */
$expiry_line = esc_html__('Valid until [expires] ([timezone]).', 'wp-dual-check');

return '<p>' . esc_html__('Your sign-in code is:', 'wp-dual-check') . '</p>'
	. '<p style="font-size:24px;font-weight:700;letter-spacing:0.08em;margin:16px 0;">[code]</p>'
	. '<p>' . $expiry_line . '</p>'
	. '<p>' . esc_html__('Account: [user-login]', 'wp-dual-check') . '</p>'
	. '<p>' . esc_html__('Site: [site-url]', 'wp-dual-check') . '</p>';
