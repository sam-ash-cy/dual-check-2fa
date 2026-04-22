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
		$this->init_hooks();
	}

	/**
	 * Registers the login second step and admin screens when in wp-admin.
	 *
	 * @return void
	 */
	private function init_hooks(): void {

		\DualCheck2FA\cron\Token_Gc::register();
		\DualCheck2FA\logging\Event_Recorder::register();

		(new \DualCheck2FA\integrations\LoginFlow())->register();

		(new \DualCheck2FA\admin\Settings_Save_Handler())->register();

		if (is_admin()) {
			(new \DualCheck2FA\admin\Settings_Page())->register();
			(new \DualCheck2FA\admin\Permissions_Settings_Page())->register();
			(new \DualCheck2FA\admin\Email_Settings_Page())->register();
			(new \DualCheck2FA\admin\User_Profile_Settings())->register();
			(new \DualCheck2FA\admin\Login_Activity_Page())->register();
		}
	}
}
