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
	 * Creates a login challenge row and returns the plaintext code for emailing.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $context Short context string stored on the row (e.g. `wp-login`).
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
	 * Marks a token row consumed so the same code cannot succeed twice.
	 *
	 * @param int $row_id Primary key in the dual_check table.
	 * @return bool True if a row was updated; false if already consumed or missing.
	 */
	public static function consume_row(int $row_id): bool {
		return mark_dual_check_token_consumed($row_id);
	}
}
