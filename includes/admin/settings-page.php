<?php

namespace DualCheck2FA\admin;

use DualCheck2FA\core\Security;
use DualCheck2FA\delivery\Mail_Credentials;
use function DualCheck2FA\delivery\get_default_mail_provider;
use function DualCheck2FA\delivery\get_registered_mail_providers;
use function DualCheck2FA\delivery\normalize_mail_provider_id;

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

	public const TEST_EMAIL_ACTION = 'dual_check_2fa_test_email_general';

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
		add_action('admin_post_' . self::TEST_EMAIL_ACTION, array($this, 'handle_test_email_general'));
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
				'type'    => 'array',
				'default' => self::defaults(),
			)
		);

		/**
		 * Do not register {@see self::sanitize()} as {@code sanitize_option_*} here: {@see update_option()}
		 * always runs {@see sanitize_option()}, which would invoke that callback a second time with the
		 * already-saved row (no {@code save_context}), so the handler would mis-detect “main” save and
		 * revert capability changes and booleans. Sanitization runs only from {@see Settings_Save_Handler}
		 * (and any other explicit callers) before {@see update_option()}.
		 */

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
			'allow_user_exempt',
			__('Allow per-user 2FA exemption', 'dual-check-2fa'),
			array($this, 'field_allow_user_exempt'),
			self::MENU_SLUG,
			'dual_check_2fa_policy'
		);

		add_settings_field(
			'allow_trusted_devices',
			__('Allow trusted devices', 'dual-check-2fa'),
			array($this, 'field_allow_trusted_devices'),
			self::MENU_SLUG,
			'dual_check_2fa_policy'
		);

		add_settings_field(
			'trusted_devices_days',
			__('Remember duration (days)', 'dual-check-2fa'),
			array($this, 'field_trusted_devices_days'),
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
			'mail_delivery',
			__('Mail delivery for security codes', 'dual-check-2fa'),
			array($this, 'field_mail_delivery'),
			self::MENU_SLUG,
			'dual_check_2fa_main'
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

		add_settings_field(
			'token_gc_enabled',
			__('Token table cleanup', 'dual-check-2fa'),
			array($this, 'field_token_gc_enabled'),
			self::MENU_SLUG,
			'dual_check_2fa_debugging'
		);

		add_settings_field(
			'activity_enabled',
			__('Record login activity', 'dual-check-2fa'),
			array($this, 'field_activity_enabled'),
			self::MENU_SLUG,
			'dual_check_2fa_debugging'
		);

		add_settings_field(
			'activity_retention_days',
			__('Login activity retention (days)', 'dual-check-2fa'),
			array($this, 'field_activity_retention_days'),
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
	 * Called explicitly from {@see Settings_Save_Handler} before {@see update_option()} — not registered
	 * as {@code sanitize_option_*} (see {@see register_settings()}).
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
			if (!Security::current_user_passes_activity_context_with_settings($trial)) {
				Settings_Notices::error(
					'dc2fa_cap_activity_lockout',
					__('Those capability settings were not saved because your account would no longer match “Login activity”.', 'dual-check-2fa')
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

		$out['allow_user_exempt']       = !empty($input['allow_user_exempt']) ? 1 : 0;
		$out['allow_trusted_devices']   = !empty($input['allow_trusted_devices']) ? 1 : 0;
		$out['token_gc_enabled']        = !empty($input['token_gc_enabled']) ? 1 : 0;
		$out['activity_enabled']        = !empty($input['activity_enabled']) ? 1 : 0;
		if (isset($input['trusted_devices_days'])) {
			$out['trusted_devices_days'] = absint($input['trusted_devices_days']);
		}
		if (isset($input['activity_retention_days'])) {
			$out['activity_retention_days'] = absint($input['activity_retention_days']);
		}

		$out['mail_custom_provider_enabled'] = !empty($input['mail_custom_provider_enabled']) ? 1 : 0;
		$mid_raw                             = isset($input['mail_provider_id']) ? (string) $input['mail_provider_id'] : (string) ($out['mail_provider_id'] ?? 'wp_mail');
		$out['mail_provider_id']             = normalize_mail_provider_id($mid_raw);
		$out                                 = self::merge_mail_provider_secret($out, $input, Mail_Credentials::SENDGRID_KEY_OPTION);
		$out                                 = self::merge_mail_provider_secret($out, $input, Mail_Credentials::POSTMARK_TOKEN_OPTION);
		$out                                 = self::merge_mail_provider_secret($out, $input, Mail_Credentials::MAILGUN_KEY_OPTION);
		$out                                 = self::merge_mail_provider_secret($out, $input, Mail_Credentials::SES_SECRET_ACCESS_KEY_OPTION);
		if (array_key_exists('mail_mailgun_domain', $input) && is_string($input['mail_mailgun_domain'])) {
			$d = strtolower(sanitize_text_field(trim(wp_unslash($input['mail_mailgun_domain']))));
			$d = (string) preg_replace('#^https?://#', '', $d);
			$out['mail_mailgun_domain'] = trim($d, '/');
		}
		$reg = isset($input[ Mail_Credentials::MAILGUN_REGION_OPTION ])
			? sanitize_key((string) $input[ Mail_Credentials::MAILGUN_REGION_OPTION ])
			: '';
		$out[ Mail_Credentials::MAILGUN_REGION_OPTION ] = $reg === 'eu' ? 'eu' : 'us';

		if (array_key_exists(Mail_Credentials::SES_ACCESS_KEY_ID_OPTION, $input)) {
			$out[ Mail_Credentials::SES_ACCESS_KEY_ID_OPTION ] = sanitize_text_field(
				trim((string) wp_unslash($input[ Mail_Credentials::SES_ACCESS_KEY_ID_OPTION ]))
			);
		}
		if (array_key_exists(Mail_Credentials::SES_REGION_OPTION, $input)) {
			$r = strtolower(sanitize_text_field(trim((string) wp_unslash($input[ Mail_Credentials::SES_REGION_OPTION ]))));
			$out[ Mail_Credentials::SES_REGION_OPTION ] = $r !== '' ? $r : 'us-east-1';
		}
		if (array_key_exists(Mail_Credentials::SES_CONFIGURATION_SET_OPTION, $input)) {
			$out[ Mail_Credentials::SES_CONFIGURATION_SET_OPTION ] = sanitize_text_field(
				trim((string) wp_unslash($input[ Mail_Credentials::SES_CONFIGURATION_SET_OPTION ]))
			);
		}

		return self::normalize_email_settings(self::clamp_numeric_settings(self::normalize_capability_arrays($out)));
	}

	/**
	 * Keeps stored API tokens when the password field is left blank on save.
	 *
	 * @param array<string, mixed> $out   Merged option row.
	 * @param array<string, mixed> $input Raw POST slice.
	 * @param string               $key   Option key (e.g. mail_sendgrid_api_key).
	 * @return array<string, mixed>
	 */
	private static function merge_mail_provider_secret(array $out, array $input, string $key): array {
		if (!array_key_exists($key, $input)) {
			return $out;
		}
		$raw = trim((string) wp_unslash($input[ $key ]));
		if ($raw !== '') {
			$out[ $key ] = $raw;
		}

		return $out;
	}

	/**
	 * Mail provider toggle, dropdown, and API credential fields (General section).
	 *
	 * @return void
	 */
	public function field_mail_delivery(): void {
		if (!Security::can_access_main_settings()) {
			return;
		}

		$opts = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());
		$on   = !empty($opts['mail_custom_provider_enabled']);
		$cur  = normalize_mail_provider_id(isset($opts['mail_provider_id']) ? (string) $opts['mail_provider_id'] : 'wp_mail');
		$n    = self::OPTION_NAME;

		echo '<input type="hidden" name="' . esc_attr($n) . '[mail_custom_provider_enabled]" value="0" />';
		printf(
			'<p><label><input type="checkbox" name="%1$s[mail_custom_provider_enabled]" value="1" %2$s /> %3$s</label></p>',
			esc_attr($n),
			checked($on, true, false),
			esc_html__('Use a selectable mail provider for login security codes', 'dual-check-2fa')
		);
		echo '<p class="description">' . esc_html__(
			'When unchecked, WordPress wp_mail() is always used. When checked, choose a provider below and save. API keys may be set here or via wp-config constants (see each provider).',
			'dual-check-2fa'
		) . '</p>';

		if (!$on) {
			return;
		}

		echo '<div class="wpdc-mail-provider-panel">';
		echo '<p><label for="wpdc_mail_provider_id">' . esc_html__('Provider', 'dual-check-2fa') . '</label><br />';
		printf('<select id="wpdc_mail_provider_id" name="%s[mail_provider_id]">', esc_attr($n));
		foreach (get_registered_mail_providers() as $row) {
			if (!is_array($row) || empty($row['id']) || !is_string($row['id'])) {
				continue;
			}
			$id    = sanitize_key($row['id']);
			$label = isset($row['label']) && is_string($row['label']) ? $row['label'] : $id;
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr($id),
				selected($cur, $id, false),
				esc_html($label)
			);
		}
		echo '</select></p>';

		if ($cur === 'wp_mail') {
			echo '<p class="description">' . esc_html__(
				'Uses your site\'s normal wp_mail() configuration (including any SMTP plugin).',
				'dual-check-2fa'
			) . '</p>';
		} elseif ($cur === 'sendgrid') {
			$this->render_mail_provider_sendgrid_row($n);
		} elseif ($cur === 'postmark') {
			$this->render_mail_provider_postmark_row($n);
		} elseif ($cur === 'mailgun') {
			$this->render_mail_provider_mailgun_row($n, $opts);
		} elseif ($cur === 'ses') {
			$this->render_mail_provider_ses_row($n, $opts);
		} else {
			echo '<p class="description">' . esc_html__(
				'Extra options for this provider are not shown here; use the dual_check_2fa_mail_provider filter to supply an implementation.',
				'dual-check-2fa'
			) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * @param string $n Option array name.
	 */
	private function render_mail_provider_sendgrid_row(string $n): void {
		$key_opt = Mail_Credentials::SENDGRID_KEY_OPTION;
		$const   = Mail_Credentials::SENDGRID_KEY_CONSTANT;
		echo '<div class="wpdc-mail-provider-sub">';
		echo '<p><strong>' . esc_html__('SendGrid', 'dual-check-2fa') . '</strong></p>';
		if (Mail_Credentials::constant_is_set($const)) {
			echo '<p class="description">' . esc_html__(
				'API key is set in wp-config via DUAL_CHECK_2FA_SENDGRID_API_KEY (not stored in the database).',
				'dual-check-2fa'
			) . '</p>';
		} else {
			printf(
				'<p><label for="wpdc_sg_key">%1$s</label><br /><input type="password" class="regular-text" id="wpdc_sg_key" name="%2$s[%3$s]" value="" autocomplete="new-password" placeholder="%4$s" /></p>',
				esc_html__('API key', 'dual-check-2fa'),
				esc_attr($n),
				esc_attr($key_opt),
				esc_attr__('Leave blank to keep the current key', 'dual-check-2fa')
			);
		}
		echo '</div>';
	}

	/**
	 * @param string $n Option array name.
	 */
	private function render_mail_provider_postmark_row(string $n): void {
		$key_opt = Mail_Credentials::POSTMARK_TOKEN_OPTION;
		$const   = Mail_Credentials::POSTMARK_TOKEN_CONSTANT;
		echo '<div class="wpdc-mail-provider-sub">';
		echo '<p><strong>' . esc_html__('Postmark', 'dual-check-2fa') . '</strong></p>';
		if (Mail_Credentials::constant_is_set($const)) {
			echo '<p class="description">' . esc_html__(
				'Server token is set in wp-config via DUAL_CHECK_2FA_POSTMARK_SERVER_TOKEN.',
				'dual-check-2fa'
			) . '</p>';
		} else {
			printf(
				'<p><label for="wpdc_pm_key">%1$s</label><br /><input type="password" class="regular-text" id="wpdc_pm_key" name="%2$s[%3$s]" value="" autocomplete="new-password" placeholder="%4$s" /></p>',
				esc_html__('Server token', 'dual-check-2fa'),
				esc_attr($n),
				esc_attr($key_opt),
				esc_attr__('Leave blank to keep the current token', 'dual-check-2fa')
			);
		}
		echo '</div>';
	}

	/**
	 * @param string               $n    Option array name.
	 * @param array<string, mixed> $opts Current options.
	 */
	private function render_mail_provider_mailgun_row(string $n, array $opts): void {
		$key_opt  = Mail_Credentials::MAILGUN_KEY_OPTION;
		$dom_opt  = Mail_Credentials::MAILGUN_DOMAIN_OPTION;
		$k_const  = Mail_Credentials::MAILGUN_KEY_CONSTANT;
		$d_const  = Mail_Credentials::MAILGUN_DOMAIN_CONSTANT;
		$region   = isset($opts[ Mail_Credentials::MAILGUN_REGION_OPTION ]) && sanitize_key((string) $opts[ Mail_Credentials::MAILGUN_REGION_OPTION ]) === 'eu' ? 'eu' : 'us';
		$domain_v = '';
		if (!Mail_Credentials::constant_is_set($d_const) && isset($opts[ $dom_opt ]) && is_string($opts[ $dom_opt ])) {
			$domain_v = esc_attr($opts[ $dom_opt ]);
		}
		echo '<div class="wpdc-mail-provider-sub">';
		echo '<p><strong>' . esc_html__('Mailgun', 'dual-check-2fa') . '</strong></p>';
		if (Mail_Credentials::constant_is_set($d_const)) {
			echo '<p class="description">' . esc_html__(
				'Sending domain is set in wp-config via DUAL_CHECK_2FA_MAILGUN_DOMAIN.',
				'dual-check-2fa'
			) . '</p>';
		} else {
			printf(
				'<p><label for="wpdc_mg_domain">%1$s</label><br /><input type="text" class="regular-text" id="wpdc_mg_domain" name="%2$s[%3$s]" value="%4$s" autocomplete="off" /></p>',
				esc_html__('Sending domain (e.g. mg.example.com)', 'dual-check-2fa'),
				esc_attr($n),
				esc_attr($dom_opt),
				$domain_v
			);
		}
		if (Mail_Credentials::constant_is_set(Mail_Credentials::MAILGUN_REGION_CONSTANT)) {
			echo '<p class="description">' . esc_html__(
				'Region is set via DUAL_CHECK_2FA_MAILGUN_REGION (us or eu).',
				'dual-check-2fa'
			) . '</p>';
		} else {
			echo '<p><label for="wpdc_mg_region">' . esc_html__('API region', 'dual-check-2fa') . '</label><br />';
			printf(
				'<select id="wpdc_mg_region" name="%1$s[%6$s]"><option value="us" %2$s>%3$s</option><option value="eu" %4$s>%5$s</option></select></p>',
				esc_attr($n),
				selected($region, 'us', false),
				esc_html__('United States (api.mailgun.net)', 'dual-check-2fa'),
				selected($region, 'eu', false),
				esc_html__('Europe (api.eu.mailgun.net)', 'dual-check-2fa'),
				esc_attr(Mail_Credentials::MAILGUN_REGION_OPTION)
			);
		}
		if (Mail_Credentials::constant_is_set($k_const)) {
			echo '<p class="description">' . esc_html__(
				'Private API key is set in wp-config via DUAL_CHECK_2FA_MAILGUN_API_KEY.',
				'dual-check-2fa'
			) . '</p>';
		} else {
			printf(
				'<p><label for="wpdc_mg_key">%1$s</label><br /><input type="password" class="regular-text" id="wpdc_mg_key" name="%2$s[%3$s]" value="" autocomplete="new-password" placeholder="%4$s" /></p>',
				esc_html__('Private API key', 'dual-check-2fa'),
				esc_attr($n),
				esc_attr($key_opt),
				esc_attr__('Leave blank to keep the current key', 'dual-check-2fa')
			);
		}
		echo '</div>';
	}

	/**
	 * @param string               $n    Option array name.
	 * @param array<string, mixed> $opts Current options.
	 */
	private function render_mail_provider_ses_row(string $n, array $opts): void {
		$ak_opt  = Mail_Credentials::SES_ACCESS_KEY_ID_OPTION;
		$sk_opt  = Mail_Credentials::SES_SECRET_ACCESS_KEY_OPTION;
		$reg_opt = Mail_Credentials::SES_REGION_OPTION;
		$cs_opt  = Mail_Credentials::SES_CONFIGURATION_SET_OPTION;
		$ak_c    = Mail_Credentials::SES_ACCESS_KEY_ID_CONSTANT;
		$sk_c    = Mail_Credentials::SES_SECRET_ACCESS_KEY_CONSTANT;
		$reg_c   = Mail_Credentials::SES_REGION_CONSTANT;
		$cs_c    = Mail_Credentials::SES_CONFIGURATION_SET_CONSTANT;
		$region  = isset($opts[ $reg_opt ]) && is_string($opts[ $reg_opt ]) && $opts[ $reg_opt ] !== ''
			? esc_attr($opts[ $reg_opt ])
			: 'us-east-1';
		$cs_val  = '';
		if (!Mail_Credentials::constant_is_set($cs_c) && isset($opts[ $cs_opt ]) && is_string($opts[ $cs_opt ])) {
			$cs_val = esc_attr($opts[ $cs_opt ]);
		}
		echo '<div class="wpdc-mail-provider-sub">';
		echo '<p><strong>' . esc_html__('Amazon SES', 'dual-check-2fa') . '</strong></p>';
		if (Mail_Credentials::constant_is_set($ak_c)) {
			echo '<p class="description">' . esc_html__(
				'Access key ID is set in wp-config via DUAL_CHECK_2FA_SES_ACCESS_KEY_ID.',
				'dual-check-2fa'
			) . '</p>';
		} else {
			printf(
				'<p><label for="wpdc_ses_ak">%1$s</label><br /><input type="text" class="regular-text" id="wpdc_ses_ak" name="%2$s[%3$s]" value="%4$s" autocomplete="off" /></p>',
				esc_html__('Access key ID', 'dual-check-2fa'),
				esc_attr($n),
				esc_attr($ak_opt),
				isset($opts[ $ak_opt ]) && is_string($opts[ $ak_opt ]) ? esc_attr($opts[ $ak_opt ]) : ''
			);
		}
		if (Mail_Credentials::constant_is_set($sk_c)) {
			echo '<p class="description">' . esc_html__(
				'Secret access key is set in wp-config via DUAL_CHECK_2FA_SES_SECRET_ACCESS_KEY.',
				'dual-check-2fa'
			) . '</p>';
		} else {
			printf(
				'<p><label for="wpdc_ses_sk">%1$s</label><br /><input type="password" class="regular-text" id="wpdc_ses_sk" name="%2$s[%3$s]" value="" autocomplete="new-password" placeholder="%4$s" /></p>',
				esc_html__('Secret access key', 'dual-check-2fa'),
				esc_attr($n),
				esc_attr($sk_opt),
				esc_attr__('Leave blank to keep the current secret', 'dual-check-2fa')
			);
		}
		if (Mail_Credentials::constant_is_set($reg_c)) {
			echo '<p class="description">' . esc_html__(
				'Region is set in wp-config via DUAL_CHECK_2FA_SES_REGION.',
				'dual-check-2fa'
			) . '</p>';
		} else {
			printf(
				'<p><label for="wpdc_ses_region">%1$s</label><br /><input type="text" class="regular-text" id="wpdc_ses_region" name="%2$s[%3$s]" value="%4$s" autocomplete="off" /></p>',
				esc_html__('Region (e.g. us-east-1)', 'dual-check-2fa'),
				esc_attr($n),
				esc_attr($reg_opt),
				$region
			);
		}
		if (Mail_Credentials::constant_is_set($cs_c)) {
			echo '<p class="description">' . esc_html__(
				'Configuration set is set in wp-config via DUAL_CHECK_2FA_SES_CONFIGURATION_SET.',
				'dual-check-2fa'
			) . '</p>';
		} else {
			printf(
				'<p><label for="wpdc_ses_cs">%1$s</label><br /><input type="text" class="regular-text" id="wpdc_ses_cs" name="%2$s[%3$s]" value="%4$s" autocomplete="off" /></p>',
				esc_html__('Configuration set name (optional)', 'dual-check-2fa'),
				esc_attr($n),
				esc_attr($cs_opt),
				$cs_val
			);
		}
		echo '</div>';
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
	 * @return void
	 */
	public function field_allow_user_exempt(): void {
		if (!Security::can_access_main_settings()) {
			return;
		}
		$opts    = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());
		$checked = !empty($opts['allow_user_exempt']);
		$n       = self::OPTION_NAME;
		printf('<input type="hidden" name="%s[allow_user_exempt]" value="0" />', esc_attr($n));
		printf(
			'<label><input type="checkbox" name="%1$s[allow_user_exempt]" value="1" %2$s /> %3$s</label>',
			esc_attr($n),
			checked($checked, true, false),
			esc_html__('Allow administrators to exempt individual users from 2FA on their profile.', 'dual-check-2fa')
		);
	}

	/**
	 * @return void
	 */
	public function field_allow_trusted_devices(): void {
		if (!Security::can_access_main_settings()) {
			return;
		}
		$opts    = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());
		$checked = !empty($opts['allow_trusted_devices']);
		$n       = self::OPTION_NAME;
		printf('<input type="hidden" name="%s[allow_trusted_devices]" value="0" />', esc_attr($n));
		printf(
			'<label><input type="checkbox" name="%1$s[allow_trusted_devices]" value="1" %2$s /> %3$s</label>',
			esc_attr($n),
			checked($checked, true, false),
			esc_html__('Let users skip the code step on remembered browsers after they opt in on the code screen.', 'dual-check-2fa')
		);
	}

	/**
	 * @return void
	 */
	public function field_trusted_devices_days(): void {
		if (!Security::can_access_main_settings()) {
			return;
		}
		$opts = self::clamp_numeric_settings(wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults()));
		$v    = (int) $opts['trusted_devices_days'];
		printf(
			'<input type="number" name="%1$s[trusted_devices_days]" value="%2$d" min="5" max="365" class="small-text" />',
			esc_attr(self::OPTION_NAME),
			absint($v)
		);
		echo '<p class="description">' . esc_html__('Used when trusted devices are enabled.', 'dual-check-2fa') . '</p>';
	}

	/**
	 * @return void
	 */
	public function field_token_gc_enabled(): void {
		if (!Security::can_access_main_settings()) {
			return;
		}
		$opts    = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());
		$checked = !empty($opts['token_gc_enabled']);
		$n       = self::OPTION_NAME;
		printf('<input type="hidden" name="%s[token_gc_enabled]" value="0" />', esc_attr($n));
		printf(
			'<label><input type="checkbox" name="%1$s[token_gc_enabled]" value="1" %2$s /> %3$s</label>',
			esc_attr($n),
			checked($checked, true, false),
			esc_html__('Run daily cleanup of old rows in the token table (recommended).', 'dual-check-2fa')
		);
	}

	/**
	 * @return void
	 */
	public function field_activity_enabled(): void {
		if (!Security::can_access_main_settings()) {
			return;
		}
		$opts    = wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults());
		$checked = !empty($opts['activity_enabled']);
		$n       = self::OPTION_NAME;
		printf('<input type="hidden" name="%s[activity_enabled]" value="0" />', esc_attr($n));
		printf(
			'<label><input type="checkbox" name="%1$s[activity_enabled]" value="1" %2$s /> %3$s</label>',
			esc_attr($n),
			checked($checked, true, false),
			esc_html__('Store security events for the Login Activity screen.', 'dual-check-2fa')
		);
	}

	/**
	 * @return void
	 */
	public function field_activity_retention_days(): void {
		if (!Security::can_access_main_settings()) {
			return;
		}
		$opts = self::clamp_numeric_settings(wp_parse_args(get_option(self::OPTION_NAME, array()), self::defaults()));
		$v    = (int) $opts['activity_retention_days'];
		printf(
			'<input type="number" name="%1$s[activity_retention_days]" value="%2$d" min="1" max="3650" class="small-text" />',
			esc_attr(self::OPTION_NAME),
			absint($v)
		);
	}

	/**
	 * @return void
	 */
	private function render_test_email_section(): void {
		if (!Security::can_access_main_settings()) {
			return;
		}
		if (!(bool) apply_filters('dual_check_2fa_general_test_email_enabled', true)) {
			return;
		}
		$user = wp_get_current_user();
		if (!$user->ID) {
			return;
		}
		$to = sanitize_email((string) $user->user_email);

		echo '<hr />';
		echo '<h2>' . esc_html__('Send test email', 'dual-check-2fa') . '</h2>';
		if (!is_email($to)) {
			echo '<p class="description">' . esc_html__('Your account needs a valid email address to send a test message.', 'dual-check-2fa') . '</p>';

			return;
		}
		$url = admin_url('admin-post.php');
		echo '<form method="post" action="' . esc_url($url) . '">';
		wp_nonce_field(self::TEST_EMAIL_ACTION, 'dual_check_2fa_test_email_nonce');
		echo '<input type="hidden" name="action" value="' . esc_attr(self::TEST_EMAIL_ACTION) . '" />';
		submit_button(__('Send test email', 'dual-check-2fa'), 'secondary', 'submit', false);
		echo '</form>';
		echo '<p class="description">' . esc_html__('Sends a simple HTML message to your account email using the same mail path as login codes.', 'dual-check-2fa') . '</p>';
	}

	/**
	 * Sends a one-off test email from General → Debugging.
	 *
	 * @return void
	 */
	public function handle_test_email_general(): void {
		if (!is_user_logged_in()) {
			wp_die(esc_html__('You must be logged in.', 'dual-check-2fa'), esc_html__('Error', 'dual-check-2fa'), array('response' => 403));
		}
		if (!Security::can_access_main_settings()) {
			wp_die(esc_html__('You do not have permission to do this.', 'dual-check-2fa'), esc_html__('Error', 'dual-check-2fa'), array('response' => 403));
		}
		if (!(bool) apply_filters('dual_check_2fa_general_test_email_enabled', true)) {
			wp_die(esc_html__('This action is disabled.', 'dual-check-2fa'), esc_html__('Error', 'dual-check-2fa'), array('response' => 403));
		}
		check_admin_referer(self::TEST_EMAIL_ACTION, 'dual_check_2fa_test_email_nonce');

		$user = wp_get_current_user();
		$to   = sanitize_email((string) $user->user_email);
		if (!is_email($to)) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'             => self::MENU_SLUG,
						'dc2fa_test_sent' => 'fail',
					),
					admin_url('admin.php')
				)
			);
			exit;
		}

		$subject = __('Dual Check 2FA — test email', 'dual-check-2fa');
		$body    = '<p>' . esc_html__('This is a test message from Dual Check 2FA. If you received it, your mail path is working.', 'dual-check-2fa') . '</p>';
		$headers = array('Content-Type: text/html; charset=UTF-8');
		$ok      = get_default_mail_provider()->send($to, $subject, $body, $headers);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::MENU_SLUG,
					'dc2fa_test_sent' => $ok ? 'ok' : 'fail',
				),
				admin_url('admin.php')
			)
		);
		exit;
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
		if (isset($_GET['dc2fa_test_sent'])) {
			$st = sanitize_key((string) wp_unslash($_GET['dc2fa_test_sent']));
			if ($st === 'ok') {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Test email sent.', 'dual-check-2fa') . '</p></div>';
			} elseif ($st === 'fail') {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Test email could not be sent.', 'dual-check-2fa') . '</p></div>';
			}
		}
		Settings_Notices::render();
		Settings_Save_Handler::render_form_open(Settings_Page::MENU_SLUG);
		printf('<input type="hidden" name="%s[save_context]" value="main" />', esc_attr(Settings_Page::OPTION_NAME));
		do_settings_sections(Settings_Page::MENU_SLUG);
		submit_button(__('Save changes', 'dual-check-2fa'));
		echo '</form>';
		$this->render_test_email_section();
		echo '</div>';
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
			'cap_context_activity'       => array('manage_options'),
			'mail_custom_provider_enabled' => 0,
			'mail_provider_id'             => 'wp_mail',
			Mail_Credentials::SENDGRID_KEY_OPTION   => '',
			Mail_Credentials::POSTMARK_TOKEN_OPTION => '',
			Mail_Credentials::MAILGUN_KEY_OPTION    => '',
			Mail_Credentials::MAILGUN_DOMAIN_OPTION   => '',
			Mail_Credentials::MAILGUN_REGION_OPTION   => 'us',
			'allow_user_exempt'           => 0,
			'allow_trusted_devices'       => 0,
			'trusted_devices_days'        => 30,
			'token_gc_enabled'            => 1,
			'activity_enabled'            => 1,
			'activity_retention_days'     => 90,
			Mail_Credentials::SES_ACCESS_KEY_ID_OPTION       => '',
			Mail_Credentials::SES_SECRET_ACCESS_KEY_OPTION   => '',
			Mail_Credentials::SES_REGION_OPTION              => 'us-east-1',
			Mail_Credentials::SES_CONFIGURATION_SET_OPTION   => '',
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

		foreach (array('cap_context_main', 'cap_context_email', 'cap_context_activity') as $key) {
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
		$options['trusted_devices_days'] = max(
			5,
			min(365, absint($options['trusted_devices_days'] ?? $d['trusted_devices_days']))
		);
		$options['activity_retention_days'] = max(
			1,
			min(3650, absint($options['activity_retention_days'] ?? $d['activity_retention_days']))
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
		foreach (array('allow_user_exempt', 'allow_trusted_devices', 'token_gc_enabled', 'activity_enabled') as $bool_key) {
			if (!isset($options[ $bool_key ])) {
				$options[ $bool_key ] = $d[ $bool_key ];
			} else {
				$options[ $bool_key ] = (int) !empty($options[ $bool_key ]);
			}
		}

		return $options;
	}
}
