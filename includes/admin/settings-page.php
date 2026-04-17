<?php

namespace WP_DUAL_CHECK\admin;

if (!defined('ABSPATH')) {
	exit;
}

final class Settings_Page implements Admin_Settings_Page {

	public const OPTION_NAME = 'wp_dual_check_settings';

	public const MENU_SLUG = 'wp-dual-check';

	public const CODE_LIFETIME_MIN = 5;

	public const CODE_LIFETIME_MAX = 30;

	public const MAX_ATTEMPTS_MIN = 3;

	public const MAX_ATTEMPTS_MAX = 7;

	public const CODE_LENGTH_MIN = 5;

	public const CODE_LENGTH_MAX = 15;

	public const CODE_RESEND_COOLDOWN_MIN = 30;

	public const CODE_RESEND_COOLDOWN_MAX = 900;

	public function register(): void {
		add_action('admin_menu', array($this, 'add_main_menu'));
		add_action('admin_init', array($this, 'register_settings'));
	}

	public function add_main_menu(): void {
		add_menu_page(
			__('WP Dual Check', 'wp-dual-check'),
			__('WP Dual Check', 'wp-dual-check'),
			'manage_options',
			self::MENU_SLUG,
			array($this, 'render_page'),
			'dashicons-lock',
			81
		);
		add_submenu_page(
			self::MENU_SLUG,
			__('General Settings', 'wp-dual-check'),
			__('General Settings', 'wp-dual-check'),
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
			'',
			self::MENU_SLUG
		);

		add_settings_field(
			'code_lifetime_minutes',
			__('Code expiration (minutes)', 'wp-dual-check'),
			array($this, 'field_code_lifetime'),
			self::MENU_SLUG,
			'wp_dual_check_main'
		);

		add_settings_field(
			'max_attempts',
			__('Max verification attempts', 'wp-dual-check'),
			array($this, 'field_max_attempts'),
			self::MENU_SLUG,
			'wp_dual_check_main'
		);

		add_settings_field(
			'code_length',
			__('Code length', 'wp-dual-check'),
			array($this, 'field_code_length'),
			self::MENU_SLUG,
			'wp_dual_check_main'
		);

		add_settings_section(
			'wp_dual_check_limits',
			__('Limits & debug', 'wp-dual-check'),
			'',
			self::MENU_SLUG
		);

		add_settings_field(
			'code_resend_cooldown_seconds',
			__('Minimum time between new login codes (seconds)', 'wp-dual-check'),
			array($this, 'field_code_resend_cooldown'),
			self::MENU_SLUG,
			'wp_dual_check_limits'
		);

		add_settings_field(
			'debug_logging',
			__('Debug logging', 'wp-dual-check'),
			array($this, 'field_debug_logging'),
			self::MENU_SLUG,
			'wp_dual_check_limits'
		);

		add_settings_field(
			'email_use_custom_template',
			__('Use custom email template', 'wp-dual-check'),
			array($this, 'field_email_use_custom_template'),
			self::MENU_SLUG,
			'wp_dual_check_main'
		);

		add_settings_section(
			'wp_dual_check_policy',
			__('Login policy', 'wp-dual-check'),
			'',
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
			'',
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
	 * @return array<string, mixed>
	 */
	public function sanitize($input): array {
		$prev = get_option(self::OPTION_NAME, array());
		$out  = wp_parse_args(is_array($prev) ? $prev : array(), self::defaults());
		if (!is_array($input)) {
			return self::normalize_email_settings(self::clamp_numeric_settings($out));
		}

		$ctx = isset($input['save_context']) ? sanitize_key((string) $input['save_context']) : 'main';
		unset($input['save_context']);

		if ($ctx === 'email') {
			$out = Email_Settings_Page::merge_from_post($input, $out);

			return self::normalize_email_settings(self::clamp_numeric_settings($out));
		}

		if (isset($input['code_lifetime_minutes'])) {
			$out['code_lifetime_minutes'] = absint($input['code_lifetime_minutes']);
		}
		if (isset($input['max_attempts'])) {
			$out['max_attempts'] = absint($input['max_attempts']);
		}
		if (isset($input['code_length'])) {
			$out['code_length'] = absint($input['code_length']);
		}
		if (isset($input['code_resend_cooldown_seconds'])) {
			$out['code_resend_cooldown_seconds'] = absint($input['code_resend_cooldown_seconds']);
		}
		if (isset($input['debug_logging'])) {
			$v = $input['debug_logging'];
			if (is_array($v)) {
				$v = end($v);
			}
			$out['debug_logging'] = !empty($v) ? 1 : 0;
		}
		if (isset($input['email_use_custom_template'])) {
			$v = $input['email_use_custom_template'];
			if (is_array($v)) {
				$v = end($v);
			}
			$out['email_use_custom_template'] = !empty($v) ? 1 : 0;
		}
		$out['allow_profile_2fa_email'] = !empty($input['allow_profile_2fa_email']) ? 1 : 0;
		$out['require_2fa_all_users']    = !empty($input['require_2fa_all_users']) ? 1 : 0;

		return self::normalize_email_settings(self::clamp_numeric_settings($out));
	}

	public function field_code_lifetime(): void {
		$opts = self::clamp_numeric_settings(wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults()));
		$v    = (int) $opts['code_lifetime_minutes'];
		printf(
			'<input type="number" name="%1$s[code_lifetime_minutes]" value="%2$d" min="%3$d" max="%4$d" class="small-text" />',
			esc_attr(self::OPTION_NAME),
			$v,
			self::CODE_LIFETIME_MIN,
			self::CODE_LIFETIME_MAX
		);
	}

	public function field_max_attempts(): void {
		$opts = self::clamp_numeric_settings(wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults()));
		$v    = (int) $opts['max_attempts'];
		printf(
			'<input type="number" name="%1$s[max_attempts]" value="%2$d" min="%3$d" max="%4$d" class="small-text" />',
			esc_attr(self::OPTION_NAME),
			$v,
			self::MAX_ATTEMPTS_MIN,
			self::MAX_ATTEMPTS_MAX
		);
	}

	public function field_code_length(): void {
		$opts = self::clamp_numeric_settings(wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults()));
		$v    = (int) $opts['code_length'];
		printf(
			'<input type="number" name="%1$s[code_length]" value="%2$d" min="%3$d" max="%4$d" class="small-text" />',
			esc_attr(self::OPTION_NAME),
			$v,
			self::CODE_LENGTH_MIN,
			self::CODE_LENGTH_MAX
		);
	}

	public function field_code_resend_cooldown(): void {
		$opts = self::clamp_numeric_settings(wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults()));
		$v    = (int) $opts['code_resend_cooldown_seconds'];
		printf(
			'<input type="number" name="%1$s[code_resend_cooldown_seconds]" value="%2$d" min="%3$d" max="%4$d" class="small-text" />',
			esc_attr(self::OPTION_NAME),
			$v,
			self::CODE_RESEND_COOLDOWN_MIN,
			self::CODE_RESEND_COOLDOWN_MAX
		);
		echo '<p class="description">' . esc_html__('Applies per account when that user requests a login code. Reduces mail spam and guessing.', 'wp-dual-check') . '</p>';
	}

	public function field_debug_logging(): void {
		$opts = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());
		$on   = !empty($opts['debug_logging']);
		$n    = self::OPTION_NAME;
		printf('<input type="hidden" name="%s[debug_logging]" value="0" />', esc_attr($n));
		printf(
			'<label for="wpdc_debug_logging"><input type="checkbox" id="wpdc_debug_logging" name="%1$s[debug_logging]" value="1" %2$s /> %3$s</label>',
			esc_attr($n),
			checked($on, true, false),
			esc_html__('Write debug lines to the uploads log file', 'wp-dual-check')
		);
		$dir = \WP_DUAL_CHECK\Logging\Logger::log_directory();
		echo '<p class="description">' . esc_html__('File:', 'wp-dual-check') . ' <code>' . esc_html($dir !== '' ? trailingslashit($dir) . 'debug.log' : '') . '</code></p>';
	}

	public function field_email_use_custom_template(): void {
		$opts = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());
		$on   = !empty($opts['email_use_custom_template']);
		$n    = self::OPTION_NAME;
		printf('<input type="hidden" name="%s[email_use_custom_template]" value="0" />', esc_attr($n));
		printf(
			'<label for="wpdc_use_custom_email"><input type="checkbox" id="wpdc_use_custom_email" name="%1$s[email_use_custom_template]" value="1" %2$s aria-describedby="wpdc_use_custom_email_desc" /> %3$s</label>',
			esc_attr($n),
			checked($on, true, false),
			esc_html__('Use custom email template', 'wp-dual-check')
		);
	}

	public function field_allow_profile_2fa_email(): void {
		$opts    = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());
		$checked = !empty($opts['allow_profile_2fa_email']);
		printf(
			'<label><input type="checkbox" name="%1$s[allow_profile_2fa_email]" value="1" %2$s /> %3$s</label>',
			esc_attr(self::OPTION_NAME),
			checked($checked, true, false),
			esc_html__('Let users set a separate email for codes on their profile.', 'wp-dual-check')
		);
	}

	public function field_require_2fa_all_users(): void {
		$opts    = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());
		$checked = !empty($opts['require_2fa_all_users']);
		printf(
			'<label><input type="checkbox" name="%1$s[require_2fa_all_users]" value="1" %2$s /> %3$s</label>',
			esc_attr(self::OPTION_NAME),
			checked($checked, true, false),
			esc_html__('Every user must complete the second step after password.', 'wp-dual-check')
		);
		echo '<p class="description">' . esc_html__('This is off by default to prevent locking out users.', 'wp-dual-check') . '</p>';
	}

	public function render_page(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-dual-check'));
		}

		echo '<div class="wrap"><h1>' . esc_html__('WP Dual Check', 'wp-dual-check') . '</h1>';
		Settings_Notices::render();
		echo '<form action="options.php" method="post">';
		settings_fields('wp_dual_check_settings_group');
		printf('<input type="hidden" name="%s[save_context]" value="main" />', esc_attr(self::OPTION_NAME));
		do_settings_sections(self::MENU_SLUG);
		submit_button(__('Save changes', 'wp-dual-check'));
		echo '</form></div>';
	}

	public static function defaults(): array {
		return array(
			'code_lifetime_minutes'      => 10,
			'max_attempts'               => 5,
			'code_length'                => 8,
			'allow_profile_2fa_email'    => 0,
			'require_2fa_all_users'      => 0,
			'email_subject_template'     => '',
			'email_body_template'        => '',
			'email_header_html'          => '',
			'email_footer_html'          => '',
			'email_color_link'           => '#2271b1',
			'email_color_header_bg'      => '#2271b1',
			'email_color_footer_bg'      => '#f0f0f1',
			'email_use_custom_template'  => 0,
			'code_resend_cooldown_seconds' => 30,
			'debug_logging'              => 0,
		);
	}

	public static function is_2fa_required_for_all(): bool {
		$opts = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());

		return !empty($opts['require_2fa_all_users']);
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	public static function clamp_numeric_settings(array $options): array {
		$d = self::defaults();
		$options['code_lifetime_minutes'] = max(
			self::CODE_LIFETIME_MIN,
			min(self::CODE_LIFETIME_MAX, absint($options['code_lifetime_minutes'] ?? $d['code_lifetime_minutes']))
		);
		$options['max_attempts'] = max(
			self::MAX_ATTEMPTS_MIN,
			min(self::MAX_ATTEMPTS_MAX, absint($options['max_attempts'] ?? $d['max_attempts']))
		);
		$options['code_length'] = max(
			self::CODE_LENGTH_MIN,
			min(self::CODE_LENGTH_MAX, absint($options['code_length'] ?? $d['code_length']))
		);
		$options['code_resend_cooldown_seconds'] = max(
			self::CODE_RESEND_COOLDOWN_MIN,
			min(self::CODE_RESEND_COOLDOWN_MAX, absint($options['code_resend_cooldown_seconds'] ?? $d['code_resend_cooldown_seconds']))
		);

		return $options;
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	public static function normalize_email_settings(array $options): array {
		unset($options['save_context']);

		$d = self::defaults();
		foreach (array('email_subject_template', 'email_body_template', 'email_header_html', 'email_footer_html') as $key) {
			if (!isset($options[ $key ])) {
				$options[ $key ] = $d[ $key ];
				continue;
			}
			$options[ $key ] = is_string($options[ $key ]) ? $options[ $key ] : (string) $options[ $key ];
		}
		foreach (array('email_color_link', 'email_color_header_bg', 'email_color_footer_bg') as $key) {
			$raw = isset($options[ $key ]) ? (string) $options[ $key ] : '';
			$c   = sanitize_hex_color($raw);
			$options[ $key ] = $c ?: $d[ $key ];
		}
		if (!isset($options['email_use_custom_template'])) {
			$options['email_use_custom_template'] = $d['email_use_custom_template'];
		} else {
			$options['email_use_custom_template'] = (int) !empty($options['email_use_custom_template']);
		}
		if (!isset($options['debug_logging'])) {
			$options['debug_logging'] = $d['debug_logging'];
		} else {
			$options['debug_logging'] = (int) !empty($options['debug_logging']);
		}

		return $options;
	}
}
