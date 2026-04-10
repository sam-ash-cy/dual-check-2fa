<?php
/**
 * User meta keys for WP Dual Check.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\User;

/**
 * Canonical user_meta keys (avoid scattering string literals).
 */
final class MetaKeys {

	public const DELIVERY_EMAIL = 'wp_dual_check_delivery_email';
	public const MAIL_TRANSPORT = 'wp_dual_check_mailer_transport';
}
