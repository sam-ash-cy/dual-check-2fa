<?php
/**
 * Plugin Name:       WP Dual Check
 * Description:       Email-based second step after a correct password (configurable mail transport + transients).
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            SA
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-dual-check
 *
 * @package WPDualCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_DUAL_CHECK_VERSION', '1.0.0' );
define( 'WP_DUAL_CHECK_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_DUAL_CHECK_URL', plugin_dir_url( __FILE__ ) );

$wdc_autoload = WP_DUAL_CHECK_PATH . 'vendor/autoload.php';
if ( ! is_readable( $wdc_autoload ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'WP Dual Check: install dependencies first (run composer install in the plugin directory, or use a release package that includes the vendor folder).', 'wp-dual-check' );
			echo '</p></div>';
		}
	);
	return;
}

require_once $wdc_autoload;

WPDualCheck\Plugin::instance()->boot();
