<?php

namespace WP_DUAL_CHECK\auth;

use const WP_DUAL_CHECK\db\DUAL_CHECK_TOKEN_TYPE_LOGIN;
use function WP_DUAL_CHECK\db\verify_dual_check_token;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Thin wrapper around DB verification so login code does not call SQL helpers directly.
 */
final class Code_Validator {

	/**
	 * Verify the login code.
	 * @param string $plain The plain text code from the user.
	 * @param int $user_id The ID of the user.
	 * @return array<string, mixed>|false Matching row from dual_check.
	 */
	public static function verify_login(string $plain, int $user_id) {
		return verify_dual_check_token($user_id, DUAL_CHECK_TOKEN_TYPE_LOGIN, $plain);
	}
}
