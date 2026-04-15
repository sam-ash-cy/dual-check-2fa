<?php

namespace WP_DUAL_CHECK\delivery;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Sends mail through WordPress {@see wp_mail()}.
 */
final class Wp_Mail_Provider implements Mail_Provider_Interface {

	public function send(string $to, string $subject, string $body, array $headers = array()): bool {
		return wp_mail($to, $subject, $body, $headers);
	}
}
