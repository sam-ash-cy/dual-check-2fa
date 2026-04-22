<?php

namespace DualCheck2FA\admin;

use DualCheck2FA\core\Security;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * General Dual Check 2FA settings: option registration, sanitization, and field callbacks.
 */
final class Settings_Page implements Admin_Settings_Page {

	public const OPTION_NAME = 'dual_check_2fa_settings';

	/** Settings API group slug (used with {@see register_setting()} and options.php capability filter). */
	public const OPTION_GROUP = 'dual_check_2fa_settings_group';

	public const MENU_SLUG = 'dual-check-2fa';

	public const CODE_LIFETIME_MIN = 5;

	public const CODE_LIFETIME_MAX = 30;

	public const MAX_ATTEMPTS_MIN = 3;

	public const MAX_ATTEMPTS_MAX = 7;

	public const CODE_LENGTH_MIN = 5;

	public const CODE_LENGTH_MAX = 15;

	public const CODE_RESEND_COOLDOWN_MIN = 30;

	public const CODE_RESEND_COOLDOWN_MAX = 900;

	public const CODE_STEP_IP_MAX_FAILS_MIN = 3;

	public const CODE_STEP_IP_MAX_FAILS_MAX = 30;

	public const CODE_STEP_IP_LOCKOUT_MIN = 30;

	public const CODE_STEP_IP_LOCKOUT_MAX = 900;

	/**
	 * Hooks admin menu and Settings API registration.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action('admin_menu', array($this, 'add_main_menu'));
		add_action('admin_init', array($this, 'register_settings'));
	}

	/**
	 * Adds the top-level admin menu and duplicate “General” submenu entry.
	 *
	 * @return void
	 */
	public function add_main_menu(): void {
		add_menu_page(
			__('Dual Check 2FA', 'dual-check-2fa'),
			__('Dual Check 2FA', 'dual-check-2fa'),
			Security::menu_capability_for_main(),
			self::MENU_SLUG,
			array($this, 'render_page'),
			'dashicons-lock',
			81
		);
		add_submenu_page(
			self::MENU_SLUG,
			__('General Settings', 'dual-check-2fa'),
			__('General Settings', 'dual-check-2fa'),
			Security::menu_capability_for_main(),
			self::MENU_SLUG,
			array($this, 'render_page')
		);
	}

	/**
	 * Registers the option, sections, and fields for this screen.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array($this, 'sanitize'),
				'default'           => self::defaults(),
			)
		);

		/**
		 * Core options.php only supports a single capability per option page. This plugin saves
		 * through admin-post with OR-cap checks; require manage_options here so a stray
		 * Settings API form cannot widen access beyond administrators.
		 */
		add_filter('option_page_capability_' . self::OPTION_GROUP, array(self::class, 'options_php_capability'));

		add_settings_section(
			'dual_check_2fa_policy',
			__('Login policy and management', 'dual-check-2fa'),
			'',
			self::MENU_SLUG
		);

		add_settings_field(
			'require_2fa_all_users',
			__('Require dual-check for everyone', 'dual-check-2fa'),
			array($this, 'field_require_2fa_all_users'),
			self::MENU_SLUG,
			'dual_check_2fa_policy'
		);

		add_settings_field(
			'allow_profile_2fa_email',
			__('2FA delivery email on profile', 'dual-check-2fa'),
			array($this, 'field_allow_profile_2fa_email'),
			self::MENU_SLUG,
			'dual_check_2fa_policy'
		);

		add_settings_section(
			'dual_check_2fa_main',
			__('General', 'dual-check-2fa'),
			'',
			self::MENU_SLUG
		);

		add_settings_field(
			'code_lifetime_minutes',
			__('Code expiration (minutes)', 'dual-check-2fa'),
			array($this, 'field_code_lifetime'),
			self::MENU_SLUG,
			'dual_check_2fa_main'
		);

		add_settings_field(
			'max_attempts',
			__('Max verification attempts', 'dual-check-2fa'),
			array($this, 'field_max_attempts'),
			self::MENU_SLUG,
			'dual_check_2fa_main'
		);

		add_settings_field(
			'code_length',
			__('Code length', 'dual-check-2fa'),
			array($this, 'field_code_length'),
			self::MENU_SLUG,
			'dual_check_2fa_main'
		);

		add_settings_field(
			'code_resend_cooldown_seconds',
			__('Minimum time between new login codes (seconds)', 'dual-check-2fa'),
			array($this, 'field_code_resend_cooldown'),
			self::MENU_SLUG,
			'dual_check_2fa_main'
		);

		add_settings_section(
			'dual_check_2fa_code_step_ip',
			__('Code step & IP binding', 'dual-check-2fa'),
			array($this, 'section_code_step_ip'),
			self::MENU_SLUG
		);

		add_settings_field(
			'code_step_ip_rate_limit_enabled',
			__('IP + user binding', 'dual-check-2fa'),
			array($this, 'field_code_step_ip_rate_limit_enabled'),
			self::MENU_SLUG,
			'dual_check_2fa_code_step_ip'
		);

		add_settings_field(
			'code_step_ip_max_fails',
			__('Wrong codes before lockout', 'dual-check-2fa'),
			array($this, 'field_code_step_ip_max_fails'),
			self::MENU_SLUG,
			'dual_check_2fa_code_step_ip'
		);

		add_settings_field(
			'code_step_ip_lockout_seconds',
			__('Lockout duration (seconds)', 'dual-check-2fa'),
			array($this, 'field_code_step_ip_lockout_seconds'),
			self::MENU_SLUG,
			'dual_check_2fa_code_step_ip'
		);

		add_settings_field(
			'email_use_custom_template',
			__('Use custom email template', 'dual-check-2fa'),
			array($this, 'field_email_use_custom_template'),
			self::MENU_SLUG,
			'dual_check_2fa_main'
		);


		add_settings_section(
			'dual_check_2fa_debugging',
			__('Debugging', 'dual-check-2fa'),
			'',
			self::MENU_SLUG
		);

		add_settings_field(
			'debug_logging',
			__('Debug logging', 'dual-check-2fa'),
			array($this, 'field_debug_logging'),
			self::MENU_SLUG,
			'dual_check_2fa_debugging'
		);
	}

	/**
	 * Single capability for options.php (core does not support OR). Keeps accidental Settings API
	 * posts on manage_options; delegated saves use admin-post with {@see Security::can_access_*()}.
	 *
	 * @param string $cap Default capability passed by core.
	 * @return string
	 */
	public static function options_php_capability(string $cap): string {
		return 'manage_options';
	}

	/**
	 * Merges posted values into the stored option; supports split saves via `save_context` (main vs email).
	 *
	 * @param array<string, mixed>|null $input Raw `$_POST` slice for {@see Settings_Page::OPTION_NAME}.
	 * @return array<string, mixed>
	 */
	public function sanitize($input): array {
		$prev = get_option(self::OPTION_NAME, array());
		$out  = wp_parse_args(is_array($prev) ? $prev : array(), self::defaults());
		if (!is_array($input)) {
			return self::normalize_email_settings(self::clamp_numeric_settings(self::normalize_capability_arrays($out)));
		}

		// Email template screen posts the same option name but only a subset of keys; merge without wiping numerics.
		$ctx = isset($input['save_context']) ? sanitize_key((string) $input['save_context']) : 'main';
		unset($input['save_context']);

		if ($ctx === 'permissions') {
			if (!Security::can_access_main_settings()) {
				return self::normalize_email_settings(self::clamp_numeric_settings(self::normalize_capability_arrays($out)));
			}
			$trial = Permissions_Settings_Page::merge_from_post($input, $out);
			$trial = self::normalize_capability_arrays($trial);
			if (!Security::current_user_passes_main_context_with_settings($trial)) {
				Settings_Notices::error(
					'dc2fa_cap_lockout',
					__('Those capability settings were not saved because your account would no longer match “Main settings & this screen”.', 'dual-check-2fa')
				);

				return self::normalize_email_settings(self::clamp_numeric_settings(self::normalize_capability_arrays($out)));
			}
			$out = $trial;

			return self::normalize_email_settings(self::clamp_numeric_settings($out));
		}

		if ($ctx === 'email') {
			if (!Security::can_access_email_template()) {
				return self::normalize_email_settings(self::clamp_numeric_settings(self::normalize_capability_arrays($out)));
			}
			if (empty($out['email_use_custom_template'])) {
				return self::normalize_email_settings(self::clamp_numeric_settings(self::normalize_capability_arrays($out)));
			}
			$out = Email_Settings_Page::merge_from_post($input, $out);

			return self::normalize_email_settings(self::clamp_numeric_settings(self::normalize_capability_arrays($out)));
		}

		if (!Security::can_access_main_settings()) {
			return self::normalize_email_settings(self::clamp_numeric_settings(self::normalize_capability_arrays($out)));
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
		if (isset($input['code_step_ip_max_fails'])) {
			$out['code_step_ip_max_fails'] = absint($input['code_step_ip_max_fails']);
		}
		if (isset($input['code_step_ip_lockout_seconds'])) {
			$out['code_step_ip_lockout_seconds'] = absint($input['code_step_ip_lockout_seconds']);
		}
		$out['code_step_ip_rate_limit_enabled'] = !empty($input['code_step_ip_rate_limit_enabled']) ? 1 : 0;

		return self::normalize_email_settings(self::clamp_numeric_settings(self::normalize_capability_arrays($out)));
	}

	/**
	 * Renders the “Code expiration” number field.
	 *
	 * @return void
	 */
	public function field_code_lifetime(): void {
		$opts = self::clamp_numeric_settings(wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults()));
		$v    = (int) $opts['code_lifetime_minutes'];
		printf(
			'<input type="number" name="%1$s[code_lifetime_minutes]" value="%2$d" min="%3$d" max="%4$d" class="small-text" />',
			esc_attr(Settings_Page::OPTION_NAME),
			absint($v),
			absint(self::CODE_LIFETIME_MIN),
			absint(self::CODE_LIFETIME_MAX)
		);
	}

	/**
	 * Renders the “Max verification attempts” number field.
	 *
	 * @return void
	 */
	public function field_max_attempts(): void {
		$opts = self::clamp_numeric_settings(wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults()));
		$v    = (int) $opts['max_attempts'];
		printf(
			'<input type="number" name="%1$s[max_attempts]" value="%2$d" min="%3$d" max="%4$d" class="small-text" />',
			esc_attr(Settings_Page::OPTION_NAME),
			absint($v),
			absint(self::MAX_ATTEMPTS_MIN),
			absint(self::MAX_ATTEMPTS_MAX)
		);
	}

	/**
	 * Renders the generated login code length field.
	 *
	 * @return void
	 */
	public function field_code_length(): void {
		$opts = self::clamp_numeric_settings(wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults()));
		$v    = (int) $opts['code_length'];
		printf(
			'<input type="number" name="%1$s[code_length]" value="%2$d" min="%3$d" max="%4$d" class="small-text" />',
			esc_attr(Settings_Page::OPTION_NAME),
			absint($v),
			absint(self::CODE_LENGTH_MIN),
			absint(self::CODE_LENGTH_MAX)
		);
	}

	/**
	 * Renders the minimum seconds between new login codes field.
	 *
	 * @return void
	 */
	public function field_code_resend_cooldown(): void {
		$opts = self::clamp_numeric_settings(wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults()));
		$v    = (int) $opts['code_resend_cooldown_seconds'];
		printf(
			'<input type="number" name="%1$s[code_resend_cooldown_seconds]" value="%2$d" min="%3$d" max="%4$d" class="small-text" />',
			esc_attr(Settings_Page::OPTION_NAME),
			absint($v),
			absint(self::CODE_RESEND_COOLDOWN_MIN),
			absint(self::CODE_RESEND_COOLDOWN_MAX)
		);
		echo '<p class="description">' . esc_html__('When IP binding is off: applies per WordPress user only. When IP binding is on: the same interval is tracked per IP address and user together, so one client cannot burn codes for many accounts.', 'dual-check-2fa') . '</p>';
	}

	/**
	 * Intro text for the IP + user code-step section.
	 *
	 * @return void
	 */
	public function section_code_step_ip(): void {
		echo '<p class="description">' . esc_html__('When enabled, wrong security codes are counted per IP address and user. After too many failures, that client cannot submit codes or request a new email for the lockout period. The “minimum time between new login codes” setting above uses the same IP + user key instead of user only.', 'dual-check-2fa') . '</p>';
	}

	/**
	 * Renders the IP + user binding toggle.
	 *
	 * @return void
	 */
	public function field_code_step_ip_rate_limit_enabled(): void {
		$opts    = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());
		$checked = !empty($opts['code_step_ip_rate_limit_enabled']);
		$n       = self::OPTION_NAME;
		printf('<input type="hidden" name="%s[code_step_ip_rate_limit_enabled]" value="0" />', esc_attr($n));
		printf(
			'<label for="dc2fa_code_step_ip_binding"><input type="checkbox" id="dc2fa_code_step_ip_binding" name="%1$s[code_step_ip_rate_limit_enabled]" value="1" %2$s /> %3$s</label>',
			esc_attr($n),
			checked($checked, true, false),
			esc_html__('Limit the code step and resend cooldown by IP + user', 'dual-check-2fa')
		);
	}

	/**
	 * Renders max wrong code attempts before lockout.
	 *
	 * @return void
	 */
	public function field_code_step_ip_max_fails(): void {
		$opts = self::clamp_numeric_settings(wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults()));
		$v    = (int) $opts['code_step_ip_max_fails'];
		printf(
			'<input type="number" name="%1$s[code_step_ip_max_fails]" value="%2$d" min="%3$d" max="%4$d" class="small-text" />',
			esc_attr(Settings_Page::OPTION_NAME),
			absint($v),
			absint(self::CODE_STEP_IP_MAX_FAILS_MIN),
			absint(self::CODE_STEP_IP_MAX_FAILS_MAX)
		);
		echo '<p class="description">' . esc_html__('Only used when IP binding is on. Separate from “max verification attempts” on each issued code.', 'dual-check-2fa') . '</p>';
	}

	/**
	 * Renders lockout length after too many wrong codes.
	 *
	 * @return void
	 */
	public function field_code_step_ip_lockout_seconds(): void {
		$opts = self::clamp_numeric_settings(wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults()));
		$v    = (int) $opts['code_step_ip_lockout_seconds'];
		printf(
			'<input type="number" name="%1$s[code_step_ip_lockout_seconds]" value="%2$d" min="%3$d" max="%4$d" class="small-text" />',
			esc_attr(Settings_Page::OPTION_NAME),
			absint($v),
			absint(self::CODE_STEP_IP_LOCKOUT_MIN),
			absint(self::CODE_STEP_IP_LOCKOUT_MAX)
		);
		echo '<p class="description">' . esc_html__('How long the IP + user pair is blocked from the code step and from requesting another login code.', 'dual-check-2fa') . '</p>';
	}

	/**
	 * Renders the file debug logging toggle.
	 *
	 * @return void
	 */
	public function field_debug_logging(): void {
		$opts = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());
		$on   = !empty($opts['debug_logging']);
		$n    = self::OPTION_NAME;
		printf('<input type="hidden" name="%s[debug_logging]" value="0" />', esc_attr($n));
		printf(
			'<label for="dc2fa_debug_logging"><input type="checkbox" id="dc2fa_debug_logging" name="%1$s[debug_logging]" value="1" %2$s /> %3$s</label>',
			esc_attr($n),
			checked($on, true, false),
			esc_html__('Write debug lines to the uploads log file', 'dual-check-2fa')
		);
		$dir = \DualCheck2FA\Logging\Logger::log_directory();
		echo '<p class="description">' . esc_html__('File:', 'dual-check-2fa') . ' <code>' . esc_html($dir !== '' ? trailingslashit($dir) . 'debug.log' : '') . '</code></p>';
	}

	/**
	 * Renders whether subject/body/header/footer come from settings or bundled defaults.
	 *
	 * @return void
	 */
	public function field_email_use_custom_template(): void {
		$opts = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());
		$on   = !empty($opts['email_use_custom_template']);
		$n    = self::OPTION_NAME;
		printf('<input type="hidden" name="%s[email_use_custom_template]" value="0" />', esc_attr($n));
		printf(
			'<label for="dc2fa_use_custom_email"><input type="checkbox" id="dc2fa_use_custom_email" name="%1$s[email_use_custom_template]" value="1" %2$s aria-describedby="dc2fa_use_custom_email_desc" /> %3$s</label>',
			esc_attr($n),
			checked($on, true, false),
			esc_html__('Use custom email template', 'dual-check-2fa')
		);
		echo '<p class="description" id="dc2fa_use_custom_email_desc">' . esc_html__('When unchecked, login emails use only the bundled layout and colours; the Login Email Template screen is hidden and saved custom content is not applied.', 'dual-check-2fa') . '</p>';
	}

	/**
	 * Renders the option to show the alternate 2FA email field on user profiles.
	 *
	 * @return void
	 */
	public function field_allow_profile_2fa_email(): void {
		$opts    = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());
		$checked = !empty($opts['allow_profile_2fa_email']);
		printf(
			'<label><input type="checkbox" name="%1$s[allow_profile_2fa_email]" value="1" %2$s /> %3$s</label>',
			esc_attr(Settings_Page::OPTION_NAME),
			checked($checked, true, false),
			esc_html__('Let users set a separate email for codes on their profile.', 'dual-check-2fa')
		);
	}

	/**
	 * Renders the “require 2FA for everyone” policy toggle.
	 *
	 * @return void
	 */
	public function field_require_2fa_all_users(): void {
		$opts    = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());
		$checked = !empty($opts['require_2fa_all_users']);
		printf(
			'<label><input type="checkbox" name="%1$s[require_2fa_all_users]" value="1" %2$s /> %3$s</label>',
			esc_attr(Settings_Page::OPTION_NAME),
			checked($checked, true, false),
			esc_html__('Enable Two Factor Authentication for all users.', 'dual-check-2fa')
		);
		echo '<p class="description">' . esc_html__('This is off by default to prevent locking out users. When enabled, all users will be required to complete a second step after logging in initially.', 'dual-check-2fa') . '</p>';
	}

	/**
	 * Outputs the General settings form.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if (!is_user_logged_in()) {
			wp_die(esc_html__('You must be logged in.', 'dual-check-2fa'), esc_html__('Error', 'dual-check-2fa'), array('response' => 403));
		}
		if (!Security::can_access_main_settings()) {
			wp_die(esc_html__('You do not have permission to access this page.', 'dual-check-2fa'), esc_html__('Error', 'dual-check-2fa'), array('response' => 403));
		}

		echo '<div class="wrap"><h1>' . esc_html__('Dual Check 2FA', 'dual-check-2fa') . '</h1>';
		Settings_Notices::render();
		Settings_Save_Handler::render_form_open(Settings_Page::MENU_SLUG);
		printf('<input type="hidden" name="%s[save_context]" value="main" />', esc_attr(Settings_Page::OPTION_NAME));
		do_settings_sections(Settings_Page::MENU_SLUG);
		submit_button(__('Save changes', 'dual-check-2fa'));
		echo '</form></div>';
	}

	/**
	 * Default option values when keys are missing from the database.
	 *
	 * @return array<string, mixed>
	 */
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
			'debug_logging'                => 0,
			'code_step_ip_rate_limit_enabled' => 0,
			'code_step_ip_max_fails'      => 5,
			'code_step_ip_lockout_seconds' => 30,
			'cap_pool'                     => array('manage_options'),
			'cap_context_main'             => array('manage_options'),
			'cap_context_email'          => array('manage_options'),
		);
	}

	/**
	 * Intersects context caps with the pool and applies defaults when empty.
	 *
	 * @param array<string, mixed> $options Partial or full settings row.
	 * @return array<string, mixed>
	 */
	public static function normalize_capability_arrays(array $options): array {
		$d = self::defaults();
		$pool = isset($options['cap_pool']) && is_array($options['cap_pool'])
			? Security::normalize_cap_list($options['cap_pool'])
			: Security::normalize_cap_list($d['cap_pool']);
		if ($pool === array()) {
			$pool = array('manage_options');
		}
		$options['cap_pool'] = $pool;

		foreach (array('cap_context_main', 'cap_context_email') as $key) {
			$ctx = isset($options[ $key ]) && is_array($options[ $key ])
				? Security::normalize_cap_list($options[ $key ])
				: array();
			$ctx = array_values(array_intersect($ctx, $pool));
			if ($ctx === array()) {
				$ctx = array('manage_options');
			}
			$options[ $key ] = $ctx;
		}

		return $options;
	}

	/**
	 * Whether “require dual-check for everyone” is enabled in saved options.
	 *
	 * @return bool
	 */
	public static function is_2fa_required_for_all(): bool {
		$opts = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());

		return !empty($opts['require_2fa_all_users']);
	}

	/**
	 * Clamps numeric settings to admin-defined min/max ranges.
	 *
	 * @param array<string, mixed> $options Partial or full settings row.
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
		$options['code_step_ip_max_fails'] = max(
			self::CODE_STEP_IP_MAX_FAILS_MIN,
			min(self::CODE_STEP_IP_MAX_FAILS_MAX, absint($options['code_step_ip_max_fails'] ?? $d['code_step_ip_max_fails']))
		);
		$options['code_step_ip_lockout_seconds'] = max(
			self::CODE_STEP_IP_LOCKOUT_MIN,
			min(self::CODE_STEP_IP_LOCKOUT_MAX, absint($options['code_step_ip_lockout_seconds'] ?? $d['code_step_ip_lockout_seconds']))
		);

		return $options;
	}

	/**
	 * Normalises email-related strings, hex colours, and boolean-ish flags on the options row.
	 *
	 * @param array<string, mixed> $options Partial or full settings row.
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
		if (!isset($options['code_step_ip_rate_limit_enabled'])) {
			$options['code_step_ip_rate_limit_enabled'] = $d['code_step_ip_rate_limit_enabled'];
		} else {
			$options['code_step_ip_rate_limit_enabled'] = (int) !empty($options['code_step_ip_rate_limit_enabled']);
		}

		return $options;
	}
}
