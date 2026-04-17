<?php
/**
 * Default login email subject when “Use custom login email template” is off.
 * Placeholders: [site-name], [code], [user-login], [expires], [site-url], [minutes], [timezone]
 *
 * @package WP_DUAL_CHECK
 */

defined('ABSPATH') || exit;

return '[site-name] ' . __('Your login security code', 'wp-dual-check');
