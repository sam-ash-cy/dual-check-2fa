<?php
/**
 * Default header bar inner HTML when custom template is off.
 *
 * @package WP_DUAL_CHECK
 */

defined('ABSPATH') || exit;

return '<p style="margin:0;font-size:16px;font-weight:600;">' . esc_html__('Security code', 'wp-dual-check') . '</p>';
