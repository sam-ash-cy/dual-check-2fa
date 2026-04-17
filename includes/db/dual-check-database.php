<?php
/**
 * @package WP_DUAL_CHECK
 */

namespace WP_DUAL_CHECK\db;

if (!defined('ABSPATH')) {
	exit;
}

if (defined('WP_DUAL_CHECK_PATH')) {
	require_once WP_DUAL_CHECK_PATH . 'includes/admin/settings-interface.php';
	require_once WP_DUAL_CHECK_PATH . 'includes/admin/settings-page.php';
	require_once WP_DUAL_CHECK_PATH . 'includes/core/request-context.php';
}

const WP_DUAL_CHECK_DB_SCHEMA_VERSION = '1.1.0';

/** Stored in dual_check.token_type for the wp-login second step. */
const DUAL_CHECK_TOKEN_TYPE_LOGIN = 'login';

/**
 * Returns the full prefixed database table name for token rows.
 *
 * @global \wpdb $wpdb WordPress database abstraction object.
 * @return string
 */
function get_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'dual_check';
}

/**
 * Emits {@see 'wp_dual_check_security_event'} and optionally writes a line to the PHP error log when {@see WP_DEBUG_LOG} is enabled.
 *
 * @param array<string, mixed> $context
 */
function dual_check_log_security_event(string $event, array $context): void {
	/**
	 * Fires after a dual-check security-relevant action (token issued, verify success/failure).
	 *
	 * @param string               $event   Short event key (e.g. token_issued, token_verify_success).
	 * @param array<string, mixed> $context Row id, user id, IP, truncated UA, etc.
	 */
	do_action('wp_dual_check_security_event', $event, $context);

	$write = apply_filters('wp_dual_check_write_security_event_to_debug_log', null, $event, $context);
	if ($write === null) {
		$write = defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
	}
	if (!$write) {
		return;
	}

	if (isset($context['user_agent']) && is_string($context['user_agent']) && strlen($context['user_agent']) > 160) {
		$context['user_agent'] = substr($context['user_agent'], 0, 160) . '…';
	}

	error_log('[WP Dual Check] ' . $event . ' ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES));
}

/**
 * Saved options from Settings → WP Dual Check (with defaults).
 *
 * @return array<string, mixed>
 */
function dual_check_settings(): array {
	$merged = wp_parse_args(
		get_option(\WP_DUAL_CHECK\admin\Settings_Page::OPTION_NAME, array()),
		\WP_DUAL_CHECK\admin\Settings_Page::defaults()
	);

	$clamped = \WP_DUAL_CHECK\admin\Settings_Page::clamp_numeric_settings($merged);
	$caps    = \WP_DUAL_CHECK\admin\Settings_Page::normalize_capability_arrays($clamped);

	return \WP_DUAL_CHECK\admin\Settings_Page::normalize_email_settings($caps);
}

/**
 * Runs on activation and on `plugins_loaded` when the saved schema version is behind {@see WP_DUAL_CHECK_DB_SCHEMA_VERSION}.
 *
 * @global \wpdb $wpdb WordPress database abstraction object.
 * @return void
 */
$dual_check_database_install = static function (): void {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = get_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		token_hash varchar(255) NOT NULL,
		token_type varchar(50) NOT NULL DEFAULT 'otp',
		expires_at datetime NOT NULL,
		consumed_at datetime DEFAULT NULL,
		attempts smallint(5) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		ip_address varchar(45) DEFAULT NULL,
		user_agent text,
		verify_ip_address varchar(45) DEFAULT NULL,
		verify_user_agent text,
		context varchar(64) DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY user_id (user_id),
		KEY token_hash (token_hash),
		KEY expires_at (expires_at),
		KEY user_type_active (user_id, token_type, consumed_at)
	) {$charset_collate};";

	dbDelta($sql);

	update_option('wp_dual_check_db_version', WP_DUAL_CHECK_DB_SCHEMA_VERSION, false);
};

if (defined('WP_DUAL_CHECK_FILE')) {
	register_activation_hook(WP_DUAL_CHECK_FILE, $dual_check_database_install);
}

// Early priority so other plugin code can rely on the table existing when their hooks run.
add_action(
	'plugins_loaded',
	static function () use ($dual_check_database_install): void {
		if (get_option('wp_dual_check_db_version', '') !== WP_DUAL_CHECK_DB_SCHEMA_VERSION) {
			$dual_check_database_install();
		}
	},
	5
);

/**
 * HMAC-SHA256 of the plaintext code using the WordPress auth salt.
 *
 * @param string|null $value Plaintext token; empty yields false.
 * @return string|false Hex digest, or false if input is empty.
 */
function generate_token_hash($value) {
	if ($value === '' || $value === null) {
		return false;
	}

	return hash_hmac('sha256', (string) $value, wp_salt('auth'));
}

/**
 * Inserts a new token row. Returns the plaintext secret (deliver to user) and row id, or false on failure.
 *
 * Login-type tokens invalidate prior unconsumed login rows for the same user so only the latest code works.
 *
 * @param int|string         $user_id    WordPress user ID.
 * @param string             $token_type Row discriminator (e.g. {@see DUAL_CHECK_TOKEN_TYPE_LOGIN}).
 * @param string             $context    Short free-text context (stored truncated).
 * @param int|string|null    $expires_at MySQL datetime or strtotime()-parseable string; null uses settings lifetime.
 * @return array{plain: string, id: int}|false
 */
function add_dual_check_token($user_id, $token_type, $context = '', $expires_at = null) {
	if (empty($user_id) || empty($token_type)) {
		return false;
	}

	if ((string) $token_type === DUAL_CHECK_TOKEN_TYPE_LOGIN) {
		invalidate_prior_login_challenges((int) $user_id);
	}

	$settings = dual_check_settings();
	$length   = (int) $settings['code_length'];
	// Letters + numbers only so HTML email and esc_html() never change what the user must type.
	$plain = wp_generate_password(
		max(
			\WP_DUAL_CHECK\admin\Settings_Page::CODE_LENGTH_MIN,
			min(\WP_DUAL_CHECK\admin\Settings_Page::CODE_LENGTH_MAX, $length)
		),
		false,
		false
	);
	$hash     = generate_token_hash($plain);
	if ($hash === false) {
		return false;
	}

	if ($expires_at) {
		$expires_at = date('Y-m-d H:i:s', strtotime((string) $expires_at));
	} else {
		$minutes = (int) $settings['code_lifetime_minutes'];
		// Store in GMT (same basis as current_time(..., true) in verify) so comparisons stay correct.
		$expires_at = gmdate('Y-m-d H:i:s', time() + ($minutes * 60));
	}

	global $wpdb;

	$ip = \WP_DUAL_CHECK\core\Request_Context::client_ip();
	$ua = \WP_DUAL_CHECK\core\Request_Context::user_agent();

	$table = $wpdb->prefix . 'dual_check';
	$data  = array(
		'user_id'     => (int) $user_id,
		'token_hash'  => $hash,
		'token_type'  => (string) $token_type,
		'expires_at'  => $expires_at,
		'created_at'  => current_time('mysql', true),
		'ip_address'  => $ip,
		'user_agent'  => $ua,
	);
	$formats = array('%d', '%s', '%s', '%s', '%s', '%s', '%s');

	if ($context !== '') {
		$data['context'] = substr($context, 0, 64);
		$formats[]       = '%s';
	}

	$success = $wpdb->insert($table, $data, $formats);

	if ($success === false) {
		return false;
	}

	$row_id = (int) $wpdb->insert_id;
	dual_check_log_security_event(
		'token_issued',
		array(
			'row_id'     => $row_id,
			'user_id'    => (int) $user_id,
			'ip'         => $ip,
			'user_agent' => $ua,
			'context'    => $context !== '' ? substr($context, 0, 64) : '',
		)
	);

	if (class_exists(\WP_DUAL_CHECK\Logging\Logger::class)) {
		\WP_DUAL_CHECK\Logging\Logger::debug(
			'token_issued',
			array(
				'row_id'  => $row_id,
				'user_id' => (int) $user_id,
				'context' => $context !== '' ? substr($context, 0, 64) : '',
			)
		);
	}

	return array(
		'plain' => $plain,
		'id'    => $row_id,
	);
}

/**
 * Marks any unconsumed login challenges for this user as used so only the newest issue stays valid.
 *
 * @param int $user_id WordPress user ID.
 * @return void
 */
function invalidate_prior_login_challenges(int $user_id): void {
	if ($user_id <= 0) {
		return;
	}

	global $wpdb;

	$table = get_table_name();
	$now   = current_time('mysql', true);

	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET consumed_at = %s WHERE user_id = %d AND token_type = %s AND consumed_at IS NULL",
			$now,
			$user_id,
			DUAL_CHECK_TOKEN_TYPE_LOGIN
		)
	);
}

/**
 * Verifies a code against one challenge row (by DB id). Ties verification to the issued challenge, not a broad user scan.
 *
 * On mismatch, increments `attempts` until `max_attempts` from settings is reached.
 *
 * @param int    $challenge_id Primary key of the token row.
 * @param int    $user_id      Expected owner of the row.
 * @param string $token_type   Must match the row’s token_type.
 * @param string $plain_token  Submitted code (plaintext).
 * @return array<string, mixed>|false Full row on success; false if missing, expired, exhausted, or wrong code.
 */
function verify_dual_check_token_by_row(int $challenge_id, int $user_id, string $token_type, string $plain_token) {
	if ($challenge_id <= 0 || $user_id <= 0 || $token_type === '' || $plain_token === '') {
		return false;
	}

	$expected_hash = generate_token_hash($plain_token);
	if ($expected_hash === false) {
		return false;
	}

	global $wpdb;

	$table    = get_table_name();
	$now      = current_time('mysql', true);
	$settings = dual_check_settings();
	$max      = (int) $settings['max_attempts'];
	$v_ip     = \WP_DUAL_CHECK\core\Request_Context::client_ip();
	$v_ua     = \WP_DUAL_CHECK\core\Request_Context::user_agent();

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d AND user_id = %d AND token_type = %s AND consumed_at IS NULL AND expires_at > %s AND attempts < %d",
			$challenge_id,
			$user_id,
			$token_type,
			$now,
			$max
		),
		ARRAY_A
	);

	if (!is_array($row) || empty($row['id'])) {
		if (class_exists(\WP_DUAL_CHECK\Logging\Logger::class)) {
			\WP_DUAL_CHECK\Logging\Logger::debug(
				'token_verify_challenge_missing',
				array(
					'challenge_id' => $challenge_id,
					'user_id'      => $user_id,
				)
			);
		}

		return false;
	}

	if (!empty($row['token_hash']) && hash_equals((string) $row['token_hash'], $expected_hash)) {
		$row_id = (int) $row['id'];
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET verify_ip_address = %s, verify_user_agent = %s WHERE id = %d",
				$v_ip,
				$v_ua,
				$row_id
			)
		);
		dual_check_log_security_event(
			'token_verify_success',
			array(
				'row_id'     => $row_id,
				'user_id'    => (int) $user_id,
				'ip'         => $v_ip,
				'user_agent' => $v_ua,
			)
		);

		if (class_exists(\WP_DUAL_CHECK\Logging\Logger::class)) {
			\WP_DUAL_CHECK\Logging\Logger::debug(
				'token_verify_success',
				array(
					'row_id'  => $row_id,
					'user_id' => (int) $user_id,
				)
			);
		}

		return $row;
	}

	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET attempts = attempts + 1, verify_ip_address = %s, verify_user_agent = %s WHERE id = %d AND attempts < %d",
			$v_ip,
			$v_ua,
			$challenge_id,
			$max
		)
	);
	dual_check_log_security_event(
		'token_verify_failed',
		array(
			'row_id'     => $challenge_id,
			'user_id'    => (int) $user_id,
			'ip'         => $v_ip,
			'user_agent' => $v_ua,
		)
	);
	if (class_exists(\WP_DUAL_CHECK\Logging\Logger::class)) {
		\WP_DUAL_CHECK\Logging\Logger::debug(
			'token_verify_failed',
			array(
				'row_id'  => $challenge_id,
				'user_id' => (int) $user_id,
			)
		);
	}

	return false;
}

/**
 * Marks a token row used so it cannot be verified again.
 *
 * @param int $row_id Primary key of the token row.
 * @return bool True if a row was updated; false if id invalid or already consumed.
 */
function mark_dual_check_token_consumed(int $row_id): bool {
	if ($row_id <= 0) {
		return false;
	}

	global $wpdb;

	$table = get_table_name();
	$now   = current_time('mysql', true);

	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL",
			$now,
			$row_id
		)
	);

	return $wpdb->rows_affected > 0;
}
