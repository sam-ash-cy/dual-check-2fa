<?php

namespace WP_DUAL_CHECK\admin;

if (!defined('ABSPATH')) {
	exit;
}

/** Each class hooks itself into admin (menu, fields, etc.). */
interface Admin_Settings_Page {
	public function register(): void;
}
