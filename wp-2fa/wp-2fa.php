<?php
/**
 * Plugin Name:       WP 2FA
 * Description:       Email-based second step after a correct password (Symfony Mailer + transients).
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            SA
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-2fa
 *
 * @package WP2FA
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP2FA_VERSION', '1.0.0' );
define( 'WP2FA_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP2FA_URL', plugin_dir_url( __FILE__ ) );

$wp2fa_autoload = WP2FA_PATH . 'vendor/autoload.php';
if ( ! is_readable( $wp2fa_autoload ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'WP 2FA: run composer install in the wp-2fa plugin directory.', 'wp-2fa' );
			echo '</p></div>';
		}
	);
	return;
}

require_once $wp2fa_autoload;

WP2FA\Plugin::instance()->boot();
