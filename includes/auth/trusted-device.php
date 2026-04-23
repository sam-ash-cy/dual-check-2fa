<?php

namespace DualCheck2FA\auth;

use DualCheck2FA\admin\Settings_Page;
use DualCheck2FA\core\Request_Context;
use function DualCheck2FA\db\dual_check_settings;
use function DualCheck2FA\db\get_trusted_devices_table_name;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * "Remember this browser" trusted device cookies and persistence.
 */
final class Trusted_Device {

	public const COOKIE_NAME = 'dual_check_2fa_trust';

	/**
	 * @return bool
	 */
	public static function feature_enabled(): bool {
		$opts = wp_parse_args(get_option(Settings_Page::OPTION_NAME, array()), Settings_Page::defaults());

		return (bool) apply_filters(
			'dual_check_2fa_trusted_device_enabled',
			!empty($opts['allow_trusted_devices'])
		);
	}

	/**
	 * @return int Days (1..365).
	 */
	public static function remember_days(): int {
		$opts = wp_parse_args(get_option(Settings_Page::OPTION_NAME, array()), Settings_Page::defaults());
		$days = (int) ($opts['trusted_devices_days'] ?? 30);
		$days = max(1, min(365, $days));

		return (int) apply_filters('dual_check_2fa_trusted_device_days', $days);
	}

	/**
	 * @param string $plain 64-character hex token from the cookie.
	 * @return string
	 */
	private static function hash_token(string $plain): string {
		return hash_hmac('sha256', $plain, wp_salt('auth'));
	}

	/**
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public static function browser_is_trusted(int $user_id): bool {
		if ($user_id <= 0 || !self::feature_enabled()) {
			return false;
		}

		$plain = self::read_cookie_plain();
		if ($plain === '') {
			return false;
		}

	global $wpdb;
	$table = get_trusted_devices_table_name();
	$hash  = self::hash_token($plain);
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from get_trusted_devices_table_name(); direct query on custom table.
	$row   = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE token_hash = %s AND user_id = %d AND expires_at > UTC_TIMESTAMP() LIMIT 1",
			$hash,
			$user_id
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if (!is_array($row) || empty($row['id'])) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; update last-used timestamp.
		$wpdb->update(
			$table,
			array('last_used_at' => current_time('mysql', true)),
			array('id' => (int) $row['id']),
			array('%s'),
			array('%d')
		);

		return true;
	}

	/**
	 * @return string
	 */
	private static function read_cookie_plain(): string {
		if (!isset($_COOKIE[ self::COOKIE_NAME ]) || !is_string($_COOKIE[ self::COOKIE_NAME ])) {
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- value is sanitised by preg_replace to hex characters only.
	$raw = preg_replace('/[^a-f0-9]/i', '', (string) $_COOKIE[ self::COOKIE_NAME ]);
		if (strlen($raw) !== 64) {
			return '';
		}

		return strtolower($raw);
	}

	/**
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public static function issue(int $user_id): void {
		if ($user_id <= 0 || !self::feature_enabled()) {
			return;
		}

		$plain = bin2hex(random_bytes(32));
		$hash  = self::hash_token($plain);
		$days  = self::remember_days();
		$now   = current_time('mysql', true);
		$exp   = gmdate('Y-m-d H:i:s', time() + ($days * DAY_IN_SECONDS));

	global $wpdb;
	$table = get_trusted_devices_table_name();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table; direct insert is intentional.
	$wpdb->insert(
		$table,
		array(
			'user_id'      => $user_id,
			'token_hash'   => $hash,
				'created_at'   => $now,
				'last_used_at' => $now,
				'expires_at'   => $exp,
				'ip_address'   => Request_Context::client_ip(),
				'user_agent'   => Request_Context::user_agent(),
				'label'        => self::label_for_new_device($user_id),
			),
			array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
		);

		self::set_trust_cookies($plain);
	}

	/**
	 * @param string $plain_token 64 hex chars.
	 * @return void
	 */
	private static function set_trust_cookies(string $plain_token): void {
		$expires_at = time() + (self::remember_days() * DAY_IN_SECONDS);
		$secure     = is_ssl();
		$domain     = (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) ? COOKIE_DOMAIN : '';
		$base       = array(
			'expires'  => $expires_at,
			'domain'   => $domain,
			'secure'   => $secure,
			'httponly' => true,
			'samesite' => 'Strict',
		);

		$base['path'] = COOKIEPATH;
		setcookie(self::COOKIE_NAME, $plain_token, $base);

		if (defined('SITECOOKIEPATH') && SITECOOKIEPATH && SITECOOKIEPATH !== COOKIEPATH) {
			$base['path'] = SITECOOKIEPATH;
			setcookie(self::COOKIE_NAME, $plain_token, $base);
		}
	}

	/**
	 * @return void
	 */
	public static function clear_trust_cookies(): void {
		$past       = time() - YEAR_IN_SECONDS;
		$secure     = is_ssl();
		$domain     = (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) ? COOKIE_DOMAIN : '';
		$base       = array(
			'expires'  => $past,
			'domain'   => $domain,
			'secure'   => $secure,
			'httponly' => true,
			'samesite' => 'Strict',
		);
		$base['path'] = COOKIEPATH;
		setcookie(self::COOKIE_NAME, ' ', $base);

		if (defined('SITECOOKIEPATH') && SITECOOKIEPATH && SITECOOKIEPATH !== COOKIEPATH) {
			$base['path'] = SITECOOKIEPATH;
			setcookie(self::COOKIE_NAME, ' ', $base);
		}
	}

	/**
	 * @return void
	 */
	public static function revoke_current(): void {
		$plain = self::read_cookie_plain();
		if ($plain === '') {
			self::clear_trust_cookies();

			return;
		}

	global $wpdb;
	$table = get_trusted_devices_table_name();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; direct delete.
	$wpdb->delete($table, array('token_hash' => self::hash_token($plain)), array('%s'));
	self::clear_trust_cookies();
	}

	/**
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public static function revoke_all_for_user(int $user_id): void {
		if ($user_id <= 0) {
			return;
		}

	global $wpdb;
	$table = get_trusted_devices_table_name();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; direct delete.
	$wpdb->delete($table, array('user_id' => $user_id), array('%d'));
	}

	/**
	 * @param int $row_id  Primary key.
	 * @param int $user_id Owner user id.
	 * @return void
	 */
	public static function revoke_by_row_id(int $row_id, int $user_id): void {
		if ($row_id <= 0 || $user_id <= 0) {
			return;
		}

	global $wpdb;
	$table = get_trusted_devices_table_name();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; direct delete.
	$wpdb->delete(
		$table,
		array(
			'id'      => $row_id,
			'user_id' => $user_id,
		),
		array('%d', '%d')
	);
	}

	/**
	 * @param int $user_id User issuing the device (during code verification wp_set_current_user may not be set yet).
	 * @return string
	 */
	public static function label_for_new_device(int $user_id): string {
		$user = get_userdata($user_id);
		$ua   = Request_Context::user_agent();
		/**
		 * Filters the human-readable label stored for a trusted device row.
		 *
		 * @param string   $label Default derived label.
		 * @param \WP_User $user  User receiving the trusted device.
		 * @param string   $ua    User agent string.
		 */
		$default = self::derive_label_static($ua);
		if (!$user instanceof \WP_User) {
			return $default;
		}

		$filtered = apply_filters('dual_check_2fa_trusted_device_label', $default, $user, $ua);

		return is_string($filtered) && $filtered !== '' ? substr($filtered, 0, 191) : $default;
	}

	/**
	 * @param string $ua User agent.
	 * @return string
	 */
	private static function derive_label_static(string $ua): string {
		$browser = __('Browser', 'dual-check-2fa');
		if (stripos($ua, 'Edg/') !== false) {
			$browser = 'Edge';
		} elseif (stripos($ua, 'Chrome/') !== false && stripos($ua, 'Chromium') === false) {
			$browser = 'Chrome';
		} elseif (stripos($ua, 'Firefox/') !== false) {
			$browser = 'Firefox';
		} elseif (stripos($ua, 'Safari/') !== false && stripos($ua, 'Chrome') === false) {
			$browser = 'Safari';
		}

		$os = '';
		if (stripos($ua, 'Windows') !== false) {
			$os = 'Windows';
		} elseif (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) {
			$os = 'macOS';
		} elseif (stripos($ua, 'Linux') !== false) {
			$os = 'Linux';
		} elseif (stripos($ua, 'Android') !== false) {
			$os = 'Android';
		} elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
			$os = 'iOS';
		}

		$label = $os !== '' ? sprintf('%s on %s', $browser, $os) : $browser;

		return substr((strlen($label) >= 3 ? $label : $ua), 0, 191);
	}
}
