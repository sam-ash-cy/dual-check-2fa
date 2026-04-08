<?php
/**
 * Runs after password validation: starts email challenge and redirects, or completes login after code.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck;

final class Login_Intercept {

	public const QUERY_VAR = 'wdc_challenge';

	public static function register(): void {
		add_filter( 'wp_authenticate_user', array( self::class, 'maybe_start_challenge' ), 30, 2 );
		add_action( 'login_init', array( self::class, 'handle_verify_post' ), 1 );
		add_action( 'login_init', array( self::class, 'handle_resend_post' ), 2 );
		add_action( 'login_head', array( self::class, 'maybe_hide_default_form' ) );
		add_filter( 'login_message', array( self::class, 'maybe_show_challenge_ui' ) );
	}

	/**
	 * Password is already valid here; we must not set auth cookies until the second step succeeds.
	 *
	 * @param \WP_User|\WP_Error $user
	 * @param string             $password
	 * @return \WP_User|\WP_Error
	 */
	public static function maybe_start_challenge( $user, $password ) {
		if ( is_wp_error( $user ) || ! ( $user instanceof \WP_User ) ) {
			return $user;
		}

		if ( empty( Admin_Settings::merged()['require_all_logins'] ) ) {
			return $user;
		}

		$remember    = ! empty( $_POST['rememberme'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? wp_unslash( (string) $_REQUEST['redirect_to'] ) : '';
		$redirect_to = wp_validate_redirect( $redirect_to, '' );

		$session = Pending_Session::start_pending_challenge( $user, $remember, $redirect_to );
		Logger::log( 'challenge_started', array( 'user_id' => (int) $user->ID ) );
		$sent = Mailer::send_code_email( $user, $session['plain_code'] );

		if ( true !== $sent ) {
			Pending_Session::delete_by_token( $session['token'] );
			return $sent instanceof \WP_Error ? $sent : new \WP_Error( 'wdc_send_failed', __( 'Could not send login code.', 'wp-dual-check' ) );
		}

		$url = add_query_arg(
			self::QUERY_VAR,
			rawurlencode( $session['token'] ),
			wp_login_url()
		);
		if ( '' !== $redirect_to ) {
			$url = add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $url );
		}

		wp_safe_redirect( $url );
		exit;
	}

	public static function handle_verify_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		if ( empty( $_POST['wdc_verify'] ) ) {
			return;
		}

		check_admin_referer( 'wdc_verify', 'wdc_nonce' );

		$token = isset( $_POST['wdc_token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wdc_token'] ) ) : '';
		$code  = isset( $_POST['wdc_code'] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST['wdc_code'] ) ) : '';

		$data = Pending_Session::get_by_token( $token );
		if ( null === $data ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		if ( (int) $data['attempts'] >= Config::max_attempts() ) {
			Logger::log( 'verify_locked', array( 'user_id' => (int) $data['user_id'] ) );
			Pending_Session::delete_by_token( $token );
			wp_die( esc_html__( 'Too many attempts. Log in again.', 'wp-dual-check' ), 403 );
		}

		if ( ! Code::verify_plain_against_hash( $code, (string) $data['code_hash'] ) ) {
			Logger::log( 'verify_failed', array( 'user_id' => (int) $data['user_id'], 'attempts' => (int) $data['attempts'] + 1 ) );
			$data['attempts'] = (int) $data['attempts'] + 1;
			Pending_Session::save( $token, $data );
			wp_safe_redirect(
				add_query_arg(
					array(
						self::QUERY_VAR => rawurlencode( $token ),
						'wdc_error'     => '1',
					),
					wp_login_url()
				)
			);
			exit;
		}

		$user = get_userdata( (int) $data['user_id'] );
		if ( ! $user instanceof \WP_User ) {
			Pending_Session::delete_by_token( $token );
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		Pending_Session::delete_by_token( $token );

		Logger::log( 'verify_succeeded', array( 'user_id' => (int) $user->ID ) );

		wp_set_auth_cookie( $user->ID, (bool) $data['remember'], is_ssl() );
		wp_set_current_user( $user->ID );
		do_action( 'wp_login', $user->user_login, $user );

		$target = isset( $data['redirect_to'] ) ? (string) $data['redirect_to'] : '';
		if ( '' === $target ) {
			$target = admin_url();
		}
		wp_safe_redirect( $target );
		exit;
	}

	public static function handle_resend_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		if ( empty( $_POST['wdc_resend'] ) ) {
			return;
		}

		check_admin_referer( 'wdc_resend', 'wdc_resend_nonce' );

		$token = isset( $_POST['wdc_token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wdc_token'] ) ) : '';
		$data  = Pending_Session::get_by_token( $token );
		if ( null === $data ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$user_id = (int) $data['user_id'];
		if ( ! Pending_Session::is_resend_allowed( $user_id ) ) {
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

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$plain             = Code::generate_plain();
		$data['code_hash'] = Code::hash_plain( $plain );
		$data['attempts']  = 0;
		Pending_Session::save( $token, $data );
		Pending_Session::mark_resend( $user_id, Config::resend_cooldown_seconds() );

		$sent = Mailer::send_code_email( $user, $plain );
		if ( true !== $sent ) {
			Pending_Session::delete_by_token( $token );
			wp_die( esc_html( $sent instanceof \WP_Error ? $sent->get_error_message() : __( 'Could not resend code.', 'wp-dual-check' ) ) );
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

	public static function maybe_hide_default_form(): void {
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		echo '<style id="wdc-hide-login">#loginform{display:none!important;}#wdc-challenge{margin-top:1em;}</style>';
	}

	public static function maybe_show_challenge_ui( string $message ): string {
		$token = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) ) : '';
		if ( '' === $token ) {
			return $message;
		}

		$data = Pending_Session::get_by_token( $token );
		if ( null === $data ) {
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
			$rt = wp_validate_redirect( wp_unslash( (string) $_GET['redirect_to'] ), '' );
			if ( '' !== $rt ) {
				$redirect_field = '<input type="hidden" name="redirect_to" value="' . esc_attr( $rt ) . '" />';
			}
		}
		include WP_DUAL_CHECK_PATH . 'templates/challenge-form.php';
		$message .= ob_get_clean();

		return $message;
	}
}
