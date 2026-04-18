<?php

namespace WP_DUAL_CHECK\delivery;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * SendGrid v3 Mail Send API.
 */
final class Sendgrid_Mail_Provider implements Mail_Provider_Interface {

	/** @var array<string, mixed> */
	private $settings;

	/**
	 * @param array<string, mixed> $settings Full plugin settings row.
	 */
	public function __construct(array $settings) {
		$this->settings = $settings;
	}

	/**
	 * @param array<int, string> $headers Optional headers (Content-Type ignored; API sends HTML).
	 */
	public function send(string $to, string $subject, string $body, array $headers = array()): bool {
		$to = sanitize_email($to);
		if (!is_email($to)) {
			return false;
		}

		$key = Mail_Credentials::constant_or_option(
			Mail_Credentials::SENDGRID_KEY_CONSTANT,
			Mail_Credentials::SENDGRID_KEY_OPTION,
			$this->settings
		);
		if ($key === '') {
			return false;
		}

		$from_email = Mail_Credentials::default_from_email();
		if ($from_email === '') {
			return false;
		}

		$from_name = Mail_Credentials::default_from_name();

		$payload = array(
			'personalizations' => array(
				array(
					'to' => array( array( 'email' => $to ) ),
				),
			),
			'from'             => array(
				'email' => $from_email,
				'name'  => $from_name,
			),
			'subject'          => $subject,
			'content'          => array(
				array(
					'type'  => 'text/html',
					'value' => $body,
				),
			),
		);

		$response = wp_remote_post(
			'https://api.sendgrid.com/v3/mail/send',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
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
