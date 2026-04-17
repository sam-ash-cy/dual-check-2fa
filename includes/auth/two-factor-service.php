<?php

namespace WP_DUAL_CHECK\auth;

use WP_DUAL_CHECK\admin\Settings_Page;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Policy helpers for second-step login (delegates to saved settings).
 */
final class Two_Factor_Service {

	/**
	 * Whether the saved option requires second-step login for all users.
	 *
	 * @return bool
	 */
	public static function site_requires_2fa(): bool {
		return Settings_Page::is_2fa_required_for_all();
	}

	/**
	 * @deprecated Use {@see site_requires_2fa()} — kept for any old call sites.
	 * @return bool
	 */
	public static function site_requires_second_step(): bool {
		return self::site_requires_2fa();
	}
}
