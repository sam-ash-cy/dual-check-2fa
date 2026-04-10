<?php
/**
 * POST verify: nonce, session, attempts, code verification, login completion.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Auth\Challenge;

use WPDualCheck\Auth\Code\CodeVerifier;
use WPDualCheck\Core\Config;
use WPDualCheck\Core\Logger;

/**
 * Validates the submitted code and completes WordPress authentication on success.
 */
final class ChallengeValidator {

	/**
	 * Processes the verify form: checks code, updates attempts, sets cookies, redirects.
	 */
	public static function handle_verify_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		if ( empty( $_POST['wdc_verify'] ) ) {
			return;
		}

		check_admin_referer( 'wdc_verify', 'wdc_nonce' );

		$token = isset( $_POST['wdc_token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wdc_token'] ) ) : '';
		$submitted_code_digits = isset( $_POST['wdc_code'] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST['wdc_code'] ) ) : '';

		$session_payload = PendingSession::get_by_token( $token );
		if ( null === $session_payload ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		if ( (int) $session_payload['attempts'] >= Config::max_attempts() ) {
			Logger::log( 'verify_locked', array( 'user_id' => (int) $session_payload['user_id'] ) );
			PendingSession::delete_by_token( $token );
			wp_die( esc_html__( 'Too many attempts. Log in again.', 'wp-dual-check' ), 403 );
		}

		if ( ! CodeVerifier::verify_plain_against_hash( $submitted_code_digits, (string) $session_payload['code_hash'] ) ) {
			Logger::log( 'verify_failed', array( 'user_id' => (int) $session_payload['user_id'], 'attempts' => (int) $session_payload['attempts'] + 1 ) );
			$session_payload['attempts'] = (int) $session_payload['attempts'] + 1;
			PendingSession::save( $token, $session_payload );
			wp_safe_redirect(
				add_query_arg(
					array(
						ChallengeManager::QUERY_VAR => rawurlencode( $token ),
						'wdc_error'                   => '1',
					),
					wp_login_url()
				)
			);
			exit;
		}

		$wp_user = get_userdata( (int) $session_payload['user_id'] );
		if ( ! $wp_user instanceof \WP_User ) {
			PendingSession::delete_by_token( $token );
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		PendingSession::delete_by_token( $token );

		Logger::log( 'verify_succeeded', array( 'user_id' => (int) $wp_user->ID ) );

		wp_set_auth_cookie( $wp_user->ID, (bool) $session_payload['remember'], is_ssl() );
		wp_set_current_user( $wp_user->ID );
		do_action( 'wp_login', $wp_user->user_login, $wp_user );

		$redirect_url = isset( $session_payload['redirect_to'] ) ? (string) $session_payload['redirect_to'] : '';
		if ( '' === $redirect_url ) {
			$redirect_url = admin_url();
		}
		wp_safe_redirect( $redirect_url );
		exit;
	}
}
