<?php

namespace DualCheck2FA\admin;

use DualCheck2FA\core\Security;
use function DualCheck2FA\db\dual_check_settings;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Capabilities: capability pool and OR-based access for main vs email admin areas.
 */
final class Permissions_Settings_Page implements Admin_Settings_Page {

	public const PAGE = 'dual-check-2fa-permissions';

	/**
	 * Built-in capability slugs offered as checkboxes for the pool.
	 *
	 * @return array<int, string>
	 */
	public static function preset_cap_slugs(): array {
		$presets = array('manage_options', 'edit_users', 'list_users');
		if (is_multisite()) {
			$presets[] = 'manage_network';
		}

		return $presets;
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_action('admin_menu', array($this, 'add_menu'), 12);
		add_action('admin_init', array($this, 'register_fields'), 21);
	}

	/**
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			Settings_Page::MENU_SLUG,
			__('Capabilities', 'dual-check-2fa'),
			__('Capabilities', 'dual-check-2fa'),
			Security::menu_capability_for_main(),
			self::PAGE,
			array($this, 'render_page')
		);
	}

	/**
	 * @return void
	 */
	public function register_fields(): void {
		add_settings_section(
			'dc2fa_cap_pool',
			__('Capability pool', 'dual-check-2fa'),
			array($this, 'section_pool_intro'),
			self::PAGE
		);

		add_settings_field(
			'cap_presets',
			__('Built-in capabilities', 'dual-check-2fa'),
			array($this, 'field_cap_presets'),
			self::PAGE,
			'dc2fa_cap_pool'
		);

		add_settings_field(
			'cap_custom',
			__('Additional capabilities (one per line)', 'dual-check-2fa'),
			array($this, 'field_cap_custom'),
			self::PAGE,
			'dc2fa_cap_pool'
		);

		add_settings_section(
			'dc2fa_cap_main',
			__('Main settings & this screen', 'dual-check-2fa'),
			array($this, 'section_main_intro'),
			self::PAGE
		);

		add_settings_section(
			'dc2fa_cap_email',
			__('Login email template', 'dual-check-2fa'),
			array($this, 'section_email_intro'),
			self::PAGE
		);
	}

	/**
	 * @return void
	 */
	public function section_pool_intro(): void {
		echo '<p class="description">' . esc_html__('Select capabilities to include in the pool. Each access rule below can only use caps from this pool (plus you can add custom slugs).', 'dual-check-2fa') . '</p>';
	}

	/**
	 * @return void
	 */
	public function section_main_intro(): void {
		echo '<p class="description">' . esc_html__('Users who have any one of the selected capabilities may open General settings, save them, and use this Capabilities screen.', 'dual-check-2fa') . '</p>';
		$this->render_context_checkboxes('cap_context_main', 'cap_main');
	}

	/**
	 * @return void
	 */
	public function section_email_intro(): void {
		echo '<p class="description">' . esc_html__('Users who have any one of the selected capabilities may use the Login Email Template screen (when custom template is enabled) and send test emails.', 'dual-check-2fa') . '</p>';
		$this->render_context_checkboxes('cap_context_email', 'cap_email');
	}

	/**
	 * @param string $context_key Option key `cap_context_main` or `cap_context_email`.
	 * @param string $post_prefix Post array prefix `cap_main` or `cap_email`.
	 * @return void
	 */
	private function render_context_checkboxes(string $context_key, string $post_prefix): void {
		$opts    = wp_parse_args(get_option(Settings_Page::OPTION_NAME, array()), Settings_Page::defaults());
		$pool    = isset($opts['cap_pool']) && is_array($opts['cap_pool']) ? Security::normalize_cap_list($opts['cap_pool']) : array('manage_options');
		$active  = isset($opts[ $context_key ]) && is_array($opts[ $context_key ]) ? Security::normalize_cap_list($opts[ $context_key ]) : array('manage_options');
		$name    = Settings_Page::OPTION_NAME;

		foreach ($pool as $slug) {
			$id    = 'dc2fa_' . $post_prefix . '_' . $slug;
			$check = in_array($slug, $active, true);
			printf(
				'<p><label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s[%3$s][%4$s]" value="1" %5$s /> <code>%4$s</code></label></p>',
				esc_attr($id),
				esc_attr($name),
				esc_attr($post_prefix),
				esc_attr($slug),
				checked($check, true, false)
			);
		}
	}

	/**
	 * @return void
	 */
	public function field_cap_presets(): void {
		$opts = wp_parse_args(get_option(Settings_Page::OPTION_NAME, array()), Settings_Page::defaults());
		$pool = isset($opts['cap_pool']) && is_array($opts['cap_pool']) ? Security::normalize_cap_list($opts['cap_pool']) : array('manage_options');
		$name = Settings_Page::OPTION_NAME;
		foreach (self::preset_cap_slugs() as $slug) {
			$inPool = in_array($slug, $pool, true);
			printf(
				'<p><label><input type="checkbox" name="%1$s[cap_preset][%2$s]" value="1" %3$s /> <code>%2$s</code></label></p>',
				esc_attr($name),
				esc_attr($slug),
				checked($inPool, true, false)
			);
		}
	}

	/**
	 * @return void
	 */
	public function field_cap_custom(): void {
		$opts = wp_parse_args(get_option(Settings_Page::OPTION_NAME, array()), Settings_Page::defaults());
		$pool = isset($opts['cap_pool']) && is_array($opts['cap_pool']) ? Security::normalize_cap_list($opts['cap_pool']) : array('manage_options');
		$pres = self::preset_cap_slugs();
		$extra = array();
		foreach ($pool as $c) {
			if (!in_array($c, $pres, true)) {
				$extra[] = $c;
			}
		}
		$text = implode("\n", $extra);
		printf(
			'<textarea class="large-text code" rows="4" name="%1$s[cap_custom]" id="dc2fa_cap_custom">%2$s</textarea>',
			esc_attr(Settings_Page::OPTION_NAME),
			esc_textarea($text)
		);
		echo '<p class="description">' . esc_html__('Lowercase letters, digits, and underscores only. One capability per line (or comma-separated).', 'dual-check-2fa') . '</p>';
	}

	/**
	 * @return void
	 */
	public function render_page(): void {
		if (!is_user_logged_in()) {
			wp_die(esc_html__('You must be logged in.', 'dual-check-2fa'), esc_html__('Error', 'dual-check-2fa'), array('response' => 403));
		}
		if (!Security::can_access_main_settings()) {
			wp_die(esc_html__('You do not have permission to access this page.', 'dual-check-2fa'), esc_html__('Error', 'dual-check-2fa'), array('response' => 403));
		}

		echo '<div class="wrap"><h1>' . esc_html__('Dual Check 2FA — Capabilities', 'dual-check-2fa') . '</h1>';
		Settings_Notices::render();
		Settings_Save_Handler::render_form_open(Permissions_Settings_Page::PAGE);
		printf('<input type="hidden" name="%s[save_context]" value="permissions" />', esc_attr(Settings_Page::OPTION_NAME));
		do_settings_sections(Permissions_Settings_Page::PAGE);
		submit_button(__('Save capabilities', 'dual-check-2fa'));
		echo '</form></div>';
	}

	/**
	 * Merge POSTed capability UI into the option row.
	 *
	 * @param array<string, mixed> $input Same slice as main option save.
	 * @param array<string, mixed> $out   Current merged row.
	 * @return array<string, mixed>
	 */
	public static function merge_from_post(array $input, array $out): array {
		$pool = array();
		if (!empty($input['cap_preset']) && is_array($input['cap_preset'])) {
			foreach ($input['cap_preset'] as $slug => $on) {
				if (empty($on)) {
					continue;
				}
				$s = Security::sanitize_cap_slug((string) $slug);
				if ($s !== '' && in_array($s, self::preset_cap_slugs(), true)) {
					$pool[] = $s;
				}
			}
		}
		if (isset($input['cap_custom']) && is_string($input['cap_custom'])) {
			$raw = wp_unslash($input['cap_custom']);
			foreach (preg_split('/[\r\n,]+/', $raw) as $line) {
				$s = Security::sanitize_cap_slug(trim($line));
				if ($s !== '') {
					$pool[] = $s;
				}
			}
		}
		$pool = Security::normalize_cap_list($pool);
		if ($pool === array()) {
			$pool = array('manage_options');
		}
		$out['cap_pool'] = $pool;

		foreach (array(
			'cap_main'   => 'cap_context_main',
			'cap_email'  => 'cap_context_email',
		) as $postKey => $optKey) {
			$selected = array();
			if (!empty($input[ $postKey ]) && is_array($input[ $postKey ])) {
				foreach ($input[ $postKey ] as $slug => $on) {
					if (empty($on)) {
						continue;
					}
					$s = Security::sanitize_cap_slug((string) $slug);
					if ($s !== '' && in_array($s, $pool, true)) {
						$selected[] = $s;
					}
				}
			}
			$out[ $optKey ] = Security::normalize_cap_list($selected);
		}

		return $out;
	}
}
