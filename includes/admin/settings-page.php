<?php

namespace WP_DUAL_CHECK\admin;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Settings → WP Dual Check (manage_options only).
 */
class Settings_Page {

	public const OPTION_NAME = 'wp_dual_check_settings';

	public const MENU_SLUG = 'wp-dual-check';

	public function register(): void {
		add_action('admin_menu', array($this, 'add_menu'));
		add_action('admin_init', array($this, 'register_settings'));
	}

	public function add_menu(): void {
		add_options_page(
			__('WP Dual Check', 'wp-dual-check'),
			__('WP Dual Check', 'wp-dual-check'),
			'manage_options',
			self::MENU_SLUG,
			array($this, 'render_page')
		);
	}

	public function register_settings(): void {
		register_setting(
			'wp_dual_check_settings_group',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array($this, 'sanitize'),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'wp_dual_check_main',
			__('General', 'wp-dual-check'),
			static function (): void {
				echo '<p>' . esc_html__('Basic options for dual-check codes. Hook your login flow into these values when you build it.', 'wp-dual-check') . '</p>';
			},
			self::MENU_SLUG
		);

		add_settings_field(
			'code_lifetime_minutes',
			__('Code lifetime (minutes)', 'wp-dual-check'),
			array($this, 'field_code_lifetime'),
			self::MENU_SLUG,
			'wp_dual_check_main'
		);

		add_settings_field(
			'max_attempts',
			__('Max check attempts', 'wp-dual-check'),
			array($this, 'field_max_attempts'),
			self::MENU_SLUG,
			'wp_dual_check_main'
		);

		add_settings_field(
			'code_length',
			__('Generated code length', 'wp-dual-check'),
			array($this, 'field_code_length'),
			self::MENU_SLUG,
			'wp_dual_check_main'
		);

		add_settings_section(
			'wp_dual_check_policy',
			__('Login policy', 'wp-dual-check'),
			static function (): void {
				echo '<p>' . esc_html__('Your login integration should read these flags when you wire it up.', 'wp-dual-check') . '</p>';
			},
			self::MENU_SLUG
		);

		add_settings_field(
			'require_2fa_all_users',
			__('Require dual-check for everyone', 'wp-dual-check'),
			array($this, 'field_require_2fa_all_users'),
			self::MENU_SLUG,
			'wp_dual_check_policy'
		);

		add_settings_section(
			'wp_dual_check_profiles',
			__('User profiles', 'wp-dual-check'),
			static function (): void {
				echo '<p>' . esc_html__('Control optional fields on the WordPress user profile screen.', 'wp-dual-check') . '</p>';
			},
			self::MENU_SLUG
		);

		add_settings_field(
			'allow_profile_2fa_email',
			__('2FA delivery email on profile', 'wp-dual-check'),
			array($this, 'field_allow_profile_2fa_email'),
			self::MENU_SLUG,
			'wp_dual_check_profiles'
		);
	}

	/**
	 * @param array<string, mixed>|null $input
	 * @return array<string, int>
	 */
	public function sanitize($input): array {
		$out = self::defaults();
		if (!is_array($input)) {
			return $out;
		}
		if (isset($input['code_lifetime_minutes'])) {
			$out['code_lifetime_minutes'] = max(1, min(120, absint($input['code_lifetime_minutes'])));
		}
		if (isset($input['max_attempts'])) {
			$out['max_attempts'] = max(1, min(20, absint($input['max_attempts'])));
		}
		if (isset($input['code_length'])) {
			$out['code_length'] = max(6, min(64, absint($input['code_length'])));
		}
		$out['allow_profile_2fa_email'] = !empty($input['allow_profile_2fa_email']) ? 1 : 0;
		$out['require_2fa_all_users']   = !empty($input['require_2fa_all_users']) ? 1 : 0;

		return $out;
	}

	public function field_code_lifetime(): void {
		$options = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());
		$value   = (int) $options['code_lifetime_minutes'];
		printf(
			'<input type="number" name="%1$s[code_lifetime_minutes]" value="%2$d" min="1" max="120" class="small-text" />',
			esc_attr(self::OPTION_NAME),
			$value
		);
	}

	public function field_max_attempts(): void {
		$options = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());
		$value   = (int) $options['max_attempts'];
		printf(
			'<input type="number" name="%1$s[max_attempts]" value="%2$d" min="1" max="20" class="small-text" />',
			esc_attr(self::OPTION_NAME),
			$value
		);
		echo '<p class="description">' . esc_html__('Site-wide limit. Each token row stores its own count in the database attempts column until it hits this cap.', 'wp-dual-check') . '</p>';
	}

	public function field_code_length(): void {
		$options = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());
		$value   = (int) $options['code_length'];
		printf(
			'<input type="number" name="%1$s[code_length]" value="%2$d" min="6" max="64" class="small-text" />',
			esc_attr(self::OPTION_NAME),
			$value
		);
		echo '<p class="description">' . esc_html__('Length of the random code sent to the user (not the hash stored in the database).', 'wp-dual-check') . '</p>';
	}

	public function field_allow_profile_2fa_email(): void {
		$options = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());
		$checked = !empty($options['allow_profile_2fa_email']);
		printf(
			'<label><input type="checkbox" name="%1$s[allow_profile_2fa_email]" value="1" %2$s /> %3$s</label>',
			esc_attr(self::OPTION_NAME),
			checked($checked, true, false),
			esc_html__('Let each user set a separate email address for dual-check codes on their profile.', 'wp-dual-check')
		);
		echo '<p class="description">' . esc_html__('Stored in user meta; if they leave it blank, codes use the normal account email.', 'wp-dual-check') . '</p>';
	}

	public function field_require_2fa_all_users(): void {
		$options = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());
		$checked = !empty($options['require_2fa_all_users']);
		printf(
			'<label><input type="checkbox" name="%1$s[require_2fa_all_users]" value="1" %2$s /> %3$s</label>',
			esc_attr(self::OPTION_NAME),
			checked($checked, true, false),
			esc_html__('Every user must complete the second step after password.', 'wp-dual-check')
		);
		echo '<p class="description">' . esc_html__('Off by default so installing the plugin does not lock anyone out. When off, this option alone does not disable codes—you simply do not require them for every login until your flow checks this setting.', 'wp-dual-check') . '</p>';
	}

	public function render_page(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-dual-check'));
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__('WP Dual Check', 'wp-dual-check') . '</h1>';
		echo '<form action="options.php" method="post">';
		settings_fields('wp_dual_check_settings_group');
		do_settings_sections(self::MENU_SLUG);
		submit_button(__('Save changes', 'wp-dual-check'));
		echo '</form></div>';
	}

	/**
	 * @return array{code_lifetime_minutes: int, max_attempts: int, code_length: int, allow_profile_2fa_email: int, require_2fa_all_users: int}
	 */
	public static function defaults(): array {
		return array(
			'code_lifetime_minutes'     => 10,
			'max_attempts'              => 5,
			'code_length'               => 8,
			'allow_profile_2fa_email'   => 0,
			'require_2fa_all_users'     => 0,
		);
	}

	/**
	 * Whether site policy says every account must use the second factor (login code must enforce).
	 */
	public static function is_2fa_required_for_all(): bool {
		$options = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());

		return !empty($options['require_2fa_all_users']);
	}
}
