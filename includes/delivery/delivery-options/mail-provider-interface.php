<?php

namespace WP_DUAL_CHECK\delivery;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * One delivery channel (email today; SMS etc. can implement the same contract).
 */
interface Mail_Provider_Interface {

	/**
	 * Sends one message through the provider (e.g. `wp_mail()`).
	 *
	 * @param string             $to      Recipient address.
	 * @param string             $subject Message subject.
	 * @param string             $body    HTML or plain body.
	 * @param array<int, string> $headers Optional mail headers.
	 * @return bool True on success.
	 */
	public function send(string $to, string $subject, string $body, array $headers = array()): bool;
}
