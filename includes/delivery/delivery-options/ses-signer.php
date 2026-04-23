<?php

namespace DualCheck2FA\delivery;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Minimal AWS SigV4 signer for SES v2 HTTP API (no SDK).
 */
final class Ses_Signer {

	/**
	 * @param string $region       AWS region (e.g. us-east-1).
	 * @param string $access_key   Access key id.
	 * @param string $secret_key   Secret access key.
	 * @param string $payload      Raw JSON body.
	 * @param string $service      AWS service name for signing (ses).
	 * @return array<string, string> Request headers for wp_remote_post.
	 */
	public static function signed_headers(string $region, string $access_key, string $secret_key, string $payload, string $service = 'ses'): array {
		$host       = 'email.' . $region . '.amazonaws.com';
		$amz_date   = gmdate('Ymd\THis\Z');
		$date_stamp = substr($amz_date, 0, 8);
		$uri        = '/v2/email/outbound-emails';

		$payload_hash = hash('sha256', $payload);
		$headers      = array(
			'content-type'         => 'application/json; charset=UTF-8',
			'host'                 => $host,
			'x-amz-content-sha256' => $payload_hash,
			'x-amz-date'           => $amz_date,
		);

		ksort($headers);
		$canonical_headers = '';
		$signed_headers    = '';
		foreach ($headers as $k => $v) {
			$canonical_headers .= strtolower($k) . ':' . trim((string) $v) . "\n";
			$signed_headers   .= strtolower($k) . ';';
		}
		$signed_headers = rtrim($signed_headers, ';');

		$canonical_request = "POST\n"
			. $uri . "\n\n"
			. $canonical_headers
			. $signed_headers . "\n"
			. $payload_hash;

		$credential_scope = $date_stamp . '/' . $region . '/' . $service . '/aws4_request';
		$string_to_sign   = "AWS4-HMAC-SHA256\n"
			. $amz_date . "\n"
			. $credential_scope . "\n"
			. hash('sha256', $canonical_request);

		$k_date    = hash_hmac('sha256', $date_stamp, 'AWS4' . $secret_key, true);
		$k_region  = hash_hmac('sha256', $region, $k_date, true);
		$k_service = hash_hmac('sha256', $service, $k_region, true);
		$k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
		$signature = hash_hmac('sha256', $string_to_sign, $k_signing);

		$auth = 'AWS4-HMAC-SHA256 Credential=' . $access_key . '/' . $credential_scope
			. ', SignedHeaders=' . $signed_headers
			. ', Signature=' . $signature;

		return array(
			'Authorization'        => $auth,
			'Host'                 => $host,
			'X-Amz-Date'           => $amz_date,
			'X-Amz-Content-Sha256' => $payload_hash,
			'Content-Type'         => 'application/json; charset=UTF-8',
		);
	}
}
