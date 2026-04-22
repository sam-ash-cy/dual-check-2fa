<?php
/**
 * Plugin Name: Dual Check 2FA
 * Description: Email-based second step after password on the standard WordPress login.
 * Version: 1.0.5
 * Author: Samuel Ashman
 * Text Domain: dual-check-2fa
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

define('DUAL_CHECK_2FA_FILE', __FILE__);
define('DUAL_CHECK_2FA_PATH', plugin_dir_path(__FILE__));

require_once DUAL_CHECK_2FA_PATH . 'includes/db/dual-check-database.php';

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