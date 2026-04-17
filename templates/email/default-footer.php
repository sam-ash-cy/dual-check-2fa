<?php
/**
 * Default footer strip inner HTML when custom template is off.
 *
 * @package WP_DUAL_CHECK
 */

defined('ABSPATH') || exit;

return '<p style="margin:0;font-size:12px;color:#50575e;">' . esc_html__('If you did not try to sign in, you can ignore this email.', 'wp-dual-check') . '</p>';
