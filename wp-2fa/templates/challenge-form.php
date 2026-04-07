<?php
/**
 * Shown on wp-login.php when ?wdc_challenge= is present (variables: $token, $redirect_field).
 *
 * @package WPDualCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="wdc-challenge" class="wdc-challenge">
	<form name="wdcverify" id="wdcverify" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>" method="post">
		<input type="hidden" name="wdc_verify" value="1" />
		<input type="hidden" name="wdc_token" value="<?php echo esc_attr( $token ); ?>" />
		<?php echo $redirect_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built in Login_Intercept ?>
		<?php wp_nonce_field( 'wdc_verify', 'wdc_nonce' ); ?>
		<p>
			<label for="wdc_code"><?php esc_html_e( 'Email login code', 'wp-dual-check' ); ?></label>
			<input type="text" name="wdc_code" id="wdc_code" class="input" value="" size="10" autocomplete="one-time-code" inputmode="numeric" />
		</p>
		<p class="submit">
			<input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Verify', 'wp-dual-check' ); ?>" />
		</p>
	</form>
	<form name="wdcresend" id="wdcresend" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>" method="post" style="margin-top:1em;">
		<input type="hidden" name="wdc_resend" value="1" />
		<input type="hidden" name="wdc_token" value="<?php echo esc_attr( $token ); ?>" />
		<?php echo $redirect_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php wp_nonce_field( 'wdc_resend', 'wdc_resend_nonce' ); ?>
		<p class="submit">
			<input type="submit" class="button" value="<?php esc_attr_e( 'Resend code', 'wp-dual-check' ); ?>" />
		</p>
	</form>
</div>
