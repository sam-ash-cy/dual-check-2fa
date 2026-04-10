<?php
/**
 * Runs after password validation: starts email challenge and redirects, or completes login after code.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Auth;

use WPDualCheck\Admin\Settings\SettingsRepository;
use WPDualCheck\Auth\Challenge\ChallengeManager;
use WPDualCheck\Auth\Challenge\ChallengeValidator;
use WPDualCheck\Auth\Challenge\PendingSession;

/**
 * Hooks into WordPress login to enforce the second-factor email step when enabled.
 */
final class LoginInterceptor {

	public const QUERY_VAR = ChallengeManager::QUERY_VAR;

	/**
	 * Registers filters and actions for challenge start, verify/resend, and login UI.
	 */
	public static function register(): void {
		add_filter( 'wp_authenticate_user', array( self::class, 'maybe_start_challenge' ), 30, 2 );
		add_action( 'login_init', array( ChallengeValidator::class, 'handle_verify_post' ), 1 );
		add_action( 'login_init', array( ChallengeManager::class, 'handle_resend_post' ), 2 );
		add_action( 'login_head', array( self::class, 'maybe_hide_default_form' ) );
		add_filter( 'login_message', array( self::class, 'maybe_show_challenge_ui' ) );
	}

	/**
	 * Password is already valid here; we must not set auth cookies until the second step succeeds.
	 *
	 * @param \WP_User|\WP_Error $user     Authenticated user candidate or error.
	 * @param string            $password Plain password (unused after primary auth).
	 * @return \WP_User|\WP_Error User, error from challenge start, or redirect/exit.
	 */
	public static function maybe_start_challenge( $user, $password ) {
		if ( is_wp_error( $user ) || ! ( $user instanceof \WP_User ) ) {
			return $user;
		}

		if ( empty( SettingsRepository::merged()['require_all_logins'] ) ) {
			return $user;
		}

		$remember_me    = ! empty( $_POST['rememberme'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$redirect_to    = isset( $_REQUEST['redirect_to'] ) ? wp_unslash( (string) $_REQUEST['redirect_to'] ) : '';
		$redirect_to    = wp_validate_redirect( $redirect_to, '' );

		$challenge_result = ChallengeManager::start_after_valid_password( $user, $remember_me, $redirect_to );
		if ( is_wp_error( $challenge_result ) ) {
			return $challenge_result;
		}

		ChallengeManager::redirect_to_challenge( $challenge_result['token'], $challenge_result['redirect_to'] );
		exit;
	}

	/**
	 * Hides the default username/password form when the challenge query var is present.
	 */
	public static function maybe_hide_default_form(): void {
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		echo '<style id="wdc-hide-login">#loginform{display:none!important;}#wdc-challenge{margin-top:1em;}</style>';
	}

	/**
	 * Appends the code entry form (and notices) to the login screen when a valid token is in the URL.
	 *
	 * @param string $message Existing login messages from WordPress.
	 * @return string Message HTML possibly extended with challenge UI.
	 */
	public static function maybe_show_challenge_ui( string $message ): string {
		$token = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) ) : '';
		if ( '' === $token ) {
			return $message;
		}

		$session_payload = PendingSession::get_by_token( $token );
		if ( null === $session_payload ) {
			return $message . '<p class="message">' . esc_html__( 'This login challenge expired. Please sign in again.', 'wp-dual-check' ) . '</p>';
		}

		if ( ! empty( $_GET['wdc_error'] ) ) {
			$message .= '<div id="login_error" class="notice notice-error"><p>' . esc_html__( 'Invalid code. Try again.', 'wp-dual-check' ) . '</p></div>';
		}
		if ( ! empty( $_GET['wdc_resent'] ) ) {
			$message .= '<p class="message">' . esc_html__( 'A new code was sent to your email.', 'wp-dual-check' ) . '</p>';
		}
		if ( ! empty( $_GET['wdc_wait'] ) ) {
			$message .= '<div id="login_error" class="notice notice-error"><p>' . esc_html__( 'Please wait a minute before resending.', 'wp-dual-check' ) . '</p></div>';
		}

		ob_start();
		$redirect_field = '';
		if ( ! empty( $_GET['redirect_to'] ) ) {
			$validated_redirect = wp_validate_redirect( wp_unslash( (string) $_GET['redirect_to'] ), '' );
			if ( '' !== $validated_redirect ) {
				$redirect_field = '<input type="hidden" name="redirect_to" value="' . esc_attr( $validated_redirect ) . '" />';
			}
		}
		include WP_DUAL_CHECK_PATH . 'templates/challenge-form.php';
		$message .= ob_get_clean();

		return $message;
	}
}
