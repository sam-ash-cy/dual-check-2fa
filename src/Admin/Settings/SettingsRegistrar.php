<?php
/**
 * Registers settings, sections, and fields with WordPress.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Admin\Settings;

/**
 * Wires WordPress Settings API screens and admin-post handlers.
 */
final class SettingsRegistrar {

	/** Registers admin menu hooks and the test-mail admin-post action. */
	public static function register(): void {
		add_action( 'admin_menu', array( SettingsPage::class, 'add_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( SettingsPage::class, 'enqueue_email_template_assets' ) );
		add_action( 'admin_post_wp_dual_check_send_test_mail', array( SettingsPage::class, 'handle_send_test_mail' ) );
	}

	/** Registers the option, sections, and fields for all plugin settings pages. */
	public static function register_settings(): void {
		register_setting(
			SettingsRepository::OPTION_GROUP,
			SettingsRepository::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( SettingsSanitizer::class, 'sanitize' ),
				'default'           => SettingsRepository::DEFAULTS,
			)
		);

		add_settings_section(
			'wdc_main',
			__( 'Email challenge', 'wp-dual-check' ),
			static function () {
				echo '<p>' . esc_html__( 'When enabled below, every successful password login goes to the email code step before access is granted (wp-login.php, wp-admin, and any flow using WordPress authentication).', 'wp-dual-check' ) . '</p>';
				echo '<p>' . esc_html__( 'Secrets for mail (DSN, API keys) belong in environment variables or wp-config.php when possible—not in these form fields.', 'wp-dual-check' ) . '</p>';
			},
			SettingsRepository::PAGE_SLUG
		);

		$page = SettingsRepository::PAGE_SLUG;

		add_settings_field( 'wdc_require_all_logins', __( 'All users', 'wp-dual-check' ), array( SettingsPage::class, 'field_require_all_logins' ), $page, 'wdc_main' );
		add_settings_field( 'wdc_code_ttl', __( 'Code / session expiry (seconds)', 'wp-dual-check' ), array( SettingsPage::class, 'field_code_ttl' ), $page, 'wdc_main' );
		add_settings_field( 'wdc_code_length', __( 'Code length (digits)', 'wp-dual-check' ), array( SettingsPage::class, 'field_code_length' ), $page, 'wdc_main' );
		add_settings_field( 'wdc_max_attempts', __( 'Max wrong code attempts', 'wp-dual-check' ), array( SettingsPage::class, 'field_max_attempts' ), $page, 'wdc_main' );
		add_settings_field( 'wdc_resend_cooldown', __( 'Resend cooldown (seconds)', 'wp-dual-check' ), array( SettingsPage::class, 'field_resend_cooldown' ), $page, 'wdc_main' );
		add_settings_field( 'wdc_from_email', __( 'From email', 'wp-dual-check' ), array( SettingsPage::class, 'field_from_email' ), $page, 'wdc_main' );
		add_settings_field( 'wdc_from_name', __( 'From name', 'wp-dual-check' ), array( SettingsPage::class, 'field_from_name' ), $page, 'wdc_main' );
		add_settings_field( 'wdc_default_mailer_transport', __( 'Default mail transport', 'wp-dual-check' ), array( SettingsPage::class, 'field_default_mailer_transport' ), $page, 'wdc_main' );

		self::register_provider_pages();
		self::register_email_template_page();

		add_settings_section(
			'wdc_diagnostics',
			__( 'Diagnostics', 'wp-dual-check' ),
			static function () {
				echo '<p>' . esc_html__( 'Enable debug logging for structured plugin lines in the PHP error log. Use the test email form below to verify your DSN without signing in.', 'wp-dual-check' ) . '</p>';
			},
			$page
		);

		add_settings_field( 'wdc_debug_logging', __( 'Debug logging', 'wp-dual-check' ), array( SettingsPage::class, 'field_debug_logging' ), $page, 'wdc_diagnostics' );
	}

	/** Registers credential sections for SendGrid, Mailgun, SES, Postmark, Gmail. */
	private static function register_provider_pages(): void {
		$intro = static function (): void {
			echo '<p>' . esc_html__( 'Prefer environment variables or wp-config.php on production. Values here are stored in the database. Leave a secret field blank when saving to keep the previous value.', 'wp-dual-check' ) . '</p>';
		};

		$providers_page = SettingsRepository::PAGE_MAIL_PROVIDERS;

		add_settings_section( 'wdc_sendgrid', '', $intro, $providers_page );
		add_settings_field( 'wdc_api_sendgrid', __( 'Credentials', 'wp-dual-check' ), array( SettingsPage::class, 'field_api_sendgrid' ), $providers_page, 'wdc_sendgrid' );

		add_settings_section( 'wdc_mailgun', '', $intro, $providers_page );
		add_settings_field( 'wdc_api_mailgun', __( 'Credentials', 'wp-dual-check' ), array( SettingsPage::class, 'field_api_mailgun' ), $providers_page, 'wdc_mailgun' );

		add_settings_section( 'wdc_ses', '', $intro, $providers_page );
		add_settings_field( 'wdc_api_ses', __( 'Credentials', 'wp-dual-check' ), array( SettingsPage::class, 'field_api_ses' ), $providers_page, 'wdc_ses' );

		add_settings_section( 'wdc_postmark', '', $intro, $providers_page );
		add_settings_field( 'wdc_api_postmark', __( 'Credentials', 'wp-dual-check' ), array( SettingsPage::class, 'field_api_postmark' ), $providers_page, 'wdc_postmark' );

		add_settings_section( 'wdc_gmail', '', $intro, $providers_page );
		add_settings_field( 'wdc_api_gmail', __( 'Credentials', 'wp-dual-check' ), array( SettingsPage::class, 'field_api_gmail' ), $providers_page, 'wdc_gmail' );
	}

	/** Subject, layout, and body fields for the email template submenu. */
	private static function register_email_template_page(): void {
		$email_template_page = SettingsRepository::PAGE_EMAIL_TEMPLATE;

		add_settings_section(
			'wdc_email_subject',
			__( 'Subject & format', 'wp-dual-check' ),
			static function (): void {
				echo '<p>' . esc_html__( 'The subject is sent as plain text (any HTML is removed).', 'wp-dual-check' ) . '</p>';
				echo '<p><strong>' . esc_html__( 'Placeholders', 'wp-dual-check' ) . '</strong> — '
					. esc_html__( 'use in the subject, plain-text body, and HTML body:', 'wp-dual-check' ) . '</p>';
				echo '<ul style="list-style:disc;padding-left:1.25em;">';
				$tags = array( '{site_name}', '{user_name}', '{code}', '{ip_address}', '{login_time}', '{expiry_minutes}' );
				foreach ( $tags as $placeholder_tag ) {
					echo '<li><code>' . esc_html( $placeholder_tag ) . '</code></li>';
				}
				echo '</ul>';
				echo '<p class="description">' . esc_html__( '{expiry_minutes} is the code lifetime in whole minutes. Leave the HTML body empty to use the plugin’s built-in styled layout for the code.', 'wp-dual-check' ) . '</p>';
			},
			$email_template_page
		);
		add_settings_field( 'email_subject_template', __( 'Subject line', 'wp-dual-check' ), array( SettingsPage::class, 'field_email_subject_template' ), $email_template_page, 'wdc_email_subject' );
		add_settings_field( 'email_format', __( 'Email format', 'wp-dual-check' ), array( SettingsPage::class, 'field_email_format' ), $email_template_page, 'wdc_email_subject' );

		add_settings_section(
			'wdc_email_look',
			__( 'HTML layout', 'wp-dual-check' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Colours and images wrap the HTML template. Widths are in pixels (200–920).', 'wp-dual-check' ) . '</p>';
			},
			$email_template_page
		);
		add_settings_field( 'email_accent_color', __( 'Accent colour', 'wp-dual-check' ), array( SettingsPage::class, 'field_email_accent_color' ), $email_template_page, 'wdc_email_look' );
		add_settings_field( 'email_background_color', __( 'Outer background colour', 'wp-dual-check' ), array( SettingsPage::class, 'field_email_background_color' ), $email_template_page, 'wdc_email_look' );
		add_settings_field( 'email_container_max_width_px', __( 'Email content max width', 'wp-dual-check' ), array( SettingsPage::class, 'field_email_container_max_width_px' ), $email_template_page, 'wdc_email_look' );
		add_settings_field( 'email_header_text', __( 'Header bar (HTML)', 'wp-dual-check' ), array( SettingsPage::class, 'field_email_header_text' ), $email_template_page, 'wdc_email_look' );
		add_settings_field( 'email_header_image_url', __( 'Header image URL', 'wp-dual-check' ), array( SettingsPage::class, 'field_email_header_image_url' ), $email_template_page, 'wdc_email_look' );
		add_settings_field( 'email_header_width_px', __( 'Header image width', 'wp-dual-check' ), array( SettingsPage::class, 'field_email_header_width_px' ), $email_template_page, 'wdc_email_look' );
		add_settings_field( 'email_footer_image_url', __( 'Footer image URL', 'wp-dual-check' ), array( SettingsPage::class, 'field_email_footer_image_url' ), $email_template_page, 'wdc_email_look' );
		add_settings_field( 'email_footer_width_px', __( 'Footer image width', 'wp-dual-check' ), array( SettingsPage::class, 'field_email_footer_width_px' ), $email_template_page, 'wdc_email_look' );
		add_settings_field( 'email_footer_text', __( 'Footer (HTML)', 'wp-dual-check' ), array( SettingsPage::class, 'field_email_footer_text' ), $email_template_page, 'wdc_email_look' );

		add_settings_section(
			'wdc_email_bodies',
			__( 'Message templates', 'wp-dual-check' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Plain text is always used for the plain-text part (and required for multipart). HTML is placed inside the layout above when using HTML or multipart.', 'wp-dual-check' ) . '</p>';
			},
			$email_template_page
		);
		add_settings_field( 'email_body_html', __( 'HTML body', 'wp-dual-check' ), array( SettingsPage::class, 'field_email_body_html' ), $email_template_page, 'wdc_email_bodies' );
		add_settings_field( 'email_body_text', __( 'Plain-text body', 'wp-dual-check' ), array( SettingsPage::class, 'field_email_body_text' ), $email_template_page, 'wdc_email_bodies' );
	}
}
