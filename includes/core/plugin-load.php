<?php

namespace DualCheck2FA\core;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Bootstrap: loads PHP dependencies and registers hooks.
 */
class PluginLoad {

	/**
	 * Loads includes and registers WordPress hooks.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * Loads all plugin PHP files (auth, admin, integrations, etc.).
	 *
	 * @return void
	 */
	private function load_dependencies(): void {
		require_once DUAL_CHECK_2FA_PATH . 'includes/core/security.php';
		require_once DUAL_CHECK_2FA_PATH . 'includes/core/plugin.php';
		require_once DUAL_CHECK_2FA_PATH . 'includes/core/loader.php';
		require_once DUAL_CHECK_2FA_PATH . 'includes/delivery/delivery-options/mail-provider-interface.php';
		require_once DUAL_CHECK_2FA_PATH . 'includes/delivery/delivery-options/wp-mail-provider.php';
		require_once DUAL_CHECK_2FA_PATH . 'includes/delivery/registry.php';
		require_once DUAL_CHECK_2FA_PATH . 'includes/auth/code-generator.php';
		require_once DUAL_CHECK_2FA_PATH . 'includes/auth/code-validator.php';
		require_once DUAL_CHECK_2FA_PATH . 'includes/auth/token-store.php';
		require_once DUAL_CHECK_2FA_PATH . 'includes/logging/logger.php';
		require_once DUAL_CHECK_2FA_PATH . 'includes/auth/two-factor-service.php';
		require_once DUAL_CHECK_2FA_PATH . 'includes/admin/settings-interface.php';
		require_once DUAL_CHECK_2FA_PATH . 'includes/admin/settings-save-handler.php';
		require_once DUAL_CHECK_2FA_PATH . 'includes/admin/settings-page.php';
		require_once DUAL_CHECK_2FA_PATH . 'includes/auth/code-step-rate-limit.php';
		require_once DUAL_CHECK_2FA_PATH . 'includes/auth/code-request-cooldown.php';
		require_once DUAL_CHECK_2FA_PATH . 'includes/admin/permissions-settings-page.php';
		require_once DUAL_CHECK_2FA_PATH . 'includes/admin/email-settings-page.php';
		require_once DUAL_CHECK_2FA_PATH . 'includes/admin/user-profile-settings.php';
		require_once DUAL_CHECK_2FA_PATH . 'includes/email/login-email-builder.php';
		require_once DUAL_CHECK_2FA_PATH . 'includes/integrations/login-flow.php';
	}

	/**
	 * Registers the login second step and admin screens when in wp-admin.
	 *
	 * @return void
	 */
	private function init_hooks(): void {

		(new \DualCheck2FA\integrations\LoginFlow())->register();

		(new \DualCheck2FA\admin\Settings_Save_Handler())->register();

		if (is_admin()) {
			(new \DualCheck2FA\admin\Settings_Page())->register();
			(new \DualCheck2FA\admin\Permissions_Settings_Page())->register();
			(new \DualCheck2FA\admin\Email_Settings_Page())->register();
			(new \DualCheck2FA\admin\User_Profile_Settings())->register();
		}
	}
}
