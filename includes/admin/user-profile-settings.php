<?php

namespace WP_DUAL_CHECK\admin;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Optional profile field: alternate email for 2FA codes (user meta).
 */
class User_Profile_Settings {

	public const META_KEY = 'wp_dual_check_2fa_email';

	/**
	 * Hooks profile display and save handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action('show_user_profile', array($this, 'render_field'));
		add_action('edit_user_profile', array($this, 'render_field'));
		add_action('personal_options_update', array($this, 'save_field'));
		add_action('edit_user_profile_update', array($this, 'save_field'));
	}

	/**
	 * Whether the site allows users to set a separate 2FA delivery email.
	 *
	 * @return bool
	 */
	public static function profile_field_enabled(): bool {
		$options = wp_parse_args(get_option(Settings_Page::OPTION_NAME, array()), Settings_Page::defaults());

		return !empty($options['allow_profile_2fa_email']);
	}

	/**
	 * Address to send codes to: override meta if set and valid, else account email.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Empty if the user does not exist.
	 */
	public static function get_delivery_email(int $user_id): string {
		$user = get_userdata($user_id);
		if (!$user) {
			return '';
		}
		if (self::profile_field_enabled()) {
			$override = get_user_meta($user_id, self::META_KEY, true);
			if (is_string($override) && $override !== '' && is_email($override)) {
				return $override;
			}
		}

		return (string) $user->user_email;
	}

	/**
	 * Outputs the optional “email for security codes” field when enabled and permitted.
	 *
	 * @param \WP_User $user User being edited or viewed.
	 * @return void
	 */
	public function render_field($user): void {
		if (!self::profile_field_enabled()) {
			return;
		}
		if (!($user instanceof \WP_User)) {
			return;
		}
		if (!is_user_logged_in() || !current_user_can('edit_user', $user->ID)) {
			return;
		}

		$value = get_user_meta($user->ID, self::META_KEY, true);
		$value = is_string($value) ? $value : '';

		echo '<h2>' . esc_html__('Dual-check email', 'wp-dual-check') . '</h2>';
		echo '<table class="form-table" role="presentation"><tr>';
		echo '<th scope="row"><label for="dual_check_2fa_email">' . esc_html__('Email for security codes', 'wp-dual-check') . '</label></th>';
		echo '<td>';
		printf(
			'<input type="email" class="regular-text" name="dual_check_2fa_email" id="dual_check_2fa_email" value="%s" autocomplete="email" />',
			esc_attr($value)
		);
		echo '<p class="description">' . esc_html__('Leave blank to use your normal account email.', 'wp-dual-check') . '</p>';
		echo '</td></tr></table>';
	}

	/**
	 * Saves or clears the user meta override from the profile form.
	 *
	 * @param int $user_id User ID from the profile save action.
	 * @return void
	 */
	public function save_field($user_id): void {
		if (!self::profile_field_enabled()) {
			return;
		}
		if (!is_user_logged_in()) {
			return;
		}
		$user_id = (int) $user_id;
		if ($user_id <= 0) {
			return;
		}
		if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_wpnonce'])), 'update-user_' . $user_id)) {
			return;
		}
		if (!current_user_can('edit_user', $user_id)) {
			return;
		}
		if (!isset($_POST['dual_check_2fa_email'])) {
			return;
		}

		$email = sanitize_email(wp_unslash((string) $_POST['dual_check_2fa_email']));
		if ($email === '') {
			delete_user_meta($user_id, self::META_KEY);

			return;
		}
		if (!is_email($email)) {
			return;
		}

		update_user_meta($user_id, self::META_KEY, $email);
	}
}
