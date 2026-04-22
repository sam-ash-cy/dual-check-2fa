<?php

namespace DualCheck2FA\delivery;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Sends mail through WordPress {@see wp_mail()}.
 */
final class Wp_Mail_Provider implements Mail_Provider_Interface {

	/**
	 * Delegates to WordPress {@see wp_mail()}.
	 *
	 * @param string             $to      Recipient.
	 * @param string             $subject Subject line.
	 * @param string             $body    Message body.
	 * @param array<int, string> $headers Optional headers.
	 * @return bool
	 */
	public function send(string $to, string $subject, string $body, array $headers = array()): bool {
		return wp_mail($to, $subject, $body, $headers);
	}
}
