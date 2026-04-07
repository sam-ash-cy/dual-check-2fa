<?php
/**
 * Per-user 2FA toggle and optional delivery email (user meta).
 *
 * @package WP2FA
 */

namespace WP2FA;

final class User_Settings {

	public const META_ENABLED         = 'wp2fa_enabled';
	public const META_DELIVERY_EMAIL  = 'wp2fa_delivery_email';

	public static function register(): void {
		add_action( 'show_user_profile', array( self::class, 'render_profile_fields' ) );
		add_action( 'edit_user_profile', array( self::class, 'render_profile_fields' ) );
		add_action( 'personal_options_update', array( self::class, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save_profile_fields' ) );
	}

	public static function is_2fa_enabled_for_user( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, self::META_ENABLED, true );
	}

	public static function render_profile_fields( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$enabled = self::is_2fa_enabled_for_user( $user->ID );
		$email   = (string) get_user_meta( $user->ID, self::META_DELIVERY_EMAIL, true );
		wp_nonce_field( 'wp2fa_profile', 'wp2fa_profile_nonce' );
		?>
		<h2><?php esc_html_e( 'Email two-factor login (WP 2FA)', 'wp-2fa' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Email 2FA', 'wp-2fa' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="wp2fa_enabled" value="1" <?php checked( $enabled ); ?> />
						<?php esc_html_e( 'Require a one-time code sent to email after my password is accepted.', 'wp-2fa' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'The site must have WP2FA_MAILER_DSN set in the environment (Settings → WP 2FA shows status).', 'wp-2fa' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wp2fa_delivery_email"><?php esc_html_e( 'Delivery email (optional)', 'wp-2fa' ); ?></label></th>
				<td>
					<input type="email" class="regular-text" name="wp2fa_delivery_email" id="wp2fa_delivery_email" value="<?php echo esc_attr( $email ); ?>" />
					<p class="description">
						<?php esc_html_e( 'Leave blank to use your account email address.', 'wp-2fa' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_profile_fields( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( empty( $_POST['wp2fa_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['wp2fa_profile_nonce'] ) ), 'wp2fa_profile' ) ) {
			return;
		}

		$enabled = ! empty( $_POST['wp2fa_enabled'] );
		if ( $enabled ) {
			update_user_meta( $user_id, self::META_ENABLED, '1' );
		} else {
			delete_user_meta( $user_id, self::META_ENABLED );
		}

		$delivery = isset( $_POST['wp2fa_delivery_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['wp2fa_delivery_email'] ) ) : '';
		if ( '' === $delivery ) {
			delete_user_meta( $user_id, self::META_DELIVERY_EMAIL );
		} elseif ( is_email( $delivery ) ) {
			update_user_meta( $user_id, self::META_DELIVERY_EMAIL, $delivery );
		}
	}
}
