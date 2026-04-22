<?php

namespace DualCheck2FA\delivery;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Mailgun HTTP API (US or EU region).
 */
final class Mailgun_Mail_Provider implements Mail_Provider_Interface {

	/** @var array<string, mixed> */
	private $settings;

	/**
	 * @param array<string, mixed> $settings Full plugin settings row.
	 */
	public function __construct(array $settings) {
		$this->settings = $settings;
	}

	/**
	 * @param array<int, string> $headers Unused.
	 */
	public function send(string $to, string $subject, string $body, array $headers = array()): bool {
		$to = sanitize_email($to);
		if (!is_email($to)) {
			return false;
		}

		$key = Mail_Credentials::constant_or_option(
			Mail_Credentials::MAILGUN_KEY_CONSTANT,
			Mail_Credentials::MAILGUN_KEY_OPTION,
			$this->settings
		);
		if ($key === '') {
			return false;
		}

		$domain = Mail_Credentials::constant_or_option(
			Mail_Credentials::MAILGUN_DOMAIN_CONSTANT,
			Mail_Credentials::MAILGUN_DOMAIN_OPTION,
			$this->settings
		);
		$domain = strtolower(trim($domain));
		$domain = preg_replace('#^https?://#', '', $domain);
		$domain = trim((string) $domain, '/');
		if ($domain === '') {
			return false;
		}

		$region = sanitize_key(Mail_Credentials::constant_or_option(
			Mail_Credentials::MAILGUN_REGION_CONSTANT,
			Mail_Credentials::MAILGUN_REGION_OPTION,
			$this->settings
		));
		$base = $region === 'eu' ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';

		$from_email = Mail_Credentials::default_from_email();
		if ($from_email === '') {
			return false;
		}

		$from_name = Mail_Credentials::default_from_name();
		$from      = sprintf('%s <%s>', $from_name, $from_email);

		$url      = $base . '/v3/' . rawurlencode($domain) . '/messages';
		$form_body = array(
			'from'    => $from,
			'to'      => $to,
			'subject' => $subject,
			'html'    => $body,
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode('api:' . $key),
				),
				'body'    => $form_body,
			)
		);

		if (is_wp_error($response)) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code($response);

		return $code >= 200 && $code < 300;
	}
}
