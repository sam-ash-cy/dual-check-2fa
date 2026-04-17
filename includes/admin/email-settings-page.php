<?php

namespace WP_DUAL_CHECK\admin;

use WP_DUAL_CHECK\core\Security;
use WP_DUAL_CHECK\email\Login_Email_Builder;
use WP_DUAL_CHECK\Logging\Logger;
use function WP_DUAL_CHECK\db\dual_check_settings;
use function WP_DUAL_CHECK\delivery\get_default_mail_provider;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Admin screen for login email subject/body/colours and test send.
 */
final class Email_Settings_Page implements Admin_Settings_Page {

	public const PAGE = 'wp-dual-check-email';

	/**
	 * Registers submenu, settings sections, assets, and test-email handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action('admin_menu', array($this, 'add_menu'), 11);
		add_action('admin_init', array($this, 'register_fields'), 20);
		add_action('admin_enqueue_scripts', array($this, 'enqueue_colors'));
		add_action('admin_post_wp_dual_check_test_email', array($this, 'handle_test_email_post'));
	}

	/**
	 * Adds the “Login Email Template” submenu under WP Dual Check.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		if (!self::is_custom_template_enabled()) {
			return;
		}

		add_submenu_page(
			Settings_Page::MENU_SLUG,
			__('Login Email Template', 'wp-dual-check'),
			__('Login Email Template', 'wp-dual-check'),
			Security::menu_capability_for_email(),
			self::PAGE,
			array($this, 'render_page')
		);
	}

	/**
	 * Whether “Use custom email template” is on (gates submenu, fields, saves, and outbound mail styling).
	 *
	 * @return bool
	 */
	public static function is_custom_template_enabled(): bool {
		$s = dual_check_settings();

		return !empty($s['email_use_custom_template']);
	}

	/**
	 * Registers settings sections and fields on the email template admin page.
	 *
	 * @return void
	 */
	public function register_fields(): void {
		if (!self::is_custom_template_enabled()) {
			return;
		}

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

	/**
	 * Loads the colour picker on the email settings screen only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_colors(string $hook): void {
		if (!self::is_custom_template_enabled()) {
			return;
		}
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

	/**
	 * Renders the email template form and test-email form.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if (!Security::can_access_email_template()) {
			wp_die(esc_html__('You do not have permission to access this page.', 'wp-dual-check'));
		}

		if (!self::is_custom_template_enabled()) {
			wp_safe_redirect(add_query_arg('page', Settings_Page::MENU_SLUG, admin_url('admin.php')));
			exit;
		}

		echo '<div class="wrap"><h1>' . esc_html__('Login Email Template', 'wp-dual-check') . '</h1>';
		$this->render_query_flash_notice();
		Settings_Notices::render();
		Settings_Save_Handler::render_form_open(self::PAGE);
		printf('<input type="hidden" name="%s[save_context]" value="email" />', esc_attr(Settings_Page::OPTION_NAME));
		do_settings_sections(self::PAGE);
		submit_button(__('Save changes', 'wp-dual-check'));
		echo '</form>';

		echo '<hr />';
		echo '<h2>' . esc_html__('Send test email', 'wp-dual-check') . '</h2>';
		echo '<p class="description">' . esc_html__('Sends a sample login-style message to your account email (placeholder code 000000).', 'wp-dual-check') . '</p>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		wp_nonce_field('wp_dual_check_test_email');
		echo '<input type="hidden" name="action" value="wp_dual_check_test_email" />';
		submit_button(__('Send test email', 'wp-dual-check'), 'secondary');
		echo '</form></div>';
	}

	/**
	 * Sends a sample login email to the current admin user’s address.
	 *
	 * @return void
	 */
	public function handle_test_email_post(): void {
		if (!Security::can_access_email_template()) {
			wp_die(esc_html__('You do not have permission to do this.', 'wp-dual-check'));
		}
		check_admin_referer('wp_dual_check_test_email');

		if (!self::is_custom_template_enabled()) {
			wp_safe_redirect(add_query_arg('page', Settings_Page::MENU_SLUG, admin_url('admin.php')));
			exit;
		}

		$user = wp_get_current_user();
		$to   = $user->user_email;
		if ($to === '') {
			wp_safe_redirect(add_query_arg(array('page' => self::PAGE, 'wpdc_msg' => 'no_email'), admin_url('admin.php')));
			exit;
		}

		$mail    = Login_Email_Builder::build('000000', $user->user_login);
		$headers = array('Content-Type: text/html; charset=UTF-8');
		$sent    = get_default_mail_provider()->send($to, $mail['subject'], $mail['html'], $headers);
		Logger::debug('test_email', array('to' => $to, 'sent' => (bool) $sent));

		$arg = $sent ? 'test_ok' : 'test_fail';
		wp_safe_redirect(add_query_arg(array('page' => self::PAGE, 'wpdc_msg' => $arg), admin_url('admin.php')));
		exit;
	}

	/**
	 * Shows one-off admin notices after redirect from test email handler.
	 *
	 * @return void
	 */
	private function render_query_flash_notice(): void {
		if (!isset($_GET['wpdc_msg'])) {
			return;
		}
		$key = sanitize_key((string) wp_unslash($_GET['wpdc_msg']));
		if ($key === 'test_ok') {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Test email sent.', 'wp-dual-check') . '</p></div>';

			return;
		}
		if ($key === 'test_fail') {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Could not send test email. Check your site mail configuration.', 'wp-dual-check') . '</p></div>';

			return;
		}
		if ($key === 'no_email') {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Your user account has no email address.', 'wp-dual-check') . '</p></div>';
		}
	}

	/**
	 * @param array<string, mixed> $post Same slice as option save.
	 * @param array<string, mixed> $out  Merged option row to update.
	 * @return array<string, mixed>
	 */
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

	/**
	 * Prints help text for email placeholders above the template fields.
	 *
	 * @return void
	 */
	public function section_intro(): void {
		$list = '[site-name], [code], [user-login], [expires], [site-url]';
		echo '<p>' . esc_html__('Placeholders in subject and body are replaced when mail is sent.', 'wp-dual-check') . '</p>';
		echo '<p class="description"><code>' . esc_html($list) . '</code></p>';
	}

	/**
	 * Renders the email subject textarea.
	 *
	 * @return void
	 */
	public function field_subject(): void {
		$v = (string) (dual_check_settings()['email_subject_template'] ?? '');
		printf(
			'<textarea class="large-text" rows="2" name="%1$s[email_subject_template]" id="wpdc_email_subject">%2$s</textarea>',
			esc_attr(Settings_Page::OPTION_NAME),
			esc_textarea($v)
		);
		echo '<p class="description">' . esc_html__('If empty, the default subject will be used.', 'wp-dual-check') . '</p>';
	}

	/**
	 * Renders the HTML body textarea.
	 *
	 * @return void
	 */
	public function field_body(): void {
		$v = (string) (dual_check_settings()['email_body_template'] ?? '');
		printf(
			'<textarea class="large-text code" rows="12" name="%1$s[email_body_template]" id="wpdc_email_body">%2$s</textarea>',
			esc_attr(Settings_Page::OPTION_NAME),
			esc_textarea($v)
		);
		echo '<p class="description">' . esc_html__('If empty, the default body will be used.', 'wp-dual-check') . '</p>';
	}

	/**
	 * Renders optional header HTML for the mail wrapper.
	 *
	 * @return void
	 */
	public function field_header(): void {
		$v = (string) (dual_check_settings()['email_header_html'] ?? '');
		printf(
			'<textarea class="large-text code" rows="3" name="%1$s[email_header_html]" id="wpdc_email_header">%2$s</textarea>',
			esc_attr(Settings_Page::OPTION_NAME),
			esc_textarea($v)
		);
	}

	/**
	 * Renders optional footer HTML for the mail wrapper.
	 *
	 * @return void
	 */
	public function field_footer(): void {
		$v = (string) (dual_check_settings()['email_footer_html'] ?? '');
		printf(
			'<textarea class="large-text code" rows="3" name="%1$s[email_footer_html]" id="wpdc_email_footer">%2$s</textarea>',
			esc_attr(Settings_Page::OPTION_NAME),
			esc_textarea($v)
		);
	}

	/**
	 * Renders colour picker inputs for link/header/footer backgrounds.
	 *
	 * @return void
	 */
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
