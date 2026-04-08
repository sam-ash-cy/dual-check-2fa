<?php
/**
 * Loads config and registers hooks in a fixed order (see boot).
 *
 * @package WPDualCheck
 */

namespace WPDualCheck;

final class Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		Config::maybe_load_dotenv();

		Admin_Settings::register();
		User_Settings::register();
		Login_Intercept::register();
	}
}
