<?php
/**
 * Optional JSON surface for verify/resend (same transients as the login form).
 * Enable under Settings → WP Dual Check (REST API checkbox).
 *
 * @package WPDualCheck
 */

namespace WPDualCheck;

final class Rest {

	public const NS = 'dual-check/v1';

	public static function register(): void {
		if ( ! Config::rest_enabled() ) {
			return;
		}

		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/verify',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'verify' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
					'code'  => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/resend',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'resend' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	public static function verify( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$code  = preg_replace( '/\D/', '', (string) $request->get_param( 'code' ) );

		$data = Pending_Session::get_by_token( $token );
		if ( null === $data ) {
			return new \WP_Error( 'wdc_invalid', __( 'Invalid or expired challenge.', 'wp-dual-check' ), array( 'status' => 400 ) );
		}

		if ( (int) $data['attempts'] >= Config::max_attempts() ) {
			Pending_Session::delete_by_token( $token );
			return new \WP_Error( 'wdc_locked', __( 'Too many attempts.', 'wp-dual-check' ), array( 'status' => 403 ) );
		}

		if ( ! Code::verify_plain_against_hash( $code, (string) $data['code_hash'] ) ) {
			$data['attempts'] = (int) $data['attempts'] + 1;
			Pending_Session::save( $token, $data );
			return new \WP_Error( 'wdc_bad_code', __( 'Invalid code.', 'wp-dual-check' ), array( 'status' => 400 ) );
		}

		$user = get_userdata( (int) $data['user_id'] );
		if ( ! $user instanceof \WP_User ) {
			Pending_Session::delete_by_token( $token );
			return new \WP_Error( 'wdc_user', __( 'User not found.', 'wp-dual-check' ), array( 'status' => 400 ) );
		}

		Pending_Session::delete_by_token( $token );

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, (bool) $data['remember'], is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );

		$redirect = isset( $data['redirect_to'] ) ? (string) $data['redirect_to'] : admin_url();

		return new \WP_REST_Response(
			array(
				'success'  => true,
				'redirect' => $redirect,
			),
			200
		);
	}

	public static function resend( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$data  = Pending_Session::get_by_token( $token );
		if ( null === $data ) {
			return new \WP_Error( 'wdc_invalid', __( 'Invalid or expired challenge.', 'wp-dual-check' ), array( 'status' => 400 ) );
		}

		$user_id = (int) $data['user_id'];
		if ( ! Pending_Session::is_resend_allowed( $user_id ) ) {
			return new \WP_Error( 'wdc_rate', __( 'Please wait before requesting another code.', 'wp-dual-check' ), array( 'status' => 429 ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'wdc_user', __( 'User not found.', 'wp-dual-check' ), array( 'status' => 400 ) );
		}

		$plain             = Code::generate_plain();
		$data['code_hash'] = Code::hash_plain( $plain );
		$data['attempts']  = 0;
		Pending_Session::save( $token, $data );
		Pending_Session::mark_resend( $user_id, Config::resend_cooldown_seconds() );

		$sent = Mailer::send_code_email( $user, $plain );
		if ( true !== $sent ) {
			return $sent instanceof \WP_Error ? $sent : new \WP_Error( 'wdc_send', __( 'Send failed.', 'wp-dual-check' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}
}
