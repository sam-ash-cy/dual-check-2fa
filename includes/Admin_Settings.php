<?php
/**
 * Admin: General on the main item; HTTP API / Gmail credentials live under Mail Transport Providers (tabbed).
 *
 * @package WPDualCheck
 */

namespace WPDualCheck;

final class Admin_Settings {

	public const OPTION_KEY   = 'wp_dual_check_settings';
	public const PAGE_SLUG    = 'wp-dual-check';
	public const PAGE_EMAIL_TEMPLATE = 'wp-dual-check-email-template';
	public const PAGE_MAIL_PROVIDERS  = 'wp-dual-check-mail-providers';
	public const OPTION_GROUP = 'wp_dual_check_settings_group';

	/**
	 * Tab slug => settings section id (wdc_*).
	 *
	 * @var array<string, string>
	 */
	private const MAIL_PROVIDER_TABS = array(
		'sendgrid' => 'wdc_sendgrid',
		'mailgun'  => 'wdc_mailgun',
		'ses'      => 'wdc_ses',
		'postmark' => 'wdc_postmark',
		'gmail'    => 'wdc_gmail',
	);

	public const DEFAULTS = array(
		'require_all_logins'       => false,
		'code_ttl'                 => 600,
		'code_length'              => 6,
		'max_attempts'             => 5,
		'from_email'               => '',
		'from_name'                => '',
		'default_mailer_transport' => 'dsn',
		'resend_cooldown'          => 60,
		'debug_logging'            => false,
		'api_sendgrid_key'         => '',
		'api_mailgun_key'          => '',
		'api_mailgun_domain'       => '',
		'api_mailgun_region'       => 'us',
		'api_ses_access_key'       => '',
		'api_ses_secret_key'       => '',
		'api_ses_region'           => 'us-east-1',
		'api_postmark_token'       => '',
		'api_gmail_user'           => '',
		'api_gmail_app_password'   => '',
		'email_subject_template'   => 'Your login code for {site_name}',
		'email_format'             => Email_Template::FORMAT_MULTIPART,
		'email_accent_color'       => '#2271b1',
		'email_background_color'   => '#f0f0f1',
		'email_header_text'        => '',
		'email_header_image_url'   => '',
		'email_header_width_px'    => 600,
		'email_footer_image_url'   => '',
		'email_footer_width_px'    => 600,
		'email_container_max_width_px' => 600,
		'email_footer_text'        => '',
		'email_body_html'          => '',
		'email_body_text'          => "Hello {user_name},\n\nYour login code is: {code}\n\nIt expires in about {expiry_minutes} minutes.\n\nIP: {ip_address}\nTime: {login_time}",
	);

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_email_template_assets' ) );
		add_action( 'admin_post_wp_dual_check_send_test_mail', array( self::class, 'handle_send_test_mail' ) );
	}

	public static function enqueue_email_template_assets( string $hook ): void {
		if ( 'wp-dual-check_page_' . self::PAGE_EMAIL_TEMPLATE !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style(
			'wdc-email-template',
			WP_DUAL_CHECK_URL . 'assets/admin-email-template.css',
			array(),
			WP_DUAL_CHECK_VERSION
		);
		wp_enqueue_script(
			'wdc-email-template',
			WP_DUAL_CHECK_URL . 'assets/admin-email-template.js',
			array( 'jquery', 'media-editor' ),
			WP_DUAL_CHECK_VERSION,
			true
		);
		wp_localize_script(
			'wdc-email-template',
			'wdcEmailTpl',
			array(
				'frameTitle'  => __( 'Choose image', 'wp-dual-check' ),
				'frameButton' => __( 'Use this image', 'wp-dual-check' ),
			)
		);
	}

	public static function add_menu(): void {
		add_menu_page(
			__( 'WP Dual Check', 'wp-dual-check' ),
			__( 'WP Dual Check', 'wp-dual-check' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render_page' ),
			'dashicons-shield',
			80
		);
		// Replace the auto “WP Dual Check” duplicate with a clear General entry.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'General', 'wp-dual-check' ),
			__( 'General', 'wp-dual-check' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render_page' )
		);
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Email template', 'wp-dual-check' ),
			__( 'Email template', 'wp-dual-check' ),
			'manage_options',
			self::PAGE_EMAIL_TEMPLATE,
			array( self::class, 'render_page_email_template' )
		);
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Mail Transport Providers', 'wp-dual-check' ),
			__( 'Mail Transport Providers', 'wp-dual-check' ),
			'manage_options',
			self::PAGE_MAIL_PROVIDERS,
			array( self::class, 'render_page_mail_providers' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::DEFAULTS,
			)
		);

		add_settings_section(
			'wdc_main',
			__( 'Email challenge', 'wp-dual-check' ),
			static function () {
				echo '<p>' . esc_html__( 'When enabled below, every successful password login goes to the email code step before access is granted (wp-login.php, wp-admin, and any flow using WordPress authentication).', 'wp-dual-check' ) . '</p>';
				echo '<p>' . esc_html__( 'Secrets for mail (DSN, API keys) belong in environment variables or wp-config.php when possible—not in these form fields.', 'wp-dual-check' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'wdc_require_all_logins',
			__( 'All users', 'wp-dual-check' ),
			array( self::class, 'field_require_all_logins' ),
			self::PAGE_SLUG,
			'wdc_main'
		);

		add_settings_field(
			'wdc_code_ttl',
			__( 'Code / session expiry (seconds)', 'wp-dual-check' ),
			array( self::class, 'field_code_ttl' ),
			self::PAGE_SLUG,
			'wdc_main'
		);
		add_settings_field(
			'wdc_code_length',
			__( 'Code length (digits)', 'wp-dual-check' ),
			array( self::class, 'field_code_length' ),
			self::PAGE_SLUG,
			'wdc_main'
		);
		add_settings_field(
			'wdc_max_attempts',
			__( 'Max wrong code attempts', 'wp-dual-check' ),
			array( self::class, 'field_max_attempts' ),
			self::PAGE_SLUG,
			'wdc_main'
		);
		add_settings_field(
			'wdc_resend_cooldown',
			__( 'Resend cooldown (seconds)', 'wp-dual-check' ),
			array( self::class, 'field_resend_cooldown' ),
			self::PAGE_SLUG,
			'wdc_main'
		);
		add_settings_field(
			'wdc_from_email',
			__( 'From email', 'wp-dual-check' ),
			array( self::class, 'field_from_email' ),
			self::PAGE_SLUG,
			'wdc_main'
		);
		add_settings_field(
			'wdc_from_name',
			__( 'From name', 'wp-dual-check' ),
			array( self::class, 'field_from_name' ),
			self::PAGE_SLUG,
			'wdc_main'
		);
		add_settings_field(
			'wdc_default_mailer_transport',
			__( 'Default mail transport', 'wp-dual-check' ),
			array( self::class, 'field_default_mailer_transport' ),
			self::PAGE_SLUG,
			'wdc_main'
		);

		self::register_provider_pages();
		self::register_email_template_page();

		add_settings_section(
			'wdc_diagnostics',
			__( 'Diagnostics', 'wp-dual-check' ),
			static function () {
				echo '<p>' . esc_html__( 'Enable debug logging for structured plugin lines in the PHP error log. Use the test email form below to verify your DSN without signing in.', 'wp-dual-check' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'wdc_debug_logging',
			__( 'Debug logging', 'wp-dual-check' ),
			array( self::class, 'field_debug_logging' ),
			self::PAGE_SLUG,
			'wdc_diagnostics'
		);

		add_settings_section(
			'wdc_secrets',
			__( 'Secrets (environment only)', 'wp-dual-check' ),
			static function () {
				$dsn_ok = '' !== Config::mailer_dsn();
				echo '<p>';
				if ( $dsn_ok ) {
					esc_html_e( 'Mailer DSN is set in the environment.', 'wp-dual-check' );
				} else {
					echo '<strong>' . esc_html__( 'Mailer DSN is not set.', 'wp-dual-check' ) . '</strong> ';
					esc_html_e( 'Set WP_DUAL_CHECK_MAILER_DSN (or legacy WP2FA_MAILER_DSN) in the server environment or wp-config.php.', 'wp-dual-check' );
				}
				echo '</p>';
				echo '<p>' . esc_html__( 'Optional: WP_DUAL_CHECK_SECRET (or legacy WP2FA_SECRET) for a stable HMAC key across multiple servers.', 'wp-dual-check' ) . '</p>';
				echo '<p>' . esc_html__( 'API provider keys can be set via constants/environment (see README); those override the values saved under Mail Transport Providers.', 'wp-dual-check' ) . '</p>';
			},
			self::PAGE_SLUG
		);
	}

	/**
	 * Tabbed provider credentials (single admin submenu).
	 */
	private static function register_provider_pages(): void {
		$intro = static function (): void {
			echo '<p>' . esc_html__( 'Prefer environment variables or wp-config.php on production. Values here are stored in the database. Leave a secret field blank when saving to keep the previous value.', 'wp-dual-check' ) . '</p>';
		};

		$page = self::PAGE_MAIL_PROVIDERS;

		add_settings_section( 'wdc_sendgrid', '', $intro, $page );
		add_settings_field( 'wdc_api_sendgrid', __( 'Credentials', 'wp-dual-check' ), array( self::class, 'field_api_sendgrid' ), $page, 'wdc_sendgrid' );

		add_settings_section( 'wdc_mailgun', '', $intro, $page );
		add_settings_field( 'wdc_api_mailgun', __( 'Credentials', 'wp-dual-check' ), array( self::class, 'field_api_mailgun' ), $page, 'wdc_mailgun' );

		add_settings_section( 'wdc_ses', '', $intro, $page );
		add_settings_field( 'wdc_api_ses', __( 'Credentials', 'wp-dual-check' ), array( self::class, 'field_api_ses' ), $page, 'wdc_ses' );

		add_settings_section( 'wdc_postmark', '', $intro, $page );
		add_settings_field( 'wdc_api_postmark', __( 'Credentials', 'wp-dual-check' ), array( self::class, 'field_api_postmark' ), $page, 'wdc_postmark' );

		add_settings_section( 'wdc_gmail', '', $intro, $page );
		add_settings_field( 'wdc_api_gmail', __( 'Credentials', 'wp-dual-check' ), array( self::class, 'field_api_gmail' ), $page, 'wdc_gmail' );
	}

	private static function register_email_template_page(): void {
		$p = self::PAGE_EMAIL_TEMPLATE;

		add_settings_section(
			'wdc_email_subject',
			__( 'Subject & format', 'wp-dual-check' ),
			static function (): void {
				echo '<p>' . esc_html__( 'The subject is sent as plain text (any HTML is removed).', 'wp-dual-check' ) . '</p>';
				echo '<p><strong>' . esc_html__( 'Placeholders', 'wp-dual-check' ) . '</strong> — '
					. esc_html__( 'use in the subject, plain-text body, and HTML body:', 'wp-dual-check' ) . '</p>';
				echo '<ul style="list-style:disc;padding-left:1.25em;">';
				$tags = array( '{site_name}', '{user_name}', '{code}', '{ip_address}', '{login_time}', '{expiry_minutes}' );
				foreach ( $tags as $t ) {
					echo '<li><code>' . esc_html( $t ) . '</code></li>';
				}
				echo '</ul>';
				echo '<p class="description">' . esc_html__( '{expiry_minutes} is the code lifetime in whole minutes. Leave the HTML body empty to use the plugin’s built-in styled layout for the code.', 'wp-dual-check' ) . '</p>';
			},
			$p
		);
		add_settings_field( 'email_subject_template', __( 'Subject line', 'wp-dual-check' ), array( self::class, 'field_email_subject_template' ), $p, 'wdc_email_subject' );
		add_settings_field( 'email_format', __( 'Email format', 'wp-dual-check' ), array( self::class, 'field_email_format' ), $p, 'wdc_email_subject' );

		add_settings_section(
			'wdc_email_look',
			__( 'HTML layout', 'wp-dual-check' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Colours and images wrap the HTML template. Widths are in pixels (200–920).', 'wp-dual-check' ) . '</p>';
			},
			$p
		);
		add_settings_field( 'email_accent_color', __( 'Accent colour', 'wp-dual-check' ), array( self::class, 'field_email_accent_color' ), $p, 'wdc_email_look' );
		add_settings_field( 'email_background_color', __( 'Outer background colour', 'wp-dual-check' ), array( self::class, 'field_email_background_color' ), $p, 'wdc_email_look' );
		add_settings_field( 'email_container_max_width_px', __( 'Email content max width', 'wp-dual-check' ), array( self::class, 'field_email_container_max_width_px' ), $p, 'wdc_email_look' );
		add_settings_field( 'email_header_text', __( 'Header bar (HTML)', 'wp-dual-check' ), array( self::class, 'field_email_header_text' ), $p, 'wdc_email_look' );
		add_settings_field( 'email_header_image_url', __( 'Header image URL', 'wp-dual-check' ), array( self::class, 'field_email_header_image_url' ), $p, 'wdc_email_look' );
		add_settings_field( 'email_header_width_px', __( 'Header image width', 'wp-dual-check' ), array( self::class, 'field_email_header_width_px' ), $p, 'wdc_email_look' );
		add_settings_field( 'email_footer_image_url', __( 'Footer image URL', 'wp-dual-check' ), array( self::class, 'field_email_footer_image_url' ), $p, 'wdc_email_look' );
		add_settings_field( 'email_footer_width_px', __( 'Footer image width', 'wp-dual-check' ), array( self::class, 'field_email_footer_width_px' ), $p, 'wdc_email_look' );
		add_settings_field( 'email_footer_text', __( 'Footer (HTML)', 'wp-dual-check' ), array( self::class, 'field_email_footer_text' ), $p, 'wdc_email_look' );

		add_settings_section(
			'wdc_email_bodies',
			__( 'Message templates', 'wp-dual-check' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Plain text is always used for the plain-text part (and required for multipart). HTML is placed inside the layout above when using HTML or multipart.', 'wp-dual-check' ) . '</p>';
			},
			$p
		);
		add_settings_field( 'email_body_html', __( 'HTML body', 'wp-dual-check' ), array( self::class, 'field_email_body_html' ), $p, 'wdc_email_bodies' );
		add_settings_field( 'email_body_text', __( 'Plain-text body', 'wp-dual-check' ), array( self::class, 'field_email_body_text' ), $p, 'wdc_email_bodies' );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$prev  = self::merged();
		$input = is_array( $input ) ? $input : array();

		$transport = isset( $input['default_mailer_transport'] ) ? (string) $input['default_mailer_transport'] : (string) $prev['default_mailer_transport'];

		$mg_reg = isset( $input['api_mailgun_region'] ) ? strtolower( (string) $input['api_mailgun_region'] ) : (string) $prev['api_mailgun_region'];
		$mg_reg = 'eu' === $mg_reg ? 'eu' : 'us';

		return array(
			'require_all_logins'         => array_key_exists( 'require_all_logins', $input ) ? ( '1' === (string) $input['require_all_logins'] ) : (bool) $prev['require_all_logins'],
			'code_ttl'                   => isset( $input['code_ttl'] ) ? max( 60, min( 86400, (int) $input['code_ttl'] ) ) : (int) $prev['code_ttl'],
			'code_length'                => isset( $input['code_length'] ) ? max( 4, min( 12, (int) $input['code_length'] ) ) : (int) ( $prev['code_length'] ?? 6 ),
			'max_attempts'               => isset( $input['max_attempts'] ) ? max( 1, min( 50, (int) $input['max_attempts'] ) ) : (int) $prev['max_attempts'],
			'resend_cooldown'            => isset( $input['resend_cooldown'] ) ? max( 15, min( 600, (int) $input['resend_cooldown'] ) ) : (int) $prev['resend_cooldown'],
			'from_email'                 => isset( $input['from_email'] ) ? sanitize_email( (string) $input['from_email'] ) : (string) $prev['from_email'],
			'from_name'                  => isset( $input['from_name'] ) ? sanitize_text_field( (string) $input['from_name'] ) : (string) $prev['from_name'],
			'default_mailer_transport'   => Mailer::sanitize_transport_id( $transport, Mailer::TRANSPORT_DSN ),
			'debug_logging'              => array_key_exists( 'debug_logging', $input ) ? ( '1' === (string) $input['debug_logging'] ) : (bool) $prev['debug_logging'],
			'email_subject_template'     => isset( $input['email_subject_template'] ) ? sanitize_text_field( (string) $input['email_subject_template'] ) : (string) $prev['email_subject_template'],
			'email_format'               => isset( $input['email_format'] ) ? self::sanitize_email_format( (string) $input['email_format'] ) : (string) $prev['email_format'],
			'email_accent_color'         => isset( $input['email_accent_color'] ) ? self::sanitize_hex_color_field( (string) $input['email_accent_color'], (string) $prev['email_accent_color'] ) : (string) $prev['email_accent_color'],
			'email_background_color'     => isset( $input['email_background_color'] ) ? self::sanitize_hex_color_field( (string) $input['email_background_color'], (string) $prev['email_background_color'] ) : (string) $prev['email_background_color'],
			'email_header_text'          => isset( $input['email_header_text'] ) ? wp_kses_post( (string) $input['email_header_text'] ) : (string) $prev['email_header_text'],
			'email_header_image_url'     => isset( $input['email_header_image_url'] ) ? esc_url_raw( (string) $input['email_header_image_url'] ) : (string) $prev['email_header_image_url'],
			'email_header_width_px'      => isset( $input['email_header_width_px'] ) ? max( 200, min( 920, (int) $input['email_header_width_px'] ) ) : (int) $prev['email_header_width_px'],
			'email_footer_image_url'     => isset( $input['email_footer_image_url'] ) ? esc_url_raw( (string) $input['email_footer_image_url'] ) : (string) $prev['email_footer_image_url'],
			'email_footer_width_px'      => isset( $input['email_footer_width_px'] ) ? max( 200, min( 920, (int) $input['email_footer_width_px'] ) ) : (int) $prev['email_footer_width_px'],
			'email_container_max_width_px' => isset( $input['email_container_max_width_px'] ) ? max( 200, min( 920, (int) $input['email_container_max_width_px'] ) ) : (int) $prev['email_container_max_width_px'],
			'email_footer_text'          => isset( $input['email_footer_text'] ) ? wp_kses_post( (string) $input['email_footer_text'] ) : (string) $prev['email_footer_text'],
			'email_body_html'            => isset( $input['email_body_html'] ) ? wp_kses_post( (string) $input['email_body_html'] ) : (string) $prev['email_body_html'],
			'email_body_text'            => isset( $input['email_body_text'] ) ? sanitize_textarea_field( (string) $input['email_body_text'] ) : (string) $prev['email_body_text'],
			'api_sendgrid_key'           => self::sanitize_secret_field( $input, 'api_sendgrid_key', $prev ),
			'api_mailgun_key'            => self::sanitize_secret_field( $input, 'api_mailgun_key', $prev ),
			'api_mailgun_domain'         => isset( $input['api_mailgun_domain'] ) ? sanitize_text_field( (string) $input['api_mailgun_domain'] ) : (string) $prev['api_mailgun_domain'],
			'api_mailgun_region'         => $mg_reg,
			'api_ses_access_key'         => self::sanitize_secret_field( $input, 'api_ses_access_key', $prev ),
			'api_ses_secret_key'         => self::sanitize_secret_field( $input, 'api_ses_secret_key', $prev ),
			'api_ses_region'             => self::sanitize_ses_region( $input, $prev ),
			'api_postmark_token'         => self::sanitize_secret_field( $input, 'api_postmark_token', $prev ),
			'api_gmail_user'             => isset( $input['api_gmail_user'] ) ? sanitize_email( (string) $input['api_gmail_user'] ) : (string) $prev['api_gmail_user'],
			'api_gmail_app_password'     => self::sanitize_secret_field( $input, 'api_gmail_app_password', $prev ),
		);
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $prev
	 */
	private static function sanitize_ses_region( array $input, array $prev ): string {
		$r = isset( $input['api_ses_region'] ) ? preg_replace( '/[^a-z0-9\-]/i', '', (string) $input['api_ses_region'] ) : (string) ( $prev['api_ses_region'] ?? 'us-east-1' );
		if ( '' === $r ) {
			$r = (string) ( $prev['api_ses_region'] ?? 'us-east-1' );
		}

		return '' !== $r ? $r : 'us-east-1';
	}

	private static function sanitize_secret_field( array $input, string $key, array $prev ): string {
		if ( ! isset( $input[ $key ] ) ) {
			return isset( $prev[ $key ] ) ? (string) $prev[ $key ] : '';
		}
		$v = trim( (string) $input[ $key ] );
		if ( '' === $v ) {
			return isset( $prev[ $key ] ) ? (string) $prev[ $key ] : '';
		}

		return $v;
	}

	private static function sanitize_email_format( string $value ): string {
		$value = sanitize_key( $value );
		$ok    = array( Email_Template::FORMAT_TEXT, Email_Template::FORMAT_HTML, Email_Template::FORMAT_MULTIPART );

		return in_array( $value, $ok, true ) ? $value : Email_Template::FORMAT_MULTIPART;
	}

	private static function sanitize_hex_color_field( string $value, string $fallback ): string {
		$c = sanitize_hex_color( trim( $value ) );
		if ( '' !== $c ) {
			return $c;
		}
		$f = sanitize_hex_color( trim( $fallback ) );

		return '' !== $f ? $f : '#2271b1';
	}

	/**
	 * Normalize stored hex for HTML5 color input (#rrggbb only).
	 */
	private static function hex_for_color_input( string $hex, string $fallback ): string {
		$c = sanitize_hex_color( trim( $hex ) );
		if ( '' === $c ) {
			$c = sanitize_hex_color( trim( $fallback ) );
		}
		if ( '' === $c ) {
			$c = '#2271b1';
		}
		if ( 4 === strlen( $c ) && '#' === $c[0] ) {
			$c = '#' . $c[1] . $c[1] . $c[2] . $c[2] . $c[3] . $c[3];
		}

		return $c;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function merged(): array {
		$stored = get_option( self::OPTION_KEY );
		if ( is_array( $stored ) && $stored !== array() ) {
			$out = array_merge( self::DEFAULTS, $stored );
		} else {
			$legacy = get_option( 'wp2fa_settings', array() );
			if ( is_array( $legacy ) && $legacy !== array() ) {
				$out = array_merge( self::DEFAULTS, $legacy );
			} else {
				$out = array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );
			}
		}
		unset( $out['rest_enabled'], $out['api_ops_notes'] );
		if ( ! isset( $out['default_mailer_transport'] ) || ! is_string( $out['default_mailer_transport'] ) ) {
			$out['default_mailer_transport'] = self::DEFAULTS['default_mailer_transport'];
		} else {
			$out['default_mailer_transport'] = Mailer::sanitize_transport_id( $out['default_mailer_transport'], Mailer::TRANSPORT_DSN );
		}
		foreach ( self::DEFAULTS as $k => $v ) {
			if ( ! array_key_exists( $k, $out ) ) {
				$out[ $k ] = $v;
			}
		}

		return $out;
	}

	private static function print_settings_saved_notice(): void {
		if ( isset( $_GET['settings-updated'] ) && 'true' === sanitize_text_field( wp_unslash( (string) $_GET['settings-updated'] ) ) ) {
			echo '<div id="wdc-settings-saved" class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wp-dual-check' ) . '</p></div>';
		}
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$uid  = get_current_user_id();
		$flash = $uid ? get_transient( 'wdc_test_mail_flash_' . $uid ) : false;
		if ( is_array( $flash ) ) {
			delete_transient( 'wdc_test_mail_flash_' . $uid );
			$ok = ! empty( $flash['success'] );
			echo $ok
				? '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Test email sent. Check the inbox for your account (or delivery override).', 'wp-dual-check' ) . '</p></div>'
				: '<div class="notice notice-error is-dismissible"><p>' . esc_html( isset( $flash['message'] ) ? (string) $flash['message'] : __( 'Could not send test email.', 'wp-dual-check' ) ) . '</p></div>';
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php self::print_settings_saved_notice(); ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Test email', 'wp-dual-check' ); ?></h2>
			<p><?php esc_html_e( 'Send a test message using your account’s selected mail transport and From settings. No login code is included.', 'wp-dual-check' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wp_dual_check_test_mail' ); ?>
				<input type="hidden" name="action" value="wp_dual_check_send_test_mail" />
				<?php
				submit_button( __( 'Send test email to me', 'wp-dual-check' ), 'secondary', 'submit', false );
				?>
			</form>
			<p class="description"><?php esc_html_e( 'Uses your user profile delivery email if set; otherwise your account email. Login code email filters are not applied.', 'wp-dual-check' ); ?></p>
		</div>
		<?php
	}

	public static function render_page_email_template(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php self::print_settings_saved_notice(); ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_EMAIL_TEMPLATE );
				submit_button();
				?>
			</form>
			<p class="description"><?php esc_html_e( 'Code length is configured on the General screen. Filters wp_dual_check_email_subject and wp_dual_check_email_body still run after templates are built.', 'wp-dual-check' ); ?></p>
		</div>
		<?php
	}

	public static function render_page_mail_providers(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : '';
		if ( '' === $tab || ! isset( self::MAIL_PROVIDER_TABS[ $tab ] ) ) {
			$tab = 'sendgrid';
		}
		$section_id = self::MAIL_PROVIDER_TABS[ $tab ];

		$tab_labels = array(
			'sendgrid' => __( 'SendGrid', 'wp-dual-check' ),
			'mailgun'  => __( 'Mailgun', 'wp-dual-check' ),
			'ses'      => __( 'Amazon SES', 'wp-dual-check' ),
			'postmark' => __( 'Postmark', 'wp-dual-check' ),
			'gmail'    => __( 'Gmail (SMTP)', 'wp-dual-check' ),
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php self::print_settings_saved_notice(); ?>

			<h2 class="nav-tab-wrapper wp-clearfix">
				<?php
				foreach ( $tab_labels as $tab_slug => $label ) {
					$url   = add_query_arg(
						array(
							'page' => self::PAGE_MAIL_PROVIDERS,
							'tab'  => $tab_slug,
						),
						admin_url( 'admin.php' )
					);
					$class = 'nav-tab' . ( $tab === $tab_slug ? ' nav-tab-active' : '' );
					echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
				}
				?>
			</h2>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				self::render_single_settings_section( self::PAGE_MAIL_PROVIDERS, $section_id );
				submit_button();
				?>
			</form>
			<p class="description"><?php esc_html_e( 'The default mail transport is chosen on WP Dual Check → General.', 'wp-dual-check' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Output one section (callback + form table) for a tabbed options page.
	 */
	private static function render_single_settings_section( string $page, string $section_id ): void {
		global $wp_settings_sections, $wp_settings_fields;

		if ( empty( $wp_settings_sections[ $page ][ $section_id ] ) ) {
			return;
		}

		$section = $wp_settings_sections[ $page ][ $section_id ];
		if ( ! empty( $section['title'] ) ) {
			echo '<h2>' . esc_html( $section['title'] ) . '</h2>' . "\n";
		}
		if ( ! empty( $section['callback'] ) && is_callable( $section['callback'] ) ) {
			call_user_func( $section['callback'], $section );
		}

		if ( empty( $wp_settings_fields[ $page ][ $section_id ] ) ) {
			return;
		}

		echo '<table class="form-table" role="presentation">';
		do_settings_fields( $page, $section_id );
		echo '</table>';
	}

	public static function field_require_all_logins(): void {
		$on = ! empty( self::merged()['require_all_logins'] );
		echo '<input type="hidden" name="' . esc_attr( self::OPTION_KEY ) . '[require_all_logins]" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[require_all_logins]" value="1" ' . checked( $on, true, false ) . ' /> ';
		esc_html_e( 'Require email verification code for every user after a correct password (all logins).', 'wp-dual-check' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'Applies to administrators, editors, subscribers—anyone authenticating through WordPress.', 'wp-dual-check' ) . '</p>';
	}

	public static function field_code_ttl(): void {
		$v = (int) self::merged()['code_ttl'];
		echo '<input type="number" min="60" max="86400" class="small-text" name="' . esc_attr( self::OPTION_KEY ) . '[code_ttl]" value="' . esc_attr( (string) $v ) . '" />';
		echo '<p class="description">' . esc_html__( 'How long the pending login and code remain valid.', 'wp-dual-check' ) . '</p>';
	}

	public static function field_code_length(): void {
		$v = (int) self::merged()['code_length'];
		echo '<input type="number" min="4" max="12" class="small-text" name="' . esc_attr( self::OPTION_KEY ) . '[code_length]" value="' . esc_attr( (string) $v ) . '" />';
		echo '<p class="description">' . esc_html__( 'Number of digits in the login code (e.g. 6).', 'wp-dual-check' ) . '</p>';
	}

	public static function field_max_attempts(): void {
		$v = (int) self::merged()['max_attempts'];
		echo '<input type="number" min="1" max="50" class="small-text" name="' . esc_attr( self::OPTION_KEY ) . '[max_attempts]" value="' . esc_attr( (string) $v ) . '" />';
	}

	public static function field_resend_cooldown(): void {
		$v = (int) self::merged()['resend_cooldown'];
		echo '<input type="number" min="15" max="600" class="small-text" name="' . esc_attr( self::OPTION_KEY ) . '[resend_cooldown]" value="' . esc_attr( (string) $v ) . '" />';
		echo '<p class="description">' . esc_html__( 'Minimum time between “Resend code” actions per user.', 'wp-dual-check' ) . '</p>';
	}

	public static function field_from_email(): void {
		$v = (string) self::merged()['from_email'];
		echo '<input type="email" class="regular-text" name="' . esc_attr( self::OPTION_KEY ) . '[from_email]" value="' . esc_attr( $v ) . '" />';
		echo '<p class="description">' . esc_html__( 'Leave blank to use the site admin email.', 'wp-dual-check' ) . '</p>';
	}

	public static function field_from_name(): void {
		$v = (string) self::merged()['from_name'];
		echo '<input type="text" class="regular-text" name="' . esc_attr( self::OPTION_KEY ) . '[from_name]" value="' . esc_attr( $v ) . '" />';
		echo '<p class="description">' . esc_html__( 'Leave blank to use the site title.', 'wp-dual-check' ) . '</p>';
	}

	public static function field_default_mailer_transport(): void {
		$current = (string) self::merged()['default_mailer_transport'];
		$name    = self::OPTION_KEY . '[default_mailer_transport]';
		echo '<select id="wdc_default_mailer_transport" name="' . esc_attr( $name ) . '">';
		foreach ( Mailer::transport_choices() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Used for all users unless overridden on their profile. Configure API and Gmail SMTP credentials under Mail Transport Providers.', 'wp-dual-check' ) . '</p>';
	}

	public static function field_api_sendgrid(): void {
		$key = (string) self::merged()['api_sendgrid_key'];
		$ph  = '' !== $key ? __( '•••••••• (leave blank to keep)', 'wp-dual-check' ) : '';
		echo '<p><label for="wdc_sg_key">' . esc_html__( 'API key', 'wp-dual-check' ) . '</label><br />';
		echo '<input type="password" class="regular-text" name="' . esc_attr( self::OPTION_KEY ) . '[api_sendgrid_key]" id="wdc_sg_key" value="" autocomplete="new-password" placeholder="' . esc_attr( $ph ) . '" /></p>';
		echo '<p class="description">' . esc_html__( 'Env: WP_DUAL_CHECK_SENDGRID_API_KEY', 'wp-dual-check' ) . '</p>';
	}

	public static function field_api_mailgun(): void {
		$m    = self::merged();
		$key  = (string) $m['api_mailgun_key'];
		$dom  = (string) $m['api_mailgun_domain'];
		$reg  = (string) $m['api_mailgun_region'];
		$ph   = '' !== $key ? __( '•••••••• (leave blank to keep)', 'wp-dual-check' ) : '';
		echo '<p><label for="wdc_mg_key">' . esc_html__( 'API key', 'wp-dual-check' ) . '</label><br />';
		echo '<input type="password" class="regular-text" name="' . esc_attr( self::OPTION_KEY ) . '[api_mailgun_key]" id="wdc_mg_key" value="" autocomplete="new-password" placeholder="' . esc_attr( $ph ) . '" /></p>';
		echo '<p><label for="wdc_mg_domain">' . esc_html__( 'Sending domain', 'wp-dual-check' ) . '</label><br />';
		echo '<input type="text" class="regular-text" name="' . esc_attr( self::OPTION_KEY ) . '[api_mailgun_domain]" id="wdc_mg_domain" value="' . esc_attr( $dom ) . '" /></p>';
		echo '<p><label for="wdc_mg_reg">' . esc_html__( 'Region', 'wp-dual-check' ) . '</label><br />';
		echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[api_mailgun_region]" id="wdc_mg_reg">';
		echo '<option value="us" ' . selected( $reg, 'us', false ) . '>US</option>';
		echo '<option value="eu" ' . selected( $reg, 'eu', false ) . '>EU</option>';
		echo '</select></p>';
		echo '<p class="description">' . esc_html__( 'Env: WP_DUAL_CHECK_MAILGUN_API_KEY, MAILGUN_DOMAIN, MAILGUN_REGION (us|eu).', 'wp-dual-check' ) . '</p>';
	}

	public static function field_api_ses(): void {
		$m      = self::merged();
		$access = (string) $m['api_ses_access_key'];
		$ph_a   = '' !== $access ? __( '•••••••• (leave blank to keep)', 'wp-dual-check' ) : '';
		$secret = (string) $m['api_ses_secret_key'];
		$ph_s   = '' !== $secret ? __( '•••••••• (leave blank to keep)', 'wp-dual-check' ) : '';
		echo '<p><label for="wdc_ses_a">' . esc_html__( 'Access key ID', 'wp-dual-check' ) . '</label><br />';
		echo '<input type="password" class="regular-text" name="' . esc_attr( self::OPTION_KEY ) . '[api_ses_access_key]" id="wdc_ses_a" value="" autocomplete="new-password" placeholder="' . esc_attr( $ph_a ) . '" /></p>';
		echo '<p><label for="wdc_ses_s">' . esc_html__( 'Secret access key', 'wp-dual-check' ) . '</label><br />';
		echo '<input type="password" class="regular-text" name="' . esc_attr( self::OPTION_KEY ) . '[api_ses_secret_key]" id="wdc_ses_s" value="" autocomplete="new-password" placeholder="' . esc_attr( $ph_s ) . '" /></p>';
		echo '<p><label for="wdc_ses_r">' . esc_html__( 'AWS region', 'wp-dual-check' ) . '</label><br />';
		echo '<input type="text" class="regular-text" name="' . esc_attr( self::OPTION_KEY ) . '[api_ses_region]" id="wdc_ses_r" value="' . esc_attr( (string) $m['api_ses_region'] ) . '" /></p>';
		echo '<p class="description">' . esc_html__( 'Env: WP_DUAL_CHECK_SES_ACCESS_KEY, SES_SECRET_KEY, SES_REGION.', 'wp-dual-check' ) . '</p>';
	}

	public static function field_api_postmark(): void {
		$key = (string) self::merged()['api_postmark_token'];
		$ph  = '' !== $key ? __( '•••••••• (leave blank to keep)', 'wp-dual-check' ) : '';
		echo '<p><label for="wdc_pm_t">' . esc_html__( 'Server API token', 'wp-dual-check' ) . '</label><br />';
		echo '<input type="password" class="regular-text" name="' . esc_attr( self::OPTION_KEY ) . '[api_postmark_token]" id="wdc_pm_t" value="" autocomplete="new-password" placeholder="' . esc_attr( $ph ) . '" /></p>';
		echo '<p class="description">' . esc_html__( 'Env: WP_DUAL_CHECK_POSTMARK_TOKEN', 'wp-dual-check' ) . '</p>';
	}

	public static function field_api_gmail(): void {
		$m    = self::merged();
		$user = (string) $m['api_gmail_user'];
		$pass = (string) $m['api_gmail_app_password'];
		$ph   = '' !== $pass ? __( '•••••••• (leave blank to keep)', 'wp-dual-check' ) : '';
		echo '<p><strong>' . esc_html__( 'Google SMTP + app password (not OAuth2 API)', 'wp-dual-check' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Symfony’s Gmail bridge uses smtp.gmail.com with a 16-character app password. Full Gmail OAuth2 is not included; use wp_mail with a Google plugin or wp_dual_check_symfony_dsn to inject a custom transport.', 'wp-dual-check' ) . '</p>';
		echo '<p><label for="wdc_gm_u">' . esc_html__( 'Gmail address', 'wp-dual-check' ) . '</label><br />';
		echo '<input type="email" class="regular-text" name="' . esc_attr( self::OPTION_KEY ) . '[api_gmail_user]" id="wdc_gm_u" value="' . esc_attr( $user ) . '" /></p>';
		echo '<p><label for="wdc_gm_p">' . esc_html__( 'App password', 'wp-dual-check' ) . '</label><br />';
		echo '<input type="password" class="regular-text" name="' . esc_attr( self::OPTION_KEY ) . '[api_gmail_app_password]" id="wdc_gm_p" value="" autocomplete="new-password" placeholder="' . esc_attr( $ph ) . '" /></p>';
		echo '<p class="description">' . esc_html__( 'Env: WP_DUAL_CHECK_GMAIL_ADDRESS, GMAIL_APP_PASSWORD', 'wp-dual-check' ) . '</p>';
	}

	public static function field_email_subject_template(): void {
		$v = (string) self::merged()['email_subject_template'];
		echo '<input type="text" class="large-text wdc-wide-field" name="' . esc_attr( self::OPTION_KEY ) . '[email_subject_template]" value="' . esc_attr( $v ) . '" autocomplete="off" />';
	}

	public static function field_email_format(): void {
		$m = self::merged();
		$c = isset( $m['email_format'] ) ? (string) $m['email_format'] : Email_Template::FORMAT_MULTIPART;
		$name = self::OPTION_KEY . '[email_format]';
		echo '<select id="wdc_email_format" name="' . esc_attr( $name ) . '">';
		echo '<option value="' . esc_attr( Email_Template::FORMAT_MULTIPART ) . '" ' . selected( $c, Email_Template::FORMAT_MULTIPART, false ) . '>' . esc_html__( 'HTML + plain text (multipart)', 'wp-dual-check' ) . '</option>';
		echo '<option value="' . esc_attr( Email_Template::FORMAT_HTML ) . '" ' . selected( $c, Email_Template::FORMAT_HTML, false ) . '>' . esc_html__( 'HTML only', 'wp-dual-check' ) . '</option>';
		echo '<option value="' . esc_attr( Email_Template::FORMAT_TEXT ) . '" ' . selected( $c, Email_Template::FORMAT_TEXT, false ) . '>' . esc_html__( 'Plain text only', 'wp-dual-check' ) . '</option>';
		echo '</select>';
	}

	public static function field_email_accent_color(): void {
		$v       = (string) self::merged()['email_accent_color'];
		$picker  = self::hex_for_color_input( $v, '#2271b1' );
		$id_pick = 'wdc_email_accent_color_pick';
		$id_text = 'wdc_email_accent_color_text';
		echo '<span class="wdc-color-sync" data-wdc-color-sync>';
		echo '<input type="color" id="' . esc_attr( $id_pick ) . '" class="wdc-color-field" value="' . esc_attr( $picker ) . '" title="' . esc_attr__( 'Choose accent colour', 'wp-dual-check' ) . '" /> ';
		echo '<input type="text" id="' . esc_attr( $id_text ) . '" class="wdc-color-hex-input" name="' . esc_attr( self::OPTION_KEY ) . '[email_accent_color]" value="' . esc_attr( $v ) . '" pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$" maxlength="7" spellcheck="false" />';
		echo '</span>';
		echo '<p class="description">' . esc_html__( 'Used for the optional header bar behind “Header bar (HTML)”. Adjust with the picker or type a hex value.', 'wp-dual-check' ) . '</p>';
	}

	public static function field_email_background_color(): void {
		$v       = (string) self::merged()['email_background_color'];
		$picker  = self::hex_for_color_input( $v, '#f0f0f1' );
		$id_pick = 'wdc_email_background_color_pick';
		$id_text = 'wdc_email_background_color_text';
		echo '<span class="wdc-color-sync" data-wdc-color-sync>';
		echo '<input type="color" id="' . esc_attr( $id_pick ) . '" class="wdc-color-field" value="' . esc_attr( $picker ) . '" title="' . esc_attr__( 'Choose outer background colour', 'wp-dual-check' ) . '" /> ';
		echo '<input type="text" id="' . esc_attr( $id_text ) . '" class="wdc-color-hex-input" name="' . esc_attr( self::OPTION_KEY ) . '[email_background_color]" value="' . esc_attr( $v ) . '" pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$" maxlength="7" spellcheck="false" />';
		echo '</span>';
	}

	public static function field_email_container_max_width_px(): void {
		$v = (int) self::merged()['email_container_max_width_px'];
		echo '<input type="number" min="200" max="920" class="small-text" name="' . esc_attr( self::OPTION_KEY ) . '[email_container_max_width_px]" value="' . esc_attr( (string) $v ) . '" /> px';
	}

	public static function field_email_header_text(): void {
		$v = (string) self::merged()['email_header_text'];
		echo '<textarea class="large-text wdc-wide-field" rows="4" name="' . esc_attr( self::OPTION_KEY ) . '[email_header_text]">' . esc_textarea( $v ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Shown on a bar using the accent colour (above the main content).', 'wp-dual-check' ) . '</p>';
	}

	public static function field_email_header_image_url(): void {
		self::render_email_image_url_field(
			'email_header_image_url',
			'wdc_email_header_image',
			__( 'Paste an image URL or open the Media Library. Use a publicly reachable address (HTTPS recommended).', 'wp-dual-check' )
		);
	}

	public static function field_email_header_width_px(): void {
		$v = (int) self::merged()['email_header_width_px'];
		echo '<input type="number" min="200" max="920" class="small-text" name="' . esc_attr( self::OPTION_KEY ) . '[email_header_width_px]" value="' . esc_attr( (string) $v ) . '" /> px';
	}

	public static function field_email_footer_image_url(): void {
		self::render_email_image_url_field(
			'email_footer_image_url',
			'wdc_email_footer_image',
			__( 'Paste an image URL or open the Media Library. Use a publicly reachable address (HTTPS recommended).', 'wp-dual-check' )
		);
	}

	/**
	 * URL field + Media Library picker + preview (login email template).
	 */
	private static function render_email_image_url_field( string $settings_key, string $id_prefix, string $description ): void {
		$v         = (string) self::merged()[ $settings_key ];
		$input_id  = $id_prefix . '_url';
		$name      = self::OPTION_KEY . '[' . $settings_key . ']';
		$has_image = '' !== $v;
		echo '<div class="wdc-media-url-field" data-wdc-media-url>';
		echo '<div class="wdc-media-url-row">';
		echo '<input type="url" id="' . esc_attr( $input_id ) . '" class="large-text wdc-wide-field wdc-media-url-input" name="' . esc_attr( $name ) . '" value="' . esc_attr( $v ) . '" placeholder="https://..." autocomplete="off" />';
		echo '<p class="wdc-media-url-actions">';
		echo '<button type="button" class="button wdc-media-select">' . esc_html__( 'Media Library…', 'wp-dual-check' ) . '</button> ';
		echo '<button type="button" class="button-link wdc-media-clear">' . esc_html__( 'Clear', 'wp-dual-check' ) . '</button>';
		echo '</p>';
		echo '</div>';
		echo '<div class="wdc-media-preview-wrap"' . ( $has_image ? '' : ' style="display:none;"' ) . '>';
		if ( $has_image ) {
			echo '<img src="' . esc_url( $v ) . '" alt="" class="wdc-media-preview-img" />';
		} else {
			echo '<img src="" alt="" class="wdc-media-preview-img" />';
		}
		echo '</div>';
		echo '</div>';
		echo '<p class="description">' . esc_html( $description ) . '</p>';
	}

	public static function field_email_footer_width_px(): void {
		$v = (int) self::merged()['email_footer_width_px'];
		echo '<input type="number" min="200" max="920" class="small-text" name="' . esc_attr( self::OPTION_KEY ) . '[email_footer_width_px]" value="' . esc_attr( (string) $v ) . '" /> px';
	}

	public static function field_email_footer_text(): void {
		$v = (string) self::merged()['email_footer_text'];
		echo '<textarea class="large-text wdc-wide-field" rows="5" name="' . esc_attr( self::OPTION_KEY ) . '[email_footer_text]">' . esc_textarea( $v ) . '</textarea>';
	}

	public static function field_email_body_html(): void {
		$v = (string) self::merged()['email_body_html'];
		echo '<textarea class="large-text code wdc-wide-field wdc-tall-html" rows="22" name="' . esc_attr( self::OPTION_KEY ) . '[email_body_html]">' . esc_textarea( $v ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Leave empty for the default styled code block. Allowed HTML is filtered for safety.', 'wp-dual-check' ) . '</p>';
	}

	public static function field_email_body_text(): void {
		$v = (string) self::merged()['email_body_text'];
		echo '<textarea class="large-text wdc-wide-field wdc-tall-plain" rows="14" name="' . esc_attr( self::OPTION_KEY ) . '[email_body_text]">' . esc_textarea( $v ) . '</textarea>';
	}

	public static function field_debug_logging(): void {
		$on = ! empty( self::merged()['debug_logging'] );
		echo '<input type="hidden" name="' . esc_attr( self::OPTION_KEY ) . '[debug_logging]" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[debug_logging]" value="1" ' . checked( $on, true, false ) . ' /> ';
		esc_html_e( 'Write structured debug lines to the PHP error log (also when WP_DEBUG is true). Never logs login codes or tokens.', 'wp-dual-check' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'Turn off on production unless you are troubleshooting. Requires a working error_log destination.', 'wp-dual-check' ) . '</p>';
	}

	public static function handle_send_test_mail(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wp-dual-check' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wp_dual_check_test_mail' );

		$user = wp_get_current_user();
		if ( ! $user instanceof \WP_User || ! $user->ID ) {
			wp_die( esc_html__( 'No user context.', 'wp-dual-check' ), '', array( 'response' => 400 ) );
		}

		Logger::log( 'test_mail_requested', array( 'user_id' => (int) $user->ID ) );

		$result = Mailer::send_test_email( $user );
		$uid    = (int) $user->ID;
		$back   = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		if ( true === $result ) {
			set_transient(
				'wdc_test_mail_flash_' . $uid,
				array( 'success' => true ),
				60
			);
		} else {
			$msg = $result instanceof \WP_Error ? $result->get_error_message() : __( 'Could not send test email.', 'wp-dual-check' );
			set_transient(
				'wdc_test_mail_flash_' . $uid,
				array(
					'success' => false,
					'message' => $msg,
				),
				60
			);
		}

		wp_safe_redirect( $back );
		exit;
	}
}
