<?php

namespace WP_DUAL_CHECK\admin;

use function WP_DUAL_CHECK\db\dual_check_settings;

if (!defined('ABSPATH')) {
	exit;
}

final class Email_Settings_Page implements Admin_Settings_Page {

	public const PAGE = 'wp-dual-check-email';

	public function register(): void {
		add_action('admin_menu', array($this, 'add_menu'), 11);
		add_action('admin_init', array($this, 'register_fields'), 20);
		add_action('admin_enqueue_scripts', array($this, 'enqueue_colors'));
	}

	public function add_menu(): void {
		add_submenu_page(
			Settings_Page::MENU_SLUG,
			__('Login email', 'wp-dual-check'),
			__('Login email', 'wp-dual-check'),
			'manage_options',
			self::PAGE,
			array($this, 'render_page')
		);
	}

	public function register_fields(): void {
		add_settings_section(
			'wpdc_email_options',
			__('Template', 'wp-dual-check'),
			static function (): void {
				echo '<p class="description">' . esc_html__('Turn on custom HTML to use the fields below instead of templates/email/default-template.php.', 'wp-dual-check') . '</p>';
			},
			self::PAGE
		);

		add_settings_field(
			'email_use_custom_template',
			__('Use custom HTML', 'wp-dual-check'),
			array($this, 'field_use_custom'),
			self::PAGE,
			'wpdc_email_options'
		);

		add_settings_section(
			'wpdc_email_body',
			__('Content', 'wp-dual-check'),
			array($this, 'section_intro'),
			self::PAGE
		);

		add_settings_field(
			'email_subject_template',
			__('Subject', 'wp-dual-check'),
			array($this, 'field_subject'),
			self::PAGE,
			'wpdc_email_body'
		);

		add_settings_field(
			'email_body_template',
			__('Body (HTML)', 'wp-dual-check'),
			array($this, 'field_body'),
			self::PAGE,
			'wpdc_email_body'
		);

		add_settings_field(
			'email_header_html',
			__('Header HTML', 'wp-dual-check'),
			array($this, 'field_header'),
			self::PAGE,
			'wpdc_email_body'
		);

		add_settings_field(
			'email_footer_html',
			__('Footer HTML', 'wp-dual-check'),
			array($this, 'field_footer'),
			self::PAGE,
			'wpdc_email_body'
		);

		add_settings_section(
			'wpdc_email_colors',
			__('Colours', 'wp-dual-check'),
			static function (): void {
				echo '<p class="description">' . esc_html__('Used for the HTML wrapper on every login email.', 'wp-dual-check') . '</p>';
			},
			self::PAGE
		);

		add_settings_field(
			'email_colors',
			__('Pick colours', 'wp-dual-check'),
			array($this, 'field_colors'),
			self::PAGE,
			'wpdc_email_colors'
		);
	}

	public function enqueue_colors(string $hook): void {
		if ($hook !== Settings_Page::MENU_SLUG . '_page_' . self::PAGE) {
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

	public function render_page(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-dual-check'));
		}

		echo '<div class="wrap"><h1>' . esc_html__('Login email', 'wp-dual-check') . '</h1>';
		echo '<form action="options.php" method="post">';
		settings_fields('wp_dual_check_settings_group');
		printf('<input type="hidden" name="%s[save_context]" value="email" />', esc_attr(Settings_Page::OPTION_NAME));
		do_settings_sections(self::PAGE);
		submit_button(__('Save changes', 'wp-dual-check'));
		echo '</form></div>';
	}

	/**
	 * @param array<string, mixed> $post Same slice as option save.
	 * @param array<string, mixed> $out  Merged option row to update.
	 * @return array<string, mixed>
	 */
	public function field_use_custom(): void {
		$on = !empty(dual_check_settings()['email_use_custom_template']);
		$n  = Settings_Page::OPTION_NAME;
		printf('<input type="hidden" name="%1$s[email_use_custom_template]" value="0" />', esc_attr($n));
		printf(
			'<label><input type="checkbox" name="%1$s[email_use_custom_template]" value="1" %2$s /> %3$s</label>',
			esc_attr($n),
			checked($on, true, false),
			esc_html__('Send the subject and body from this screen (not the template files)', 'wp-dual-check')
		);
	}

	public static function merge_from_post(array $post, array $out): array {
		if (isset($post['email_subject_template'])) {
			$out['email_subject_template'] = sanitize_textarea_field(wp_unslash((string) $post['email_subject_template']));
		}
		if (isset($post['email_body_template'])) {
			$out['email_body_template'] = wp_kses_post(wp_unslash((string) $post['email_body_template']));
		}
		if (isset($post['email_header_html'])) {
			$out['email_header_html'] = wp_kses_post(wp_unslash((string) $post['email_header_html']));
		}
		if (isset($post['email_footer_html'])) {
			$out['email_footer_html'] = wp_kses_post(wp_unslash((string) $post['email_footer_html']));
		}
		if (isset($post['email_color_link'])) {
			$c = sanitize_hex_color(wp_unslash((string) $post['email_color_link']));
			$out['email_color_link'] = $c ?: Settings_Page::defaults()['email_color_link'];
		}
		if (isset($post['email_color_header_bg'])) {
			$c = sanitize_hex_color(wp_unslash((string) $post['email_color_header_bg']));
			$out['email_color_header_bg'] = $c ?: Settings_Page::defaults()['email_color_header_bg'];
		}
		if (isset($post['email_color_footer_bg'])) {
			$c = sanitize_hex_color(wp_unslash((string) $post['email_color_footer_bg']));
			$out['email_color_footer_bg'] = $c ?: Settings_Page::defaults()['email_color_footer_bg'];
		}

		return $out;
	}

	public function section_intro(): void {
		$list = '[site-name], [code], [user-login], [expires], [site-url], [minutes], [timezone]';
		echo '<p>' . esc_html__('Placeholders in subject and body are replaced when mail is sent.', 'wp-dual-check') . '</p>';
		echo '<p class="description"><code>' . esc_html($list) . '</code></p>';
	}

	public function field_subject(): void {
		$v = (string) (dual_check_settings()['email_subject_template'] ?? '');
		printf(
			'<textarea class="large-text" rows="2" name="%1$s[email_subject_template]" id="wpdc_email_subject">%2$s</textarea>',
			esc_attr(Settings_Page::OPTION_NAME),
			esc_textarea($v)
		);
		echo '<p class="description">' . esc_html__('Empty = default subject.', 'wp-dual-check') . '</p>';
	}

	public function field_body(): void {
		$v = (string) (dual_check_settings()['email_body_template'] ?? '');
		printf(
			'<textarea class="large-text code" rows="12" name="%1$s[email_body_template]" id="wpdc_email_body">%2$s</textarea>',
			esc_attr(Settings_Page::OPTION_NAME),
			esc_textarea($v)
		);
		echo '<p class="description">' . esc_html__('Empty = default body.', 'wp-dual-check') . '</p>';
	}

	public function field_header(): void {
		$v = (string) (dual_check_settings()['email_header_html'] ?? '');
		printf(
			'<textarea class="large-text code" rows="3" name="%1$s[email_header_html]" id="wpdc_email_header">%2$s</textarea>',
			esc_attr(Settings_Page::OPTION_NAME),
			esc_textarea($v)
		);
	}

	public function field_footer(): void {
		$v = (string) (dual_check_settings()['email_footer_html'] ?? '');
		printf(
			'<textarea class="large-text code" rows="3" name="%1$s[email_footer_html]" id="wpdc_email_footer">%2$s</textarea>',
			esc_attr(Settings_Page::OPTION_NAME),
			esc_textarea($v)
		);
	}

	public function field_colors(): void {
		$o      = dual_check_settings();
		$link   = (string) ($o['email_color_link'] ?? '#2271b1');
		$header = (string) ($o['email_color_header_bg'] ?? '#2271b1');
		$footer = (string) ($o['email_color_footer_bg'] ?? '#f0f0f1');
		$name   = Settings_Page::OPTION_NAME;

		echo '<p><label for="wpdc_color_link">' . esc_html__('Links', 'wp-dual-check') . '</label><br />';
		printf(
			'<input type="text" id="wpdc_color_link" class="wpdc-color-field" name="%1$s[email_color_link]" value="%2$s" />',
			esc_attr($name),
			esc_attr($link)
		);
		echo '</p><p><label for="wpdc_color_header">' . esc_html__('Header background', 'wp-dual-check') . '</label><br />';
		printf(
			'<input type="text" id="wpdc_color_header" class="wpdc-color-field" name="%1$s[email_color_header_bg]" value="%2$s" />',
			esc_attr($name),
			esc_attr($header)
		);
		echo '</p><p><label for="wpdc_color_footer">' . esc_html__('Footer background', 'wp-dual-check') . '</label><br />';
		printf(
			'<input type="text" id="wpdc_color_footer" class="wpdc-color-field" name="%1$s[email_color_footer_bg]" value="%2$s" />',
			esc_attr($name),
			esc_attr($footer)
		);
		echo '</p>';
	}
}
