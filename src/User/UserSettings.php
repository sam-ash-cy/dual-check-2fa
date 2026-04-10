<?php
/**
 * Optional per-user delivery email and mail transport override.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\User;

use WPDualCheck\Email\Mailer;

/**
 * Profile fields and save handlers for code delivery preferences.
 */
final class UserSettings {

	public const META_DELIVERY_EMAIL = MetaKeys::DELIVERY_EMAIL;
	public const META_MAIL_TRANSPORT = MetaKeys::MAIL_TRANSPORT;

	/**
	 * Hooks profile display and save actions.
	 */
	public static function register(): void {
		add_action( 'show_user_profile', array( self::class, 'render_profile_fields' ) );
		add_action( 'edit_user_profile', array( self::class, 'render_profile_fields' ) );
		add_action( 'personal_options_update', array( self::class, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save_profile_fields' ) );
	}

	/**
	 * Whether the acting user may change another user’s mail transport override.
	 */
	public static function can_set_mailer_transport( int $actor_id, \WP_User $target ): bool {
		if ( user_can( $actor_id, 'manage_options' ) ) {
			return true;
		}
		if ( (int) $target->ID === $actor_id ) {
			return false;
		}
		return user_can( $actor_id, 'edit_users' );
	}

	/**
	 * Outputs delivery email and optional transport select on user edit screens.
	 */
	public static function render_profile_fields( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$email = (string) get_user_meta( $user->ID, MetaKeys::DELIVERY_EMAIL, true );
		if ( '' === $email ) {
			$email = (string) get_user_meta( $user->ID, 'wp2fa_delivery_email', true );
		}

		$actor_id       = get_current_user_id();
		$show_transport = self::can_set_mailer_transport( $actor_id, $user );

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
			<?php if ( $show_transport ) : ?>
				<tr>
					<th scope="row"><label for="wp_dual_check_mailer_transport"><?php esc_html_e( 'Login code mail transport', 'wp-dual-check' ); ?></label></th>
					<td>
						<select name="wp_dual_check_mailer_transport" id="wp_dual_check_mailer_transport">
							<?php
							$selected_transport = (string) get_user_meta( $user->ID, MetaKeys::MAIL_TRANSPORT, true );
							if ( '' === $selected_transport ) {
								$selected_transport = (string) get_user_meta( $user->ID, 'wp2fa_mailer_transport', true );
							}
							$selected_transport = sanitize_key( $selected_transport );
							if ( '' === $selected_transport ) {
								$selected_transport = Mailer::USER_TRANSPORT_INHERIT;
							}
							?>
							<option value="<?php echo esc_attr( Mailer::USER_TRANSPORT_INHERIT ); ?>" <?php selected( $selected_transport, Mailer::USER_TRANSPORT_INHERIT ); ?>>
								<?php esc_html_e( 'Site default', 'wp-dual-check' ); ?>
							</option>
							<?php foreach ( Mailer::transport_choices() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $selected_transport, $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Override how login codes are sent for this user only. Site default is set under WP Dual Check.', 'wp-dual-check' ); ?>
						</p>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * Persists delivery email and transport when the profile form is submitted.
	 */
	public static function save_profile_fields( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( empty( $_POST['wdc_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['wdc_profile_nonce'] ) ), 'wdc_profile' ) ) {
			return;
		}

		$delivery = isset( $_POST['wp_dual_check_delivery_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['wp_dual_check_delivery_email'] ) ) : '';
		if ( '' === $delivery ) {
			delete_user_meta( $user_id, MetaKeys::DELIVERY_EMAIL );
		} elseif ( is_email( $delivery ) ) {
			update_user_meta( $user_id, MetaKeys::DELIVERY_EMAIL, $delivery );
		}
		delete_user_meta( $user_id, 'wp2fa_delivery_email' );

		$target = get_userdata( $user_id );
		if ( ! $target instanceof \WP_User ) {
			return;
		}

		$actor_id = get_current_user_id();
		if ( self::can_set_mailer_transport( $actor_id, $target ) && isset( $_POST['wp_dual_check_mailer_transport'] ) ) {
			$choice = sanitize_key( wp_unslash( (string) $_POST['wp_dual_check_mailer_transport'] ) );
			if ( Mailer::USER_TRANSPORT_INHERIT === $choice || '' === $choice ) {
				delete_user_meta( $user_id, MetaKeys::MAIL_TRANSPORT );
			} elseif ( in_array( $choice, Mailer::valid_transport_ids(), true ) ) {
				update_user_meta( $user_id, MetaKeys::MAIL_TRANSPORT, $choice );
			}
			delete_user_meta( $user_id, 'wp2fa_mailer_transport' );
		}
	}
}
