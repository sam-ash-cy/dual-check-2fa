<?php

namespace WP_DUAL_CHECK\auth;

use const WP_DUAL_CHECK\db\DUAL_CHECK_TOKEN_TYPE_LOGIN;
use function WP_DUAL_CHECK\db\add_dual_check_token;
use function WP_DUAL_CHECK\db\mark_dual_check_token_consumed;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Issue / retire login tokens (delegates to db layer).
 */
final class Token_Store {

	/**
	 * Issue a login challenge.
	 * @param int $user_id The ID of the user.
	 * @param string $context The context of the challenge.
	 * @return array{plain: string, id: int}|false
	 */
	public static function issue_login_challenge(int $user_id, string $context = '') {
		return add_dual_check_token(
			$user_id,
			DUAL_CHECK_TOKEN_TYPE_LOGIN,
			$context
		);
	}

	/**
	 * Consume a login token.
	 * @param int $row_id The ID of the row to consume.
	 * @return bool True if the row was consumed, false otherwise.
	 */
	public static function consume_row(int $row_id): bool {
		return mark_dual_check_token_consumed($row_id);
	}
}
