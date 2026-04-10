<?php
/**
 * Starts email challenge and handles resend (session + mail + redirects).
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Auth\Challenge;

use WPDualCheck\Auth\Code\CodeGenerator;
use WPDualCheck\Auth\Code\CodeVerifier;
use WPDualCheck\Core\Config;
use WPDualCheck\Core\Logger;
use WPDualCheck\Email\Mailer;

/**
 * Orchestrates sending the login code and redirecting to the challenge screen.
 */
final class ChallengeManager {

	public const QUERY_VAR = 'wdc_challenge';

	/**
	 * Creates pending session, emails the code, and returns redirect parameters on success.
	 *
	 * @param \WP_User $user        Authenticated user.
	 * @param bool     $remember    Persistent login preference.
	 * @param string   $redirect_to Post-login redirect (validated).
	 * @return \WP_Error|array{token:string,redirect_to:string} Error if mail fails; otherwise token + redirect.
	 */
	public static function start_after_valid_password( \WP_User $user, bool $remember, string $redirect_to ): \WP_Error|array {
		$session_with_plain = PendingSession::start_pending_challenge( $user, $remember, $redirect_to );
		Logger::log( 'challenge_started', array( 'user_id' => (int) $user->ID ) );
		$mail_ok = Mailer::send_code_email( $user, $session_with_plain['plain_code'] );

		if ( true !== $mail_ok ) {
			PendingSession::delete_by_token( $session_with_plain['token'] );
			return $mail_ok instanceof \WP_Error ? $mail_ok : new \WP_Error( 'wdc_send_failed', __( 'Could not send login code.', 'wp-dual-check' ) );
		}

		return array(
			'token'       => $session_with_plain['token'],
			'redirect_to' => $redirect_to,
		);
	}

	/**
	 * Sends the browser to wp-login.php with the challenge token (and optional redirect_to).
	 */
	public static function redirect_to_challenge( string $token, string $redirect_to ): void {
		$login_url = add_query_arg(
			self::QUERY_VAR,
			rawurlencode( $token ),
			wp_login_url()
		);
		if ( '' !== $redirect_to ) {
			$login_url = add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $login_url );
		}
		wp_safe_redirect( $login_url );
		exit;
	}

	/**
	 * Handles POST from the “Resend code” form: rate limit, rotate code, re-send email.
	 */
	public static function handle_resend_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		if ( empty( $_POST['wdc_resend'] ) ) {
			return;
		}

		check_admin_referer( 'wdc_resend', 'wdc_resend_nonce' );

		$token = isset( $_POST['wdc_token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wdc_token'] ) ) : '';
		$session_payload = PendingSession::get_by_token( $token );
		if ( null === $session_payload ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$user_id = (int) $session_payload['user_id'];
		if ( ! PendingSession::is_resend_allowed( $user_id ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						self::QUERY_VAR => rawurlencode( $token ),
						'wdc_wait'      => '1',
					),
					wp_login_url()
				)
			);
			exit;
		}

		$wp_user = get_userdata( $user_id );
		if ( ! $wp_user instanceof \WP_User ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$new_plaintext_code           = CodeGenerator::generate_plain();
		$session_payload['code_hash'] = CodeVerifier::hash_plain( $new_plaintext_code );
		$session_payload['attempts']  = 0;
		PendingSession::save( $token, $session_payload );
		PendingSession::mark_resend( $user_id, Config::resend_cooldown_seconds() );

		$mail_ok = Mailer::send_code_email( $wp_user, $new_plaintext_code );
		if ( true !== $mail_ok ) {
			PendingSession::delete_by_token( $token );
			wp_die( esc_html( $mail_ok instanceof \WP_Error ? $mail_ok->get_error_message() : __( 'Could not resend code.', 'wp-dual-check' ) ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					self::QUERY_VAR => rawurlencode( $token ),
					'wdc_resent'    => '1',
				),
				wp_login_url()
			)
		);
		exit;
	}
}
