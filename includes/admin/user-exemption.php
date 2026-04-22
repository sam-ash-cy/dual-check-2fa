<?php

namespace DualCheck2FA\admin;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Per-user 2FA exemption (user meta + setting + filter).
 */
final class User_Exemption {

	public const META_KEY = 'dual_check_2fa_exempt';

	/**
	 * @return bool
	 */
	public static function feature_enabled(): bool {
		$opts = wp_parse_args(get_option(Settings_Page::OPTION_NAME, array()), Settings_Page::defaults());

		return (bool) apply_filters(
			'dual_check_2fa_per_user_exemption_enabled',
			!empty($opts['allow_user_exempt'])
		);
	}

	/**
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public static function user_is_exempt(int $user_id): bool {
		if ($user_id <= 0) {
			return false;
		}

		$user = get_userdata($user_id);
		if (!$user instanceof \WP_User) {
			return false;
		}

		$from_meta = get_user_meta($user_id, self::META_KEY, true) === '1';

		/**
		 * Filters whether this user is exempt from the email second factor.
		 *
		 * @param bool     $exempt Whether exempt (meta or prior filter).
		 * @param \WP_User $user   User object.
		 */
		return (bool) apply_filters('dual_check_2fa_user_is_exempt', $from_meta, $user);
	}

	/**
	 * @param bool     $skip Current skip flag.
	 * @param \WP_User $user Authenticated user.
	 * @return bool
	 */
	public static function filter_skip_second_factor(bool $skip, \WP_User $user): bool {
		if ($skip) {
			return true;
		}
		if (!self::feature_enabled()) {
			return $skip;
		}

		return self::user_is_exempt((int) $user->ID);
	}
}
