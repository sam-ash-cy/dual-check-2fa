<?php
/**
 * Optional per-user delivery email (codes go to account email if unset).
 *
 * @package WPDualCheck
 */

namespace WPDualCheck;

final class User_Settings {

	public const META_DELIVERY_EMAIL = 'wp_dual_check_delivery_email';

	public static function register(): void {
		add_action( 'show_user_profile', array( self::class, 'render_profile_fields' ) );
		add_action( 'edit_user_profile', array( self::class, 'render_profile_fields' ) );
		add_action( 'personal_options_update', array( self::class, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save_profile_fields' ) );
	}

	public static function render_profile_fields( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$email = (string) get_user_meta( $user->ID, self::META_DELIVERY_EMAIL, true );
		if ( '' === $email ) {
			$email = (string) get_user_meta( $user->ID, 'wp2fa_delivery_email', true );
		}
		wp_nonce_field( 'wdc_profile', 'wdc_profile_nonce' );
		?>
		<h2><?php esc_html_e( 'WP Dual Check (email)', 'wp-dual-check' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wp_dual_check_delivery_email"><?php esc_html_e( 'Login code delivery email (optional)', 'wp-dual-check' ); ?></label></th>
				<td>
					<input type="email" class="regular-text" name="wp_dual_check_delivery_email" id="wp_dual_check_delivery_email" value="<?php echo esc_attr( $email ); ?>" />
					<p class="description">
						<?php esc_html_e( 'If the site requires email codes for all logins, leave blank to send codes to this account’s email address. Set this to use a different inbox.', 'wp-dual-check' ); ?>
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
		if ( empty( $_POST['wdc_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['wdc_profile_nonce'] ) ), 'wdc_profile' ) ) {
			return;
		}

		$delivery = isset( $_POST['wp_dual_check_delivery_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['wp_dual_check_delivery_email'] ) ) : '';
		if ( '' === $delivery ) {
			delete_user_meta( $user_id, self::META_DELIVERY_EMAIL );
		} elseif ( is_email( $delivery ) ) {
			update_user_meta( $user_id, self::META_DELIVERY_EMAIL, $delivery );
		}
		delete_user_meta( $user_id, 'wp2fa_delivery_email' );
	}
}
