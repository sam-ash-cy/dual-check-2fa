<?php

namespace DualCheck2FA\delivery;

use function DualCheck2FA\db\dual_check_settings;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Returns the mail provider used for login and test emails.
 *
 * When “Use selectable mail provider” is off in settings, resolution is {@see Wp_Mail_Provider}.
 * When on, the chosen built-in (or wp_mail in the list) is constructed from the settings row.
 * The {@see 'dual_check_2fa_mail_provider'} filter runs last for full override.
 *
 * @return Mail_Provider_Interface
 */
function get_default_mail_provider(): Mail_Provider_Interface {
	$settings = dual_check_settings();
	$provider = new Wp_Mail_Provider();

	if (!empty($settings['mail_custom_provider_enabled'])) {
		$provider = create_mail_provider_from_settings($settings);
	}

	/**
	 * Filters the mail provider used for login codes and admin test email.
	 *
	 * @param Mail_Provider_Interface $provider Resolved from settings (default wp_mail path).
	 */
	$filtered = apply_filters('dual_check_2fa_mail_provider', $provider);

	return $filtered instanceof Mail_Provider_Interface ? $filtered : $provider;
}
