<?php
/**
 * Admin menus, asset enqueue, settings screens, and field markup.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Admin\Settings;

use WPDualCheck\Core\Config;
use WPDualCheck\Core\Logger;
use WPDualCheck\Email\Mailer;
use WPDualCheck\Email\Templates\EmailTemplateManager;

/**
 * Renders admin pages, settings field markup, and the test-email handler.
 */
final class SettingsPage {

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

	/** Loads media picker assets on the Email Template admin screen. */
	public static function enqueue_email_template_assets( string $hook ): void {
		if ( 'wp-dual-check_page_' . SettingsRepository::PAGE_EMAIL_TEMPLATE !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style(
			'wdc-email-template',
			WP_DUAL_CHECK_URL . 'assets/css/admin-email-template.css',
			array(),
			WP_DUAL_CHECK_VERSION
		);
		wp_enqueue_script(
			'wdc-email-template',
			WP_DUAL_CHECK_URL . 'assets/js/admin-email-template.js',
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

	/** Registers top-level menu and submenus. */
	public static function add_menu(): void {
		add_menu_page(
			__( 'WP Dual Check', 'wp-dual-check' ),
			__( 'WP Dual Check', 'wp-dual-check' ),
			'manage_options',
			SettingsRepository::PAGE_SLUG,
			array( self::class, 'render_page' ),
			'dashicons-shield',
			80
		);
		add_submenu_page(
			SettingsRepository::PAGE_SLUG,
			__( 'General', 'wp-dual-check' ),
			__( 'General', 'wp-dual-check' ),
			'manage_options',
			SettingsRepository::PAGE_SLUG,
			array( self::class, 'render_page' )
		);
		add_submenu_page(
			SettingsRepository::PAGE_SLUG,
			__( 'Email Template', 'wp-dual-check' ),
			__( 'Email Template', 'wp-dual-check' ),
			'manage_options',
			SettingsRepository::PAGE_EMAIL_TEMPLATE,
			array( self::class, 'render_page_email_template' )
		);
		add_submenu_page(
			SettingsRepository::PAGE_SLUG,
			__( 'Mail Transport Providers', 'wp-dual-check' ),
			__( 'Mail Transport Providers', 'wp-dual-check' ),
			'manage_options',
			SettingsRepository::PAGE_MAIL_PROVIDERS,
			array( self::class, 'render_page_mail_providers' )
		);
	}

	/** Echoes the standard “Settings saved” admin notice when appropriate. */
	public static function print_settings_saved_notice(): void {
		if ( isset( $_GET['settings-updated'] ) && 'true' === sanitize_text_field( wp_unslash( (string) $_GET['settings-updated'] ) ) ) {
			echo '<div id="wdc-settings-saved" class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wp-dual-check' ) . '</p></div>';
		}
	}

	/** General settings + test email form. */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$uid   = get_current_user_id();
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
				settings_fields( SettingsRepository::OPTION_GROUP );
				do_settings_sections( SettingsRepository::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Test Email', 'wp-dual-check' ); ?></h2>
			<p><?php esc_html_e( 'Send a test message using your account’s selected mail transport and From settings. No login code is included.', 'wp-dual-check' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wp_dual_check_test_mail' ); ?>
				<input type="hidden" name="action" value="wp_dual_check_send_test_mail" />
				<?php
				submit_button( __( 'Send Test Email', 'wp-dual-check' ), 'secondary', 'submit', false );
				?>
			</form>
			<p class="description"><?php esc_html_e( 'Uses your user profile delivery email if set, otherwise uses your account email.', 'wp-dual-check' ); ?></p>
		</div>
		<?php
	}

	/** Email template customization screen. */
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
				settings_fields( SettingsRepository::OPTION_GROUP );
				do_settings_sections( SettingsRepository::PAGE_EMAIL_TEMPLATE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/** Tabbed mail provider credential screen. */
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
							'page' => SettingsRepository::PAGE_MAIL_PROVIDERS,
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
				settings_fields( SettingsRepository::OPTION_GROUP );
				self::render_single_settings_section( SettingsRepository::PAGE_MAIL_PROVIDERS, $section_id );
				submit_button();
				?>
			</form>
			<p class="description"><?php esc_html_e( 'The default mail transport is chosen on WP Dual Check → General.', 'wp-dual-check' ); ?></p>
		</div>
		<?php
	}

	/** Outputs one settings section table (used for provider tabs). */
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

	/** admin-post handler: sends test mail and redirects with a flash transient. */
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
		$back   = admin_url( 'admin.php?page=' . SettingsRepository::PAGE_SLUG );

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

	/** Normalizes hex for HTML color input (expand 3-digit, default fallback). */
	private static function hex_for_color_input( string $hex, string $fallback ): string {
		$sanitized_hex = sanitize_hex_color( trim( $hex ) );
		if ( '' === $sanitized_hex ) {
			$sanitized_hex = sanitize_hex_color( trim( $fallback ) );
		}
		if ( '' === $sanitized_hex ) {
			$sanitized_hex = '#2271b1';
		}
		if ( 4 === strlen( $sanitized_hex ) && '#' === $sanitized_hex[0] ) {
			$sanitized_hex = '#' . $sanitized_hex[1] . $sanitized_hex[1] . $sanitized_hex[2] . $sanitized_hex[2] . $sanitized_hex[3] . $sanitized_hex[3];
		}

		return $sanitized_hex;
	}

	/** URL field with media library picker and preview for header/footer images. */
	private static function render_email_image_url_field( string $settings_key, string $id_prefix, string $description ): void {
		$image_url = (string) SettingsRepository::merged()[ $settings_key ];
		$input_id  = $id_prefix . '_url';
		$name      = SettingsRepository::OPTION_KEY . '[' . $settings_key . ']';
		$has_image = '' !== $image_url;
		echo '<div class="wdc-media-url-field" data-wdc-media-url>';
		echo '<div class="wdc-media-url-row">';
		echo '<input type="url" id="' . esc_attr( $input_id ) . '" class="large-text wdc-wide-field wdc-media-url-input" name="' . esc_attr( $name ) . '" value="' . esc_attr( $image_url ) . '" placeholder="https://..." autocomplete="off" />';
		echo '<p class="wdc-media-url-actions">';
		echo '<button type="button" class="button wdc-media-select">' . esc_html__( 'Media Library…', 'wp-dual-check' ) . '</button> ';
		echo '<button type="button" class="button-link wdc-media-clear">' . esc_html__( 'Clear', 'wp-dual-check' ) . '</button>';
		echo '</p>';
		echo '</div>';
		echo '<div class="wdc-media-preview-wrap"' . ( $has_image ? '' : ' style="display:none;"' ) . '>';
		if ( $has_image ) {
			echo '<img src="' . esc_url( $image_url ) . '" alt="" class="wdc-media-preview-img" />';
		} else {
			echo '<img src="" alt="" class="wdc-media-preview-img" />';
		}
		echo '</div>';
		echo '</div>';
		echo '<p class="description">' . esc_html( $description ) . '</p>';
	}

	/** Checkbox: require email code for all logins. */
	public static function field_require_all_logins(): void {
		$on = ! empty( SettingsRepository::merged()['require_all_logins'] );
		echo '<input type="hidden" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[require_all_logins]" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[require_all_logins]" value="1" ' . checked( $on, true, false ) . ' /> ';
		esc_html_e( 'Require email verification code for every user after a correct password (all logins).', 'wp-dual-check' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'Applies to administrators, editors, subscribers—anyone authenticating through WordPress.', 'wp-dual-check' ) . '</p>';
	}

	/** Numeric: challenge/code TTL in seconds. */
	public static function field_code_ttl(): void {
		$code_ttl_seconds = (int) SettingsRepository::merged()['code_ttl'];
		echo '<input type="number" min="60" max="86400" class="small-text" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[code_ttl]" value="' . esc_attr( (string) $code_ttl_seconds ) . '" />';
		echo '<p class="description">' . esc_html__( 'How long the pending login and code remain valid.', 'wp-dual-check' ) . '</p>';
	}

	/** Numeric: digit count for generated codes. */
	public static function field_code_length(): void {
		$code_length_digits = (int) SettingsRepository::merged()['code_length'];
		echo '<input type="number" min="4" max="12" class="small-text" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[code_length]" value="' . esc_attr( (string) $code_length_digits ) . '" />';
		echo '<p class="description">' . esc_html__( 'Number of digits in the login code (e.g. 6).', 'wp-dual-check' ) . '</p>';
	}

	/** Numeric: max wrong-code attempts per pending session. */
	public static function field_max_attempts(): void {
		$max_attempts = (int) SettingsRepository::merged()['max_attempts'];
		echo '<input type="number" min="1" max="50" class="small-text" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[max_attempts]" value="' . esc_attr( (string) $max_attempts ) . '" />';
	}

	/** Numeric: seconds between resend actions per user. */
	public static function field_resend_cooldown(): void {
		$resend_cooldown_seconds = (int) SettingsRepository::merged()['resend_cooldown'];
		echo '<input type="number" min="15" max="600" class="small-text" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[resend_cooldown]" value="' . esc_attr( (string) $resend_cooldown_seconds ) . '" />';
		echo '<p class="description">' . esc_html__( 'Minimum time between “Resend code” actions per user.', 'wp-dual-check' ) . '</p>';
	}

	/** From address for outbound mail. */
	public static function field_from_email(): void {
		$from_email = (string) SettingsRepository::merged()['from_email'];
		echo '<input type="email" class="regular-text" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[from_email]" value="' . esc_attr( $from_email ) . '" />';
		echo '<p class="description">' . esc_html__( 'Leave blank to use the site admin email.', 'wp-dual-check' ) . '</p>';
	}

	/** From display name for outbound mail. */
	public static function field_from_name(): void {
		$from_name = (string) SettingsRepository::merged()['from_name'];
		echo '<input type="text" class="regular-text" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[from_name]" value="' . esc_attr( $from_name ) . '" />';
		echo '<p class="description">' . esc_html__( 'Leave blank to use the site title.', 'wp-dual-check' ) . '</p>';
	}

	/** Site default Symfony/wp_mail transport id. */
	public static function field_default_mailer_transport(): void {
		$current = (string) SettingsRepository::merged()['default_mailer_transport'];
		$name    = SettingsRepository::OPTION_KEY . '[default_mailer_transport]';
		echo '<select id="wdc_default_mailer_transport" name="' . esc_attr( $name ) . '">';
		foreach ( Mailer::transport_choices() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Used for all users unless overridden on their profile. Configure API and Gmail SMTP credentials under Mail Transport Providers.', 'wp-dual-check' ) . '</p>';
	}

	/** SendGrid API key field (password input). */
	public static function field_api_sendgrid(): void {
		$key = (string) SettingsRepository::merged()['api_sendgrid_key'];
		$secret_placeholder = '' !== $key ? __( '•••••••• (leave blank to keep)', 'wp-dual-check' ) : '';
		echo '<p><label for="wdc_sg_key">' . esc_html__( 'API key', 'wp-dual-check' ) . '</label><br />';
		echo '<input type="password" class="regular-text" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[api_sendgrid_key]" id="wdc_sg_key" value="" autocomplete="new-password" placeholder="' . esc_attr( $secret_placeholder ) . '" /></p>';
		echo '<p class="description">' . esc_html__( 'Env: WP_DUAL_CHECK_SENDGRID_API_KEY', 'wp-dual-check' ) . '</p>';
	}

	/** Mailgun key, domain, and region. */
	public static function field_api_mailgun(): void {
		$settings       = SettingsRepository::merged();
		$key            = (string) $settings['api_mailgun_key'];
		$sending_domain = (string) $settings['api_mailgun_domain'];
		$region         = (string) $settings['api_mailgun_region'];
		$secret_placeholder = '' !== $key ? __( '•••••••• (leave blank to keep)', 'wp-dual-check' ) : '';
		echo '<p><label for="wdc_mg_key">' . esc_html__( 'API key', 'wp-dual-check' ) . '</label><br />';
		echo '<input type="password" class="regular-text" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[api_mailgun_key]" id="wdc_mg_key" value="" autocomplete="new-password" placeholder="' . esc_attr( $secret_placeholder ) . '" /></p>';
		echo '<p><label for="wdc_mg_domain">' . esc_html__( 'Sending domain', 'wp-dual-check' ) . '</label><br />';
		echo '<input type="text" class="regular-text" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[api_mailgun_domain]" id="wdc_mg_domain" value="' . esc_attr( $sending_domain ) . '" /></p>';
		echo '<p><label for="wdc_mg_reg">' . esc_html__( 'Region', 'wp-dual-check' ) . '</label><br />';
		echo '<select name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[api_mailgun_region]" id="wdc_mg_reg">';
		echo '<option value="us" ' . selected( $region, 'us', false ) . '>US</option>';
		echo '<option value="eu" ' . selected( $region, 'eu', false ) . '>EU</option>';
		echo '</select></p>';
		echo '<p class="description">' . esc_html__( 'Env: WP_DUAL_CHECK_MAILGUN_API_KEY, MAILGUN_DOMAIN, MAILGUN_REGION (us|eu).', 'wp-dual-check' ) . '</p>';
	}

	/** Amazon SES access key, secret, and region. */
	public static function field_api_ses(): void {
		$settings          = SettingsRepository::merged();
		$access_key_id     = (string) $settings['api_ses_access_key'];
		$access_placeholder = '' !== $access_key_id ? __( '•••••••• (leave blank to keep)', 'wp-dual-check' ) : '';
		$secret_key        = (string) $settings['api_ses_secret_key'];
		$secret_placeholder = '' !== $secret_key ? __( '•••••••• (leave blank to keep)', 'wp-dual-check' ) : '';
		echo '<p><label for="wdc_ses_a">' . esc_html__( 'Access key ID', 'wp-dual-check' ) . '</label><br />';
		echo '<input type="password" class="regular-text" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[api_ses_access_key]" id="wdc_ses_a" value="" autocomplete="new-password" placeholder="' . esc_attr( $access_placeholder ) . '" /></p>';
		echo '<p><label for="wdc_ses_s">' . esc_html__( 'Secret access key', 'wp-dual-check' ) . '</label><br />';
		echo '<input type="password" class="regular-text" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[api_ses_secret_key]" id="wdc_ses_s" value="" autocomplete="new-password" placeholder="' . esc_attr( $secret_placeholder ) . '" /></p>';
		echo '<p><label for="wdc_ses_r">' . esc_html__( 'AWS region', 'wp-dual-check' ) . '</label><br />';
		echo '<input type="text" class="regular-text" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[api_ses_region]" id="wdc_ses_r" value="' . esc_attr( (string) $settings['api_ses_region'] ) . '" /></p>';
		echo '<p class="description">' . esc_html__( 'Env: WP_DUAL_CHECK_SES_ACCESS_KEY, SES_SECRET_KEY, SES_REGION.', 'wp-dual-check' ) . '</p>';
	}

	/** Postmark server token field. */
	public static function field_api_postmark(): void {
		$key = (string) SettingsRepository::merged()['api_postmark_token'];
		$secret_placeholder = '' !== $key ? __( '•••••••• (leave blank to keep)', 'wp-dual-check' ) : '';
		echo '<p><label for="wdc_pm_t">' . esc_html__( 'Server API token', 'wp-dual-check' ) . '</label><br />';
		echo '<input type="password" class="regular-text" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[api_postmark_token]" id="wdc_pm_t" value="" autocomplete="new-password" placeholder="' . esc_attr( $secret_placeholder ) . '" /></p>';
		echo '<p class="description">' . esc_html__( 'Env: WP_DUAL_CHECK_POSTMARK_TOKEN', 'wp-dual-check' ) . '</p>';
	}

	/** Gmail address + app password for SMTP bridge. */
	public static function field_api_gmail(): void {
		$settings        = SettingsRepository::merged();
		$gmail_user      = (string) $settings['api_gmail_user'];
		$app_password           = (string) $settings['api_gmail_app_password'];
		$secret_placeholder = '' !== $app_password ? __( '•••••••• (leave blank to keep)', 'wp-dual-check' ) : '';
		echo '<p><strong>' . esc_html__( 'Google SMTP + app password (not OAuth2 API)', 'wp-dual-check' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Symfony’s Gmail bridge uses smtp.gmail.com with a 16-character app password. Full Gmail OAuth2 is not included; use wp_mail with a Google plugin or wp_dual_check_symfony_dsn to inject a custom transport.', 'wp-dual-check' ) . '</p>';
		echo '<p><label for="wdc_gm_u">' . esc_html__( 'Gmail address', 'wp-dual-check' ) . '</label><br />';
		echo '<input type="email" class="regular-text" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[api_gmail_user]" id="wdc_gm_u" value="' . esc_attr( $gmail_user ) . '" /></p>';
		echo '<p><label for="wdc_gm_p">' . esc_html__( 'App password', 'wp-dual-check' ) . '</label><br />';
		echo '<input type="password" class="regular-text" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[api_gmail_app_password]" id="wdc_gm_p" value="" autocomplete="new-password" placeholder="' . esc_attr( $secret_placeholder ) . '" /></p>';
		echo '<p class="description">' . esc_html__( 'Env: WP_DUAL_CHECK_GMAIL_ADDRESS, GMAIL_APP_PASSWORD', 'wp-dual-check' ) . '</p>';
	}

	/** Subject line template with placeholders. */
	public static function field_email_subject_template(): void {
		$subject_template = (string) SettingsRepository::merged()['email_subject_template'];
		echo '<input type="text" class="large-text wdc-wide-field" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[email_subject_template]" value="' . esc_attr( $subject_template ) . '" autocomplete="off" />';
	}

	/** Multipart vs HTML-only vs text-only. */
	public static function field_email_format(): void {
		$settings         = SettingsRepository::merged();
		$selected_format  = isset( $settings['email_format'] ) ? (string) $settings['email_format'] : EmailTemplateManager::FORMAT_MULTIPART;
		$name             = SettingsRepository::OPTION_KEY . '[email_format]';
		echo '<select id="wdc_email_format" name="' . esc_attr( $name ) . '">';
		echo '<option value="' . esc_attr( EmailTemplateManager::FORMAT_MULTIPART ) . '" ' . selected( $selected_format, EmailTemplateManager::FORMAT_MULTIPART, false ) . '>' . esc_html__( 'HTML + plain text (multipart)', 'wp-dual-check' ) . '</option>';
		echo '<option value="' . esc_attr( EmailTemplateManager::FORMAT_HTML ) . '" ' . selected( $selected_format, EmailTemplateManager::FORMAT_HTML, false ) . '>' . esc_html__( 'HTML only', 'wp-dual-check' ) . '</option>';
		echo '<option value="' . esc_attr( EmailTemplateManager::FORMAT_TEXT ) . '" ' . selected( $selected_format, EmailTemplateManager::FORMAT_TEXT, false ) . '>' . esc_html__( 'Plain text only', 'wp-dual-check' ) . '</option>';
		echo '</select>';
	}

	/** Accent bar colour (picker + hex). */
	public static function field_email_accent_color(): void {
		$stored_hex = (string) SettingsRepository::merged()['email_accent_color'];
		$picker_hex = self::hex_for_color_input( $stored_hex, '#2271b1' );
		$id_pick    = 'wdc_email_accent_color_pick';
		$id_text    = 'wdc_email_accent_color_text';
		echo '<span class="wdc-color-sync" data-wdc-color-sync>';
		echo '<input type="color" id="' . esc_attr( $id_pick ) . '" class="wdc-color-field" value="' . esc_attr( $picker_hex ) . '" title="' . esc_attr__( 'Choose accent colour', 'wp-dual-check' ) . '" /> ';
		echo '<input type="text" id="' . esc_attr( $id_text ) . '" class="wdc-color-hex-input" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[email_accent_color]" value="' . esc_attr( $stored_hex ) . '" pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$" maxlength="7" spellcheck="false" />';
		echo '</span>';
		echo '<p class="description">' . esc_html__( 'Used for the optional header bar behind “Header bar (HTML)”. Adjust with the picker or type a hex value.', 'wp-dual-check' ) . '</p>';
	}

	/** Outer table background colour. */
	public static function field_email_background_color(): void {
		$stored_hex = (string) SettingsRepository::merged()['email_background_color'];
		$picker_hex = self::hex_for_color_input( $stored_hex, '#f0f0f1' );
		$id_pick    = 'wdc_email_background_color_pick';
		$id_text    = 'wdc_email_background_color_text';
		echo '<span class="wdc-color-sync" data-wdc-color-sync>';
		echo '<input type="color" id="' . esc_attr( $id_pick ) . '" class="wdc-color-field" value="' . esc_attr( $picker_hex ) . '" title="' . esc_attr__( 'Choose outer background colour', 'wp-dual-check' ) . '" /> ';
		echo '<input type="text" id="' . esc_attr( $id_text ) . '" class="wdc-color-hex-input" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[email_background_color]" value="' . esc_attr( $stored_hex ) . '" pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$" maxlength="7" spellcheck="false" />';
		echo '</span>';
	}

	/** Max width of the inner email card in pixels. */
	public static function field_email_container_max_width_px(): void {
		$max_width_px = (int) SettingsRepository::merged()['email_container_max_width_px'];
		echo '<input type="number" min="200" max="920" class="small-text" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[email_container_max_width_px]" value="' . esc_attr( (string) $max_width_px ) . '" /> px';
	}

	/** HTML snippet in the coloured header bar. */
	public static function field_email_header_text(): void {
		$header_html = (string) SettingsRepository::merged()['email_header_text'];
		echo '<textarea class="large-text wdc-wide-field" rows="4" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[email_header_text]">' . esc_textarea( $header_html ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Shown on a bar using the accent colour (above the main content).', 'wp-dual-check' ) . '</p>';
	}

	/** Header banner image URL (media picker). */
	public static function field_email_header_image_url(): void {
		self::render_email_image_url_field(
			'email_header_image_url',
			'wdc_email_header_image',
			__( 'Paste an image URL or open the Media Library.', 'wp-dual-check' )
		);
	}

	/** Display width for header image. */
	public static function field_email_header_width_px(): void {
		$header_width_px = (int) SettingsRepository::merged()['email_header_width_px'];
		echo '<input type="number" min="200" max="920" class="small-text" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[email_header_width_px]" value="' . esc_attr( (string) $header_width_px ) . '" /> px';
	}

	/** Footer image URL (media picker). */
	public static function field_email_footer_image_url(): void {
		self::render_email_image_url_field(
			'email_footer_image_url',
			'wdc_email_footer_image',
			__( 'Paste an image URL or open the Media Library.', 'wp-dual-check' )
		);
	}

	/** Display width for footer image. */
	public static function field_email_footer_width_px(): void {
		$footer_width_px = (int) SettingsRepository::merged()['email_footer_width_px'];
		echo '<input type="number" min="200" max="920" class="small-text" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[email_footer_width_px]" value="' . esc_attr( (string) $footer_width_px ) . '" /> px';
	}

	/** HTML footer block below content. */
	public static function field_email_footer_text(): void {
		$footer_html = (string) SettingsRepository::merged()['email_footer_text'];
		echo '<textarea class="large-text wdc-wide-field" rows="5" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[email_footer_text]">' . esc_textarea( $footer_html ) . '</textarea>';
	}

	/** Custom HTML body fragment (optional). */
	public static function field_email_body_html(): void {
		$body_html = (string) SettingsRepository::merged()['email_body_html'];
		echo '<textarea class="large-text code wdc-wide-field wdc-tall-html" rows="22" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[email_body_html]">' . esc_textarea( $body_html ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Leave empty for the default styled code block. Allowed HTML is filtered for safety.', 'wp-dual-check' ) . '</p>';
	}

	/** Plain-text body template. */
	public static function field_email_body_text(): void {
		$body_text = (string) SettingsRepository::merged()['email_body_text'];
		echo '<textarea class="large-text wdc-wide-field wdc-tall-plain" rows="14" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[email_body_text]">' . esc_textarea( $body_text ) . '</textarea>';
	}

	/** Toggle structured JSON logging to error_log. */
	public static function field_debug_logging(): void {
		$on = ! empty( SettingsRepository::merged()['debug_logging'] );
		echo '<input type="hidden" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[debug_logging]" value="0" />';
		echo '<label><input type="checkbox" name="' . esc_attr( SettingsRepository::OPTION_KEY ) . '[debug_logging]" value="1" ' . checked( $on, true, false ) . ' /> ';
		esc_html_e( 'Write structured debug lines to the PHP error log (also when WP_DEBUG is true). Never logs login codes or tokens.', 'wp-dual-check' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'Turn off on production unless you are troubleshooting. Requires a working error_log destination.', 'wp-dual-check' ) . '</p>';
	}
}
