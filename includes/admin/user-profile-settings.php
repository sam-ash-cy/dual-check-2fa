<?php

namespace DualCheck2FA\admin;

use DualCheck2FA\auth\Trusted_Device;
use DualCheck2FA\core\Security;
use function DualCheck2FA\db\get_trusted_devices_table_name;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Optional profile field: alternate email for 2FA codes (user meta).
 */
class User_Profile_Settings {

	public const META_KEY = 'dual_check_2fa_email';

	/**
	 * Hooks profile display and save handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action('show_user_profile', array($this, 'render_field'));
		add_action('edit_user_profile', array($this, 'render_field'));
		add_action('show_user_profile', array($this, 'render_exempt_field'));
		add_action('edit_user_profile', array($this, 'render_exempt_field'));
		add_action('personal_options_update', array($this, 'save_field'));
		add_action('edit_user_profile_update', array($this, 'save_field'));
		add_action('personal_options_update', array($this, 'save_exempt_field'));
		add_action('edit_user_profile_update', array($this, 'save_exempt_field'));
		add_action('admin_post_dual_check_2fa_revoke_trusted_device', array($this, 'handle_revoke_trusted_device'));
		add_action('admin_post_dual_check_2fa_revoke_all_trusted', array($this, 'handle_revoke_all_trusted'));
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
		if (!($user instanceof \WP_User)) {
			return;
		}
		if (!is_user_logged_in() || !current_user_can('edit_user', $user->ID)) {
			return;
		}

		if (self::profile_field_enabled()) {
			$value = get_user_meta($user->ID, self::META_KEY, true);
			$value = is_string($value) ? $value : '';

			echo '<h2>' . esc_html__('Dual-check email', 'dual-check-2fa') . '</h2>';
			echo '<table class="form-table" role="presentation"><tr>';
			echo '<th scope="row"><label for="dual_check_2fa_email">' . esc_html__('Email for security codes', 'dual-check-2fa') . '</label></th>';
			echo '<td>';
			printf(
				'<input type="email" class="regular-text" name="dual_check_2fa_email" id="dual_check_2fa_email" value="%s" autocomplete="email" />',
				esc_attr($value)
			);
			echo '<p class="description">' . esc_html__('Leave blank to use your normal account email.', 'dual-check-2fa') . '</p>';
			echo '</td></tr></table>';
		}

		$this->render_trusted_devices_section($user);
	}

	/**
	 * Per-user exemption checkbox for users who may access Dual Check main settings.
	 * Renders on {@code profile.php} ({@code show_user_profile}) and {@code user-edit.php} ({@code edit_user_profile}).
	 *
	 * @param \WP_User $user User being edited.
	 * @return void
	 */
	public function render_exempt_field($user): void {
		if (!($user instanceof \WP_User)) {
			return;
		}
		if (!User_Exemption::feature_enabled() || !Security::can_access_main_settings()) {
			return;
		}
		if (!is_user_logged_in() || !current_user_can('edit_user', $user->ID)) {
			return;
		}

		$checked = get_user_meta($user->ID, User_Exemption::META_KEY, true) === '1';
		echo '<h2>' . esc_html__('Dual Check 2FA Exemption', 'dual-check-2fa') . '</h2>';
		echo '<table class="form-table" role="presentation"><tr>';
		echo '<th scope="row">' . esc_html__('Second factor', 'dual-check-2fa') . '</th><td>';
		wp_nonce_field('dual_check_2fa_exempt_save', 'dual_check_2fa_exempt_nonce');
		printf(
			'<label><input type="checkbox" name="dual_check_2fa_exempted" value="1" %s /> %s</label>',
			checked($checked, true, false),
			esc_html__('Exempt this user from 2FA', 'dual-check-2fa')
		);
		echo '</td></tr></table>';
	}

	/**
	 * @param \WP_User $user User.
	 * @return void
	 */
	private function render_trusted_devices_section(\WP_User $user): void {
		if (!Trusted_Device::feature_enabled()) {
			return;
		}
		if (!is_user_logged_in() || !current_user_can('edit_user', $user->ID)) {
			return;
		}

	global $wpdb;
	$table = get_trusted_devices_table_name();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from get_trusted_devices_table_name(); direct query on custom table is intentional.
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, label, last_used_at, expires_at FROM `{$table}` WHERE user_id = %d ORDER BY id DESC",
			(int) $user->ID
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if (!is_array($rows)) {
			$rows = array();
		}

		echo '<h2>' . esc_html__('Trusted devices', 'dual-check-2fa') . '</h2>';
		if ($rows === array()) {
			echo '<p>' . esc_html__('No remembered browsers for this account.', 'dual-check-2fa') . '</p>';

			return;
		}

		$revoke_all = wp_nonce_url(
			admin_url('admin-post.php?action=dual_check_2fa_revoke_all_trusted&user_id=' . (int) $user->ID),
			'revoke_all_trusted_' . (int) $user->ID
		);
		echo '<p><a class="button" href="' . esc_url($revoke_all) . '">' . esc_html__('Revoke all remembered browsers', 'dual-check-2fa') . '</a></p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__('Label', 'dual-check-2fa') . '</th>';
		echo '<th>' . esc_html__('Last used', 'dual-check-2fa') . '</th>';
		echo '<th>' . esc_html__('Expires', 'dual-check-2fa') . '</th>';
		echo '<th>' . esc_html__('Actions', 'dual-check-2fa') . '</th>';
		echo '</tr></thead><tbody>';
		foreach ($rows as $row) {
			if (!is_array($row) || empty($row['id'])) {
				continue;
			}
			$rid = (int) $row['id'];
			$url = wp_nonce_url(
				admin_url('admin-post.php?action=dual_check_2fa_revoke_trusted_device&user_id=' . (int) $user->ID . '&row_id=' . $rid),
				'revoke_trusted_' . $rid . '_' . (int) $user->ID
			);
			echo '<tr>';
			echo '<td>' . esc_html((string) ($row['label'] ?? '')) . '</td>';
			echo '<td>' . esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) ($row['last_used_at'] ?? ''), true)) . '</td>';
			echo '<td>' . esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) ($row['expires_at'] ?? ''), true)) . '</td>';
			echo '<td><a href="' . esc_url($url) . '">' . esc_html__('Revoke', 'dual-check-2fa') . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
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

	/**
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function save_exempt_field($user_id): void {
		if (!User_Exemption::feature_enabled() || !Security::can_access_main_settings()) {
			return;
		}
		$user_id = (int) $user_id;
		if ($user_id <= 0 || !current_user_can('edit_user', $user_id)) {
			return;
		}
		if (!isset($_POST['dual_check_2fa_exempt_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['dual_check_2fa_exempt_nonce'])), 'dual_check_2fa_exempt_save')) {
			return;
		}
		if (!empty($_POST['dual_check_2fa_exempted'])) {
			update_user_meta($user_id, User_Exemption::META_KEY, '1');
		} else {
			delete_user_meta($user_id, User_Exemption::META_KEY);
		}
	}

	/**
	 * @return void
	 */
	public function handle_revoke_trusted_device(): void {
		if (!is_user_logged_in()) {
			wp_die(esc_html__('You must be logged in.', 'dual-check-2fa'), '', 403);
		}
		$user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
		$row_id   = isset($_GET['row_id']) ? absint($_GET['row_id']) : 0;
		check_admin_referer('revoke_trusted_' . $row_id . '_' . $user_id);
		if (!current_user_can('edit_user', $user_id)) {
			wp_die(esc_html__('You do not have permission.', 'dual-check-2fa'), '', 403);
		}
		Trusted_Device::revoke_by_row_id($row_id, $user_id);
		wp_safe_redirect(get_edit_user_link($user_id));

		exit;
	}

	/**
	 * @return void
	 */
	public function handle_revoke_all_trusted(): void {
		if (!is_user_logged_in()) {
			wp_die(esc_html__('You must be logged in.', 'dual-check-2fa'), '', 403);
		}
		$user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
		check_admin_referer('revoke_all_trusted_' . $user_id);
		if (!current_user_can('edit_user', $user_id)) {
			wp_die(esc_html__('You do not have permission.', 'dual-check-2fa'), '', 403);
		}
		Trusted_Device::revoke_all_for_user($user_id);
		wp_safe_redirect(get_edit_user_link($user_id));

		exit;
	}
}
