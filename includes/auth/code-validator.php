<?php

namespace DualCheck2FA\auth;

use const DualCheck2FA\db\DUAL_CHECK_TOKEN_TYPE_LOGIN;
use function DualCheck2FA\db\verify_dual_check_token_by_row;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Thin wrapper around DB verification so login code does not call SQL helpers directly.
 */
final class Code_Validator {

	/** Reject absurd payloads before hashing (admin max code length is 32). */
	private const PLAIN_MAX_LEN = 128;

	/**
	 * Verifies the code for the issued challenge row only (no user-wide token scan).
	 *
	 * @param string $plain              Submitted code.
	 * @param int    $user_id            Account id from the server-side session.
	 * @param int    $challenge_row_id   Token row id from {@see add_dual_check_token}.
	 * @return array<string, mixed>|false Token row on success; false otherwise.
	 */
	public static function verify_login_challenge(string $plain, int $user_id, int $challenge_row_id) {
		if ($challenge_row_id <= 0 || $user_id <= 0) {
			return false;
		}

		$plain = trim($plain);
		if ($plain === '' || strlen($plain) > self::PLAIN_MAX_LEN) {
			return false;
		}

		return verify_dual_check_token_by_row($challenge_row_id, $user_id, DUAL_CHECK_TOKEN_TYPE_LOGIN, $plain);
	}
}
