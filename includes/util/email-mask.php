<?php

namespace DualCheck2FA\util;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Masks an email for display (keeps first/last of local and of domain label before TLD).
 */
function mask_email(string $email): string {
	$email = trim($email);
	if ($email === '' || strpos($email, '@') === false) {
		return '***';
	}

	$parts = explode('@', $email, 2);
	$local = $parts[0];
	$domain = $parts[1] ?? '';

	if ($local === '' || $domain === '') {
		return '***';
	}

	$mask_part = static function (string $segment): string {
		$len = strlen($segment);
		if ($len <= 2) {
			return str_repeat('*', $len);
		}

		return $segment[0] . str_repeat('*', $len - 2) . $segment[ $len - 1 ];
	};

	$local_out = strlen($local) <= 2 ? str_repeat('*', strlen($local)) : $mask_part($local);

	$domain_label = $domain;
	$tld          = '';
	$last_dot     = strrpos($domain, '.');
	if ($last_dot !== false && $last_dot > 0) {
		$domain_label = substr($domain, 0, $last_dot);
		$tld          = substr($domain, $last_dot);
	}

	if ($domain_label === '') {
		return $local_out . '@' . str_repeat('*', strlen($domain));
	}

	$domain_out = strlen($domain_label) <= 2
		? str_repeat('*', strlen($domain_label))
		: $mask_part($domain_label);

	return $local_out . '@' . $domain_out . $tld;
}
