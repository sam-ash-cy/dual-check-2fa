<?php

namespace WP_DUAL_CHECK\admin;

use function WP_DUAL_CHECK\db\dual_check_settings;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Settings → WP Dual Check (manage_options only).
 */
class Settings_Page {

	public const OPTION_NAME = 'wp_dual_check_settings';

	public const MENU_SLUG = 'wp-dual-check';

	/** Settings API slug for the Login email fields (used with the Login email tab). */
	public const SETTINGS_EMAIL = 'wpdc-email';

	public const TAB_EMAIL = 'email';

	public const CODE_LIFETIME_MIN = 5;

	public const CODE_LIFETIME_MAX = 30;

	public const MAX_ATTEMPTS_MIN = 3;

	public const MAX_ATTEMPTS_MAX = 7;

	public const CODE_LENGTH_MIN = 5;

	public const CODE_LENGTH_MAX = 15;

	public function register(): void {
		add_action('admin_menu', array($this, 'add_menu'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
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

	/**
	 * Colour pickers on WP Dual Check (Login email tab).
	 */
	public function enqueue_admin_assets(string $hook_suffix): void {
		if ($hook_suffix !== 'settings_page_' . self::MENU_SLUG) {
			return;
		}
		wp_enqueue_style('wp-color-picker');
		wp_enqueue_script('wp-color-picker');
		wp_add_inline_script(
			'wp-color-picker',
			'jQuery(function($){ $(".wpdc-color-field").wpColorPicker(); });',
			'after'
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
			__('Code Expiration Time (minutes)', 'wp-dual-check'),
			array($this, 'field_code_lifetime'),
			self::MENU_SLUG,
			'wp_dual_check_main'
		);

		add_settings_field(
			'max_attempts',
			__('Max Verification Attempts', 'wp-dual-check'),
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

		add_settings_field(
			'email_use_custom_template',
			__('Login email template', 'wp-dual-check'),
			array($this, 'field_email_use_custom_template'),
			self::MENU_SLUG,
			'wp_dual_check_main'
		);

		add_settings_section(
			'wp_dual_check_email',
			__('Login email', 'wp-dual-check'),
			array($this, 'section_email_intro'),
			self::SETTINGS_EMAIL
		);

		add_settings_field(
			'email_subject_template',
			__('Email subject', 'wp-dual-check'),
			array($this, 'field_email_subject_template'),
			self::SETTINGS_EMAIL,
			'wp_dual_check_email'
		);

		add_settings_field(
			'email_body_template',
			__('Email body (HTML)', 'wp-dual-check'),
			array($this, 'field_email_body_template'),
			self::SETTINGS_EMAIL,
			'wp_dual_check_email'
		);

		add_settings_field(
			'email_header_html',
			__('Header HTML', 'wp-dual-check'),
			array($this, 'field_email_header_html'),
			self::SETTINGS_EMAIL,
			'wp_dual_check_email'
		);

		add_settings_field(
			'email_footer_html',
			__('Footer HTML', 'wp-dual-check'),
			array($this, 'field_email_footer_html'),
			self::SETTINGS_EMAIL,
			'wp_dual_check_email'
		);

		add_settings_section(
			'wp_dual_check_email_style',
			__('Login email colours', 'wp-dual-check'),
			static function (): void {
				echo '<p class="description">' . esc_html__('These colours apply to the HTML wrapper for every login email, including when you use the default files in templates/email/.', 'wp-dual-check') . '</p>';
			},
			self::SETTINGS_EMAIL
		);

		add_settings_field(
			'email_colors',
			__('Colours', 'wp-dual-check'),
			array($this, 'field_email_colors'),
			self::SETTINGS_EMAIL,
			'wp_dual_check_email_style'
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
	 * @return array<string, mixed>
	 */
	public function sanitize($input): array {
		$stored = get_option(self::OPTION_NAME, array());
		$out    = wp_parse_args(is_array($stored) ? $stored : array(), self::defaults());
		if (!is_array($input)) {
			return self::normalize_email_settings(self::clamp_numeric_settings($out));
		}

		$context = isset($input['save_context']) ? sanitize_key((string) $input['save_context']) : 'main';
		unset($input['save_context']);

		if ($context === 'email') {
			if (isset($input['email_subject_template'])) {
				$out['email_subject_template'] = sanitize_textarea_field(wp_unslash((string) $input['email_subject_template']));
			}
			if (isset($input['email_body_template'])) {
				$out['email_body_template'] = wp_kses_post(wp_unslash((string) $input['email_body_template']));
			}
			if (isset($input['email_header_html'])) {
				$out['email_header_html'] = wp_kses_post(wp_unslash((string) $input['email_header_html']));
			}
			if (isset($input['email_footer_html'])) {
				$out['email_footer_html'] = wp_kses_post(wp_unslash((string) $input['email_footer_html']));
			}
			if (isset($input['email_color_link'])) {
				$c = sanitize_hex_color(wp_unslash((string) $input['email_color_link']));
				$out['email_color_link'] = $c ?: self::defaults()['email_color_link'];
			}
			if (isset($input['email_color_header_bg'])) {
				$c = sanitize_hex_color(wp_unslash((string) $input['email_color_header_bg']));
				$out['email_color_header_bg'] = $c ?: self::defaults()['email_color_header_bg'];
			}
			if (isset($input['email_color_footer_bg'])) {
				$c = sanitize_hex_color(wp_unslash((string) $input['email_color_footer_bg']));
				$out['email_color_footer_bg'] = $c ?: self::defaults()['email_color_footer_bg'];
			}

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
		$out['email_use_custom_template'] = !empty($input['email_use_custom_template']) ? 1 : 0;
		$out['allow_profile_2fa_email']   = !empty($input['allow_profile_2fa_email']) ? 1 : 0;
		$out['require_2fa_all_users']     = !empty($input['require_2fa_all_users']) ? 1 : 0;

		return self::normalize_email_settings(self::clamp_numeric_settings($out));
	}

	public function section_email_intro(): void {
		$placeholders = '[site-name], [code], [user-login], [expires], [site-url], [minutes], [timezone]';
		echo '<p>' . esc_html__('Use placeholders in the subject and body; they are replaced when the email is sent. Allowed HTML in the body, header, and footer is filtered for safety.', 'wp-dual-check') . '</p>';
		echo '<p class="description"><strong>' . esc_html__('Placeholders:', 'wp-dual-check') . '</strong> <code>' . esc_html($placeholders) . '</code></p>';
	}

	public function field_email_subject_template(): void {
		$options = dual_check_settings();
		$value   = isset($options['email_subject_template']) ? (string) $options['email_subject_template'] : '';
		printf(
			'<textarea class="large-text" rows="2" name="%1$s[email_subject_template]" id="wpdc_email_subject">%2$s</textarea>',
			esc_attr(self::OPTION_NAME),
			esc_textarea($value)
		);
		echo '<p class="description">' . esc_html__('Leave empty for the default subject. Plain text only; placeholders allowed.', 'wp-dual-check') . '</p>';
	}

	public function field_email_body_template(): void {
		$options = dual_check_settings();
		$value   = isset($options['email_body_template']) ? (string) $options['email_body_template'] : '';
		printf(
			'<textarea class="large-text code" rows="12" name="%1$s[email_body_template]" id="wpdc_email_body">%2$s</textarea>',
			esc_attr(self::OPTION_NAME),
			esc_textarea($value)
		);
		echo '<p class="description">' . esc_html__('Leave empty for the default layout. You can use paragraphs, links, bold text, and other common HTML.', 'wp-dual-check') . '</p>';
	}

	public function field_email_header_html(): void {
		$options = dual_check_settings();
		$value   = isset($options['email_header_html']) ? (string) $options['email_header_html'] : '';
		printf(
			'<textarea class="large-text code" rows="3" name="%1$s[email_header_html]" id="wpdc_email_header">%2$s</textarea>',
			esc_attr(self::OPTION_NAME),
			esc_textarea($value)
		);
		echo '<p class="description">' . esc_html__('Shown in the coloured header bar. Leave empty for a short default title.', 'wp-dual-check') . '</p>';
	}

	public function field_email_footer_html(): void {
		$options = dual_check_settings();
		$value   = isset($options['email_footer_html']) ? (string) $options['email_footer_html'] : '';
		printf(
			'<textarea class="large-text code" rows="3" name="%1$s[email_footer_html]" id="wpdc_email_footer">%2$s</textarea>',
			esc_attr(self::OPTION_NAME),
			esc_textarea($value)
		);
		echo '<p class="description">' . esc_html__('Shown in the footer strip. Leave empty for a short default notice.', 'wp-dual-check') . '</p>';
	}

	public function field_email_colors(): void {
		$options = dual_check_settings();
		$link    = isset($options['email_color_link']) ? (string) $options['email_color_link'] : '#2271b1';
		$header  = isset($options['email_color_header_bg']) ? (string) $options['email_color_header_bg'] : '#2271b1';
		$footer  = isset($options['email_color_footer_bg']) ? (string) $options['email_color_footer_bg'] : '#f0f0f1';

		echo '<fieldset class="wpdc-color-picker-fieldset"><p>';
		echo '<label for="wpdc_color_link">' . esc_html__('Link colour', 'wp-dual-check') . '</label><br />';
		printf(
			'<input type="text" id="wpdc_color_link" class="wpdc-color-field" name="%1$s[email_color_link]" value="%2$s" data-default-color="#2271b1" />',
			esc_attr(self::OPTION_NAME),
			esc_attr($link)
		);
		echo '</p><p>';
		echo '<label for="wpdc_color_header">' . esc_html__('Header background', 'wp-dual-check') . '</label><br />';
		printf(
			'<input type="text" id="wpdc_color_header" class="wpdc-color-field" name="%1$s[email_color_header_bg]" value="%2$s" data-default-color="#2271b1" />',
			esc_attr(self::OPTION_NAME),
			esc_attr($header)
		);
		echo '</p><p>';
		echo '<label for="wpdc_color_footer">' . esc_html__('Footer background', 'wp-dual-check') . '</label><br />';
		printf(
			'<input type="text" id="wpdc_color_footer" class="wpdc-color-field" name="%1$s[email_color_footer_bg]" value="%2$s" data-default-color="#f0f0f1" />',
			esc_attr(self::OPTION_NAME),
			esc_attr($footer)
		);
		echo '</p></fieldset>';
		echo '<p class="description">' . esc_html__('Use the swatch or type a hex value. Invalid values fall back to the default when you save.', 'wp-dual-check') . '</p>';
	}

	public function field_code_lifetime(): void {
		$options = self::clamp_numeric_settings(wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults()));
		$value   = (int) $options['code_lifetime_minutes'];
		printf(
			'<input type="number" name="%1$s[code_lifetime_minutes]" value="%2$d" min="%3$d" max="%4$d" class="small-text" />',
			esc_attr(self::OPTION_NAME),
			$value,
			self::CODE_LIFETIME_MIN,
			self::CODE_LIFETIME_MAX
		);
	}

	public function field_max_attempts(): void {
		$options = self::clamp_numeric_settings(wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults()));
		$value   = (int) $options['max_attempts'];
		printf(
			'<input type="number" name="%1$s[max_attempts]" value="%2$d" min="%3$d" max="%4$d" class="small-text" />',
			esc_attr(self::OPTION_NAME),
			$value,
			self::MAX_ATTEMPTS_MIN,
			self::MAX_ATTEMPTS_MAX
		);
		echo '<p class="description">' . esc_html__('Site-wide limit. Each token row stores its own count in the database attempts column until it hits this cap.', 'wp-dual-check') . '</p>';
	}

	public function field_code_length(): void {
		$options = self::clamp_numeric_settings(wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults()));
		$value   = (int) $options['code_length'];
		printf(
			'<input type="number" name="%1$s[code_length]" value="%2$d" min="%3$d" max="%4$d" class="small-text" />',
			esc_attr(self::OPTION_NAME),
			$value,
			self::CODE_LENGTH_MIN,
			self::CODE_LENGTH_MAX
		);
		echo '<p class="description">' . esc_html__('Length of the random code sent to the user.', 'wp-dual-check') . '</p>';
	}

	public function field_email_use_custom_template(): void {
		$options = dual_check_settings();
		$checked = !empty($options['email_use_custom_template']);
		printf(
			'<label><input type="checkbox" name="%1$s[email_use_custom_template]" value="1" %2$s /> %3$s</label>',
			esc_attr(self::OPTION_NAME),
			checked($checked, true, false),
			esc_html__('Use custom login email template', 'wp-dual-check')
		);
		echo '<p class="description">' . esc_html__('When off, wording comes from templates/email/. Use the Login email tab for colours.', 'wp-dual-check') . '</p>';
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

		$custom = !empty(dual_check_settings()['email_use_custom_template']);
		$tab    = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : '';
		if ($tab !== '' && $tab !== self::TAB_EMAIL) {
			$tab = '';
		}

		echo '<div class="wrap"><h1>' . esc_html__('WP Dual Check', 'wp-dual-check') . '</h1>';

		$base = admin_url('options-general.php?page=' . self::MENU_SLUG);
		$g    = remove_query_arg('tab', $base);
		$e    = add_query_arg('tab', self::TAB_EMAIL, $base);
		echo '<nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom:1em;">';
		printf(
			'<a href="%s" class="nav-tab%s">%s</a>',
			esc_url($g),
			$tab === '' ? ' nav-tab-active' : '',
			esc_html__('General', 'wp-dual-check')
		);
		printf(
			'<a href="%s" class="nav-tab%s">%s</a>',
			esc_url($e),
			$tab === self::TAB_EMAIL ? ' nav-tab-active' : '',
			esc_html__('Login email', 'wp-dual-check')
		);
		echo '</nav>';

		if ($tab === self::TAB_EMAIL) {
			echo '<form action="options.php" method="post">';
			settings_fields('wp_dual_check_settings_group');
			printf('<input type="hidden" name="%s[save_context]" value="email" />', esc_attr(self::OPTION_NAME));
			if ($custom) {
				do_settings_sections(self::SETTINGS_EMAIL);
			} else {
				echo '<p class="description">' . esc_html__('Custom subject and body fields appear here when “Use custom login email template” is enabled on the General tab.', 'wp-dual-check') . '</p>';
				global $wp_settings_sections;
				$sec = $wp_settings_sections[ self::SETTINGS_EMAIL ]['wp_dual_check_email_style'] ?? null;
				if (is_array($sec) && isset($sec['callback']) && is_callable($sec['callback'])) {
					echo '<h2>' . esc_html((string) $sec['title']) . '</h2>';
					call_user_func($sec['callback']);
				}
				echo '<table class="form-table" role="presentation">';
				do_settings_fields(self::SETTINGS_EMAIL, 'wp_dual_check_email_style');
				echo '</table>';
			}
			submit_button(__('Save changes', 'wp-dual-check'));
			echo '</form></div>';

			return;
		}

		echo '<form action="options.php" method="post">';
		settings_fields('wp_dual_check_settings_group');
		printf('<input type="hidden" name="%s[save_context]" value="main" />', esc_attr(self::OPTION_NAME));
		do_settings_sections(self::MENU_SLUG);
		submit_button(__('Save changes', 'wp-dual-check'));
		echo '</form></div>';
	}

	/**
	 * @return array{code_lifetime_minutes: int, max_attempts: int, code_length: int, allow_profile_2fa_email: int, require_2fa_all_users: int}
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
		);
	}

	/**
	 * Whether site policy says every account must use the second factor (login code must enforce).
	 */
	public static function is_2fa_required_for_all(): bool {
		$options = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());

		return !empty($options['require_2fa_all_users']);
	}

	/**
	 * Keep code lifetime, attempt cap, and code length within supported ranges (including legacy saved values).
	 *
	 * @param array<string, mixed> $options Merged with defaults before calling, or include all keys.
	 * @return array<string, mixed>
	 */
	public static function clamp_numeric_settings(array $options): array {
		$options['code_lifetime_minutes'] = max(
			self::CODE_LIFETIME_MIN,
			min(self::CODE_LIFETIME_MAX, absint($options['code_lifetime_minutes'] ?? self::defaults()['code_lifetime_minutes']))
		);
		$options['max_attempts'] = max(
			self::MAX_ATTEMPTS_MIN,
			min(self::MAX_ATTEMPTS_MAX, absint($options['max_attempts'] ?? self::defaults()['max_attempts']))
		);
		$options['code_length'] = max(
			self::CODE_LENGTH_MIN,
			min(self::CODE_LENGTH_MAX, absint($options['code_length'] ?? self::defaults()['code_length']))
		);

		return $options;
	}

	/**
	 * Ensures email-related options exist and colours are valid hex.
	 *
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

		return $options;
	}
}
