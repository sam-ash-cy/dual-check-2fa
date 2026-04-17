<?php

namespace WP_DUAL_CHECK\delivery;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Returns the mail provider used for login and test emails.
 *
 * Extend this when adding providers under `delivery-options/`.
 *
 * @return Mail_Provider_Interface
 */
function get_default_mail_provider(): Mail_Provider_Interface {
	$provider = new Wp_Mail_Provider();

	/**
	 * Filters the mail provider used for login codes and admin test email.
	 *
	 * @param Mail_Provider_Interface $provider Default WordPress mail implementation.
	 */
	$filtered = apply_filters('wp_dual_check_mail_provider', $provider);

	return $filtered instanceof Mail_Provider_Interface ? $filtered : $provider;
}
