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
	 * @param array<int, string> $headers Optional mail headers.
	 */
	public function send(string $to, string $subject, string $body, array $headers = array()): bool;
}
