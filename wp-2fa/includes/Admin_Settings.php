<?php
/**
 * Settings → WP Dual Check: TTL, attempts, mail identity, REST, resend cooldown (secrets stay in ENV).
 *
 * @package WPDualCheck
 */

namespace WPDualCheck;

final class Admin_Settings {

	public const OPTION_KEY   = 'wp_dual_check_settings';
	public const PAGE_SLUG    = 'wp-dual-check';
	public const OPTION_GROUP = 'wp_dual_check_settings_group';

	public const DEFAULTS = array(
		'code_ttl'        => 600,
		'max_attempts'    => 5,
		'from_email'      => '',
		'from_name'       => '',
		'rest_enabled'    => false,
		'resend_cooldown' => 60,
	);

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
	}

	public static function add_menu(): void {
		add_options_page(
			__( 'WP Dual Check', 'wp-dual-check' ),
			__( 'WP Dual Check', 'wp-dual-check' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render_page' )
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
				echo '<p>' . esc_html__( 'Sensitive values (SMTP credentials and optional signing secret) belong in environment variables or wp-config.php, not here.', 'wp-dual-check' ) . '</p>';
			},
			self::PAGE_SLUG
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
			'wdc_rest_enabled',
			__( 'REST API', 'wp-dual-check' ),
			array( self::class, 'field_rest_enabled' ),
			self::PAGE_SLUG,
			'wdc_main'
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

		return array(
			'code_ttl'        => isset( $input['code_ttl'] ) ? max( 60, min( 86400, (int) $input['code_ttl'] ) ) : (int) $prev['code_ttl'],
			'max_attempts'    => isset( $input['max_attempts'] ) ? max( 1, min( 50, (int) $input['max_attempts'] ) ) : (int) $prev['max_attempts'],
			'resend_cooldown' => isset( $input['resend_cooldown'] ) ? max( 15, min( 600, (int) $input['resend_cooldown'] ) ) : (int) $prev['resend_cooldown'],
			'from_email'      => isset( $input['from_email'] ) ? sanitize_email( (string) $input['from_email'] ) : '',
			'from_name'       => isset( $input['from_name'] ) ? sanitize_text_field( (string) $input['from_name'] ) : '',
			'rest_enabled'    => ! empty( $input['rest_enabled'] ),
		);
	}

	/**
	 * @return array{code_ttl:int,max_attempts:int,from_email:string,from_name:string,rest_enabled:bool,resend_cooldown:int}
	 */
	public static function merged(): array {
		$stored = get_option( self::OPTION_KEY );
		if ( is_array( $stored ) && $stored !== array() ) {
			return array_merge( self::DEFAULTS, $stored );
		}
		$legacy = get_option( 'wp2fa_settings', array() );
		if ( is_array( $legacy ) && $legacy !== array() ) {
			return array_merge( self::DEFAULTS, $legacy );
		}
		return array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
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

	public static function field_rest_enabled(): void {
		$on = ! empty( self::merged()['rest_enabled'] );
		echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[rest_enabled]" value="1" ' . checked( $on, true, false ) . ' /> ';
		esc_html_e( 'Expose dual-check/v1 verify and resend endpoints (use with care; prefer HTTPS).', 'wp-dual-check' );
		echo '</label>';
	}
}
