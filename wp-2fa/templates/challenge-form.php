<?php
/**
 * Shown on wp-login.php when ?wp2fa_challenge= is present (variables: $token, $redirect_field).
 *
 * @package WP2FA
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="wp2fa-challenge" class="wp2fa-challenge">
	<form name="wp2faverify" id="wp2faverify" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>" method="post">
		<input type="hidden" name="wp2fa_verify" value="1" />
		<input type="hidden" name="wp2fa_token" value="<?php echo esc_attr( $token ); ?>" />
		<?php echo $redirect_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built in Login_Intercept ?>
		<?php wp_nonce_field( 'wp2fa_verify', 'wp2fa_nonce' ); ?>
		<p>
			<label for="wp2fa_code"><?php esc_html_e( 'Email login code', 'wp-2fa' ); ?></label>
			<input type="text" name="wp2fa_code" id="wp2fa_code" class="input" value="" size="10" autocomplete="one-time-code" inputmode="numeric" />
		</p>
		<p class="submit">
			<input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Verify', 'wp-2fa' ); ?>" />
		</p>
	</form>
	<form name="wp2faresend" id="wp2faresend" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>" method="post" style="margin-top:1em;">
		<input type="hidden" name="wp2fa_resend" value="1" />
		<input type="hidden" name="wp2fa_token" value="<?php echo esc_attr( $token ); ?>" />
		<?php echo $redirect_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php wp_nonce_field( 'wp2fa_resend', 'wp2fa_resend_nonce' ); ?>
		<p class="submit">
			<input type="submit" class="button" value="<?php esc_attr_e( 'Resend code', 'wp-2fa' ); ?>" />
		</p>
	</form>
</div>
