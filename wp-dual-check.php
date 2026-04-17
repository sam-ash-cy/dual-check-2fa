<?php
/**
 * Plugin Name: WP Dual Check
 * Description: 2FA Plugin, simple and easy to use.
 * Version: 1.0.1
 * Author: Samuel Ashman
 * Text Domain: wp-dual-check
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

define('WP_DUAL_CHECK_FILE', __FILE__);
define('WP_DUAL_CHECK_PATH', plugin_dir_path(__FILE__));

require_once WP_DUAL_CHECK_PATH . 'includes/db/dual-check-database.php';

require_once WP_DUAL_CHECK_PATH . 'includes/core/plugin-load.php';

/**
 * Loads the plugin after WordPress and other plugins are available.
 *
 * @return void
 */
function wp_dual_check_init() {
	new \WP_DUAL_CHECK\core\PluginLoad();
}
add_action('plugins_loaded', 'wp_dual_check_init');