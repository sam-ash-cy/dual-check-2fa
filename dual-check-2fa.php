<?php
/**
 * Plugin Name: Dual Check 2FA
 * Description: Customisable, easy to use, and secure 2FA plugin for WordPress.
 * Version: 1.0.0
 * Author: Samuel Ashman
 * Text Domain: dual-check-2fa
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

define('DUAL_CHECK_2FA_FILE', __FILE__);
define('DUAL_CHECK_2FA_PATH', plugin_dir_path(__FILE__));

$dual_check_2fa_autoload = DUAL_CHECK_2FA_PATH . 'vendor/autoload.php';
if (!is_readable($dual_check_2fa_autoload)) {
	wp_die(
		wp_kses_post(
			__('Dual Check 2FA is missing Composer dependencies. Run <code>composer install</code> in the plugin directory, or reinstall the plugin from a release archive that includes the <code>vendor/</code> folder.', 'dual-check-2fa')
		),
		esc_html__('Dual Check 2FA', 'dual-check-2fa'),
		array('response' => 503)
	);
}

require_once $dual_check_2fa_autoload;

register_deactivation_hook(
	DUAL_CHECK_2FA_FILE,
	static function (): void {
		\DualCheck2FA\cron\Token_Gc::unschedule();
	}
);

require_once DUAL_CHECK_2FA_PATH . 'includes/core/plugin-load.php';

/**
 * Loads the plugin after WordPress and other plugins are available.
 *
 * @return void
 */
function dual_check_2fa_init() {
	new \DualCheck2FA\core\PluginLoad();
}
add_action('plugins_loaded', 'dual_check_2fa_init');
