<?php
/**
 * Top-level admin menu: TTL, attempts, mail identity, transport, resend cooldown (secrets stay in ENV).
 *
 * @package WPDualCheck
 */

namespace WPDualCheck;

final class Admin_Settings {

	public const OPTION_KEY   = 'wp_dual_check_settings';
	public const PAGE_SLUG    = 'wp-dual-check';
	public const OPTION_GROUP = 'wp_dual_check_settings_group';

	public const DEFAULTS = array(
		'require_all_logins'       => false,
		'code_ttl'                 => 600,
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
		'api_ops_notes'            => '',
	);

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_post_wp_dual_check_send_test_mail', array( self::class, 'handle_send_test_mail' ) );
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
				echo '<p>' . esc_html__( 'SMTP credentials stay in environment variables or wp-config.php, not here.', 'wp-dual-check' ) . '</p>';
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

		add_settings_section(
			'wdc_api',
			__( 'API email providers', 'wp-dual-check' ),
			static function () {
				echo '<p>' . esc_html__( 'Used when the default (or per-user) transport is SendGrid, Mailgun, SES, Postmark, or Gmail SMTP. Prefer wp-config.php or environment variables on production; fields here are stored in the database.', 'wp-dual-check' ) . '</p>';
				echo '<p>' . esc_html__( 'Leave a secret field blank when saving to keep the previous value.', 'wp-dual-check' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'wdc_api_sendgrid',
			'SendGrid',
			array( self::class, 'field_api_sendgrid' ),
			self::PAGE_SLUG,
			'wdc_api'
		);
		add_settings_field(
			'wdc_api_mailgun',
			'Mailgun',
			array( self::class, 'field_api_mailgun' ),
			self::PAGE_SLUG,
			'wdc_api'
		);
		add_settings_field(
			'wdc_api_ses',
			'Amazon SES',
			array( self::class, 'field_api_ses' ),
			self::PAGE_SLUG,
			'wdc_api'
		);
		add_settings_field(
			'wdc_api_postmark',
			'Postmark',
			array( self::class, 'field_api_postmark' ),
			self::PAGE_SLUG,
			'wdc_api'
		);
		add_settings_field(
			'wdc_api_gmail',
			'Gmail',
			array( self::class, 'field_api_gmail' ),
			self::PAGE_SLUG,
			'wdc_api'
		);
		add_settings_field(
			'wdc_api_ops_notes',
			__( 'Operations notes (optional)', 'wp-dual-check' ),
			array( self::class, 'field_api_ops_notes' ),
			self::PAGE_SLUG,
			'wdc_api'
		);

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
				echo '<p>' . esc_html__( 'API provider keys can also be set via constants/environment (see README). Those override the fields above.', 'wp-dual-check' ) . '</p>';
			},
			self::PAGE_SLUG
		);
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
			'require_all_logins'         => ! empty( $input['require_all_logins'] ),
			'code_ttl'                   => isset( $input['code_ttl'] ) ? max( 60, min( 86400, (int) $input['code_ttl'] ) ) : (int) $prev['code_ttl'],
			'max_attempts'               => isset( $input['max_attempts'] ) ? max( 1, min( 50, (int) $input['max_attempts'] ) ) : (int) $prev['max_attempts'],
			'resend_cooldown'            => isset( $input['resend_cooldown'] ) ? max( 15, min( 600, (int) $input['resend_cooldown'] ) ) : (int) $prev['resend_cooldown'],
			'from_email'                 => isset( $input['from_email'] ) ? sanitize_email( (string) $input['from_email'] ) : '',
			'from_name'                  => isset( $input['from_name'] ) ? sanitize_text_field( (string) $input['from_name'] ) : '',
			'default_mailer_transport'   => Mailer::sanitize_transport_id( $transport, Mailer::TRANSPORT_DSN ),
			'debug_logging'              => ! empty( $input['debug_logging'] ),
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
			'api_ops_notes'              => isset( $input['api_ops_notes'] ) ? sanitize_textarea_field( (string) $input['api_ops_notes'] ) : (string) $prev['api_ops_notes'],
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
		unset( $out['rest_enabled'] );
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
			<?php
			if ( isset( $_GET['settings-updated'] ) && 'true' === sanitize_text_field( wp_unslash( (string) $_GET['settings-updated'] ) ) ) {
				echo '<div id="wdc-settings-saved" class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wp-dual-check' ) . '</p></div>';
			}
			?>
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

	public static function field_require_all_logins(): void {
		$on = ! empty( self::merged()['require_all_logins'] );
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
		echo '<p class="description">' . esc_html__( 'Used for all users unless overridden on their profile. API providers use the section below (or env vars). “DSN” uses WP_DUAL_CHECK_MAILER_DSN only.', 'wp-dual-check' ) . '</p>';
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

	public static function field_api_ops_notes(): void {
		$v = (string) self::merged()['api_ops_notes'];
		echo '<textarea class="large-text" rows="4" name="' . esc_attr( self::OPTION_KEY ) . '[api_ops_notes]" id="wdc_api_notes">' . esc_textarea( $v ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Optional: SPF/DKIM/domain verification reminders, ticket links, etc. Not used when sending mail.', 'wp-dual-check' ) . '</p>';
	}

	public static function field_debug_logging(): void {
		$on = ! empty( self::merged()['debug_logging'] );
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
