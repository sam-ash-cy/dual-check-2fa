<?php

namespace WP_DUAL_CHECK\admin;

use WP_DUAL_CHECK\core\Security;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Persists {@see Settings_Page::OPTION_NAME} via admin-post.php with explicit OR capability checks,
 * instead of options.php (which only accepts {@see 'manage_options'} unless filtered).
 */
final class Settings_Save_Handler {

	public const ACTION = 'wp_dual_check_save_settings';

	/**
	 * @return void
	 */
	public function register(): void {
		add_action('admin_post_' . self::ACTION, array($this, 'handle'));
	}

	/**
	 * @return void
	 */
	public function handle(): void {
		if (!is_user_logged_in()) {
			wp_die(esc_html__('You must be logged in to save settings.', 'wp-dual-check'), esc_html__('Error', 'wp-dual-check'), array('response' => 403));
		}

		check_admin_referer(self::ACTION, 'wp_dual_check_save_nonce');

		$key = Settings_Page::OPTION_NAME;
		if (!isset($_POST[ $key ]) || !is_array($_POST[ $key ])) {
			wp_die(esc_html__('Invalid form submission.', 'wp-dual-check'), esc_html__('Error', 'wp-dual-check'), array('response' => 400));
		}

		$input = wp_unslash($_POST[ $key ]);
		$ctx   = isset($input['save_context']) ? sanitize_key((string) $input['save_context']) : 'main';

		if ($ctx === 'email') {
			if (!Security::can_access_email_template() || !Email_Settings_Page::is_custom_template_enabled()) {
				wp_die(esc_html__('You do not have permission to save these settings.', 'wp-dual-check'), esc_html__('Error', 'wp-dual-check'), array('response' => 403));
			}
		} elseif ($ctx === 'permissions') {
			if (!Security::can_access_main_settings()) {
				wp_die(esc_html__('You do not have permission to save these settings.', 'wp-dual-check'), esc_html__('Error', 'wp-dual-check'), array('response' => 403));
			}
		} else {
			if (!Security::can_access_main_settings()) {
				wp_die(esc_html__('You do not have permission to save these settings.', 'wp-dual-check'), esc_html__('Error', 'wp-dual-check'), array('response' => 403));
			}
		}

		$sanitized = (new Settings_Page())->sanitize($input);
		update_option($key, $sanitized);

		if (!count(get_settings_errors())) {
			add_settings_error(
				'general',
				'settings_updated',
				__('Settings saved.', 'wp-dual-check'),
				'success'
			);
		}

		set_transient('settings_errors', get_settings_errors(), 30);

		$return = isset($_POST['wp_dual_check_return'])
			? sanitize_key((string) wp_unslash($_POST['wp_dual_check_return']))
			: Settings_Page::MENU_SLUG;
		if (!in_array($return, self::allowed_return_pages(), true)) {
			$return = Settings_Page::MENU_SLUG;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => $return,
					'settings-updated' => 'true',
				),
				admin_url('admin.php')
			)
		);
		exit;
	}

	/**
	 * Admin page slugs that may receive the redirect after save.
	 *
	 * @return array<int, string>
	 */
	public static function allowed_return_pages(): array {
		return array(
			Settings_Page::MENU_SLUG,
			Email_Settings_Page::PAGE,
			Permissions_Settings_Page::PAGE,
		);
	}

	/**
	 * Opens a form that posts to {@see self::ACTION}.
	 *
	 * @param string $return_page `page` query value for redirect (must be in {@see allowed_return_pages()}).
	 * @return void
	 */
	public static function render_form_open(string $return_page): void {
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field(self::ACTION, 'wp_dual_check_save_nonce');
		echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';
		echo '<input type="hidden" name="wp_dual_check_return" value="' . esc_attr($return_page) . '" />';
	}
}
