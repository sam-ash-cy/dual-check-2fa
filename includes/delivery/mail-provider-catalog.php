<?php

namespace DualCheck2FA\delivery;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Built-in mail provider catalog and factory.
 */

/**
 * Registered providers for the admin dropdown (id + label).
 *
 * @return array<int, array{id: string, label: string}>
 */
function get_registered_mail_providers(): array {
	$builtins = array(
		array(
			'id'    => 'wp_mail',
			'label' => __('WordPress wp_mail()', 'dual-check-2fa'),
		),
		array(
			'id'    => 'sendgrid',
			'label' => __('SendGrid (HTTP API)', 'dual-check-2fa'),
		),
		array(
			'id'    => 'postmark',
			'label' => __('Postmark (HTTP API)', 'dual-check-2fa'),
		),
		array(
			'id'    => 'mailgun',
			'label' => __('Mailgun (HTTP API)', 'dual-check-2fa'),
		),
	);

	/**
	 * Filters provider rows shown in General settings and accepted by the factory.
	 *
	 * @param array<int, array{id: string, label: string}> $builtins Core providers.
	 */
	return (array) apply_filters('dual_check_2fa_registered_mail_providers', $builtins);
}

/**
 * @return array<int, string>
 */
function registered_mail_provider_ids(): array {
	$ids = array();
	foreach (get_registered_mail_providers() as $row) {
		if (!is_array($row) || empty($row['id']) || !is_string($row['id'])) {
			continue;
		}
		$ids[] = sanitize_key($row['id']);
	}

	return array_values(array_unique($ids));
}

/**
 * @param string $id Raw provider id from settings.
 */
function normalize_mail_provider_id(string $id): string {
	$id      = sanitize_key($id);
	$allowed = registered_mail_provider_ids();
	if ($id === '' || !in_array($id, $allowed, true)) {
		return 'wp_mail';
	}

	return $id;
}

/**
 * Builds a provider instance from saved settings (caller ensures custom-provider mode is on).
 *
 * @param array<string, mixed> $settings Full plugin settings row.
 */
function create_mail_provider_from_settings(array $settings): Mail_Provider_Interface {
	$id = normalize_mail_provider_id(isset($settings['mail_provider_id']) ? (string) $settings['mail_provider_id'] : 'wp_mail');

	switch ($id) {
		case 'wp_mail':
			return new Wp_Mail_Provider();
		case 'sendgrid':
			return new Sendgrid_Mail_Provider($settings);
		case 'postmark':
			return new Postmark_Mail_Provider($settings);
		case 'mailgun':
			return new Mailgun_Mail_Provider($settings);
		default:
			return new Wp_Mail_Provider();
	}
}
