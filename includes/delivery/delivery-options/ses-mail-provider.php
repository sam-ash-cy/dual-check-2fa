<?php

namespace DualCheck2FA\delivery;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Amazon SES v2 SendEmail over HTTPS with inline SigV4.
 */
final class Ses_Mail_Provider implements Mail_Provider_Interface {

	/** @var array<string, mixed> */
	private $settings;

	/**
	 * @param array<string, mixed> $settings Full plugin settings row.
	 */
	public function __construct(array $settings) {
		$this->settings = $settings;
	}

	/**
	 * @param array<int, string> $headers Optional headers (ignored for From; uses admin email).
	 */
	public function send(string $to, string $subject, string $body, array $headers = array()): bool {
		$to = sanitize_email($to);
		if (!is_email($to)) {
			return false;
		}

		$key_id = Mail_Credentials::constant_or_option(
			Mail_Credentials::SES_ACCESS_KEY_ID_CONSTANT,
			Mail_Credentials::SES_ACCESS_KEY_ID_OPTION,
			$this->settings
		);
		$secret = Mail_Credentials::constant_or_option(
			Mail_Credentials::SES_SECRET_ACCESS_KEY_CONSTANT,
			Mail_Credentials::SES_SECRET_ACCESS_KEY_OPTION,
			$this->settings
		);
		$region = Mail_Credentials::constant_or_option(
			Mail_Credentials::SES_REGION_CONSTANT,
			Mail_Credentials::SES_REGION_OPTION,
			$this->settings
		);
		$region = $region !== '' ? strtolower(preg_replace('/[^a-z0-9\-]/', '', $region)) : 'us-east-1';
		if ($key_id === '' || $secret === '') {
			return false;
		}

		$from_email = Mail_Credentials::default_from_email();
		if ($from_email === '') {
			return false;
		}

		$from_name = Mail_Credentials::default_from_name();

		$payload = array(
			'FromEmailAddress' => $from_name !== '' ? sprintf('%s <%s>', $from_name, $from_email) : $from_email,
			'Destination'      => array(
				'ToAddresses' => array( $to ),
			),
			'Content'          => array(
				'Simple' => array(
					'Subject' => array(
						'Data' => $subject,
					),
					'Body'    => array(
						'Html' => array(
							'Data' => $body,
						),
					),
				),
			),
		);

		$config_set = Mail_Credentials::constant_or_option(
			Mail_Credentials::SES_CONFIGURATION_SET_CONSTANT,
			Mail_Credentials::SES_CONFIGURATION_SET_OPTION,
			$this->settings
		);
		if ($config_set !== '') {
			$payload['ConfigurationSetName'] = $config_set;
		}

		$json     = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$url      = 'https://email.' . $region . '.amazonaws.com/v2/email/outbound-emails';
		$sign_hdr = Ses_Signer::signed_headers($region, $key_id, $secret, (string) $json, 'ses');

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => $sign_hdr,
				'body'    => $json,
			)
		);

		if (is_wp_error($response)) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code > 299) {
			return false;
		}

		return true;
	}
}
