<?php
/**
 * Email body: $code (plain), optional $user_login, optional $expires_local (readable string in site timezone).
 *
 * @package WP_DUAL_CHECK
 */

defined('ABSPATH') || exit;

$code_safe = isset($code) ? (string) $code : '';
?>
<p><strong><?php echo esc_html($code_safe); ?></strong></p>
<p><?php esc_html_e('Enter the code above on the security code page after you sign in.', 'wp-dual-check'); ?></p>
<?php if (!empty($user_login)) : ?>
	<p><?php echo esc_html(sprintf(__('Account: %s', 'wp-dual-check'), (string) $user_login)); ?></p>
<?php endif; ?>
<?php if (!empty($expires_local)) : ?>
	<p><?php echo esc_html(sprintf(__('This code is valid until %s (%s).', 'wp-dual-check'), (string) $expires_local, wp_timezone_string())); ?></p>
<?php endif; ?>
