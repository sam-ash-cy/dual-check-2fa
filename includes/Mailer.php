<?php
/**
 * Sends the one-time code via Symfony Mailer (not wp_mail).
 *
 * @package WPDualCheck
 */

namespace WPDualCheck;

use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class Mailer {

	public static function send_code_email( \WP_User $user, string $plain_code ): \WP_Error|true {
		$dsn = Config::mailer_dsn();
		if ( '' === $dsn ) {
			return new \WP_Error(
				'wdc_no_dsn',
				__( 'WP Dual Check is not configured: set WP_DUAL_CHECK_MAILER_DSN (or legacy WP2FA_MAILER_DSN).', 'wp-dual-check' )
			);
		}

		$to = self::resolve_recipient_email( $user );
		if ( '' === $to || ! is_email( $to ) ) {
			return new \WP_Error(
				'wdc_bad_email',
				__( 'Your account has no valid email address for the login code.', 'wp-dual-check' )
			);
		}

		$subject = (string) apply_filters(
			'wp_dual_check_email_subject',
			sprintf(
				/* translators: %s: site name */
				__( 'Your login code for %s', 'wp-dual-check' ),
				wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES )
			),
			$user
		);

		$body = (string) apply_filters(
			'wp_dual_check_email_body',
			sprintf(
				/* translators: 1: numeric code, 2: expiry minutes */
				__( "Your login code is: %1\$s\n\nIt expires in about %2\$d minutes.", 'wp-dual-check' ),
				$plain_code,
				(int) ceil( Config::code_ttl_seconds() / 60 )
			),
			$user,
			$plain_code
		);

		try {
			$transport = Transport::fromDsn( $dsn );
			$mailer    = new SymfonyMailer( $transport );

			$email = ( new Email() )
				->from( new Address( Config::from_email(), Config::from_name() ) )
				->to( new Address( $to ) )
				->subject( $subject )
				->text( $body );

			$mailer->send( $email );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WP Dual Check mail error: ' . $e->getMessage() );
			}
			return new \WP_Error(
				'wdc_send_failed',
				__( 'Could not send the login code. Try again later or contact an administrator.', 'wp-dual-check' )
			);
		}

		return true;
	}

	private static function resolve_recipient_email( \WP_User $user ): string {
		$override = get_user_meta( $user->ID, User_Settings::META_DELIVERY_EMAIL, true );
		if ( is_string( $override ) && '' !== $override && is_email( $override ) ) {
			return $override;
		}
		$legacy = get_user_meta( $user->ID, 'wp2fa_delivery_email', true );
		if ( is_string( $legacy ) && '' !== $legacy && is_email( $legacy ) ) {
			return $legacy;
		}
		return (string) $user->user_email;
	}
}
