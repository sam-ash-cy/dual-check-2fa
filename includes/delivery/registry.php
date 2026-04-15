<?php

namespace WP_DUAL_CHECK\delivery;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Pick which provider to use. Add cases here when you add files under delivery-options/.
 */
function get_default_mail_provider(): Mail_Provider_Interface {
	return new Wp_Mail_Provider();
}
