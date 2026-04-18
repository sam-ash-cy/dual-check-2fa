<?php

namespace WP_DUAL_CHECK\delivery;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Postmark outbound message API.
 */
final class Postmark_Mail_Provider implements Mail_Provider_Interface {

	/** @var array<string, mixed> */
	private $settings;

	/**
	 * @param array<string, mixed> $settings Full plugin settings row.
	 */
	public function __construct(array $settings) {
		$this->settings = $settings;
	}

	/**
	 * @param array<int, string> $headers Unused for API body (HTML set explicitly).
	 */
	public function send(string $to, string $subject, string $body, array $headers = array()): bool {
		$to = sanitize_email($to);
		if (!is_email($to)) {
			return false;
		}

		$token = Mail_Credentials::constant_or_option(
			Mail_Credentials::POSTMARK_TOKEN_CONSTANT,
			Mail_Credentials::POSTMARK_TOKEN_OPTION,
			$this->settings
		);
		if ($token === '') {
			return false;
		}

		$from_email = Mail_Credentials::default_from_email();
		if ($from_email === '') {
			return false;
		}

		$from_name = Mail_Credentials::default_from_name();
		$from      = sprintf('%s <%s>', $from_name, $from_email);

		$payload = array(
			'From'     => $from,
			'To'       => $to,
			'Subject'  => $subject,
			'HtmlBody' => $body,
		);

		$response = wp_remote_post(
			'https://api.postmarkapp.com/email',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'                  => 'application/json',
					'Content-Type'            => 'application/json',
					'X-Postmark-Server-Token' => $token,
				),
				'body'    => wp_json_encode($payload),
			)
		);

		if (is_wp_error($response)) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code($response);

		return $code >= 200 && $code < 300;
	}
}
