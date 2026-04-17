<?php

namespace WP_DUAL_CHECK\integrations;

use WP_DUAL_CHECK\admin\Settings_Page;
use WP_DUAL_CHECK\admin\User_Profile_Settings;
use WP_DUAL_CHECK\auth\Code_Request_Cooldown;
use WP_DUAL_CHECK\auth\Code_Step_Rate_Limit;
use WP_DUAL_CHECK\auth\Code_Validator;
use WP_DUAL_CHECK\auth\Token_Store;
use WP_DUAL_CHECK\core\Security;
use WP_DUAL_CHECK\email\Login_Email_Builder;
use WP_DUAL_CHECK\Logging\Logger;
use function WP_DUAL_CHECK\db\dual_check_settings;
use function WP_DUAL_CHECK\delivery\get_default_mail_provider;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * After a normal successful username + password, sends an email code and sends the browser to a separate
 * wp-login screen that only asks for that code (no second password on that screen).
 *
 * Filters (see each call site):
 * - `wp_dual_check_site_requires_second_factor` — bool from saved option.
 * - `wp_dual_check_skip_second_factor` — skip email step (bool, \WP_User); core pre-sets REST/XML‑RPC/cron.
 * - `wp_dual_check_mail_provider` — in {@see \WP_DUAL_CHECK\delivery\get_default_mail_provider()}.
 * - `wp_dual_check_code_step_ip_binding_enabled` — in {@see \WP_DUAL_CHECK\auth\Code_Step_Rate_Limit::is_binding_enabled()}.
 * - `wp_dual_check_code_step_ip_max_fails`, `wp_dual_check_code_step_ip_lockout_seconds` — lockout tuning.
 * - `wp_dual_check_record_code_step_failure` — whether to count a failed verify toward IP lockout (bool, reason, user id).
 */
final class LoginFlow {

	public const POST_CODE_KEY = 'dual_check_code';

	public const ACTION_CODE_PAGE = 'wp_dual_check';

	/**
	 * Legacy query key (still read if present so old links work once).
	 * New logins use {@see COOKIE_PENDING} only — the token is not added to the URL.
	 */
	public const QUERY_SESSION = 'wpdc_session';

	private const TRANSIENT_PREFIX = 'wpdc_sess_';

	/** HttpOnly pending-login handle (48 hex chars), SameSite Strict. */
	private const COOKIE_PENDING = 'wp_dual_check_pending';

	/**
	 * Hooks the post-password redirect and the dedicated code-entry login action.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter('wp_authenticate_user', array($this, 'after_password_ok_redirect_to_code_page'), 20, 2);
		add_action('login_init', array($this, 'run_separate_code_page'), 0);
		add_action('login_form_' . self::ACTION_CODE_PAGE, '__return_empty_string', 1);
	}

	/**
	 * Whether site policy forces email second step for every user.
	 *
	 * @return bool
	 */
	private static function site_requires_second_factor(): bool {
		$required = Settings_Page::is_2fa_required_for_all();

		/**
		 * Filters whether the site requires the email second factor (option-driven baseline).
		 *
		 * @param bool $required Value from {@see Settings_Page::is_2fa_required_for_all()}.
		 */
		return (bool) apply_filters('wp_dual_check_site_requires_second_factor', $required);
	}

	/**
	 * Transient key holding pending login state for a browser session token.
	 *
	 * @param string $session 48-character hex session id.
	 * @return string
	 */
	private static function transient_name(string $session): string {
		return self::TRANSIENT_PREFIX . $session;
	}

	/**
	 * URL-safe token (hex only). Do not use sanitize_text_field() on it — that can change characters and break transient lookup.
	 *
	 * @return string
	 */
	private static function new_session_token(): string {
		return bin2hex(random_bytes(24));
	}

	/**
	 * Reads the pending-login token from the HttpOnly cookie (preferred) or legacy query arg.
	 *
	 * @return string 48-character lowercase hex, or empty string if missing/invalid.
	 */
	private static function parse_session_from_request(): string {
		$candidates = array();
		if (isset($_COOKIE[ self::COOKIE_PENDING ]) && is_string($_COOKIE[ self::COOKIE_PENDING ])) {
			$candidates[] = $_COOKIE[ self::COOKIE_PENDING ];
		}
		if (isset($_REQUEST[ self::QUERY_SESSION ])) {
			$candidates[] = (string) wp_unslash($_REQUEST[ self::QUERY_SESSION ]);
		}
		foreach ($candidates as $raw) {
			$hex = preg_replace('/[^a-f0-9]/i', '', $raw);
			if (strlen($hex) === 48) {
				return strtolower($hex);
			}
		}

		return '';
	}

	/**
	 * Sets the pending-login cookie(s) on COOKIEPATH / SITECOOKIEPATH (same pattern as core test cookie).
	 *
	 * @param string $session 48-character lowercase hex.
	 * @return void
	 */
	private static function set_pending_login_cookies(string $session): void {
		$expires_at = time() + self::pending_session_ttl();
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
		setcookie(self::COOKIE_PENDING, $session, $base);

		if (defined('SITECOOKIEPATH') && SITECOOKIEPATH && SITECOOKIEPATH !== COOKIEPATH) {
			$base['path'] = SITECOOKIEPATH;
			setcookie(self::COOKIE_PENDING, $session, $base);
		}
	}

	/**
	 * Clears pending-login cookies on all paths where they may have been set.
	 *
	 * @return void
	 */
	private static function clear_pending_login_cookies(): void {
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
		setcookie(self::COOKIE_PENDING, ' ', $base);

		if (defined('SITECOOKIEPATH') && SITECOOKIEPATH && SITECOOKIEPATH !== COOKIEPATH) {
			$base['path'] = SITECOOKIEPATH;
			setcookie(self::COOKIE_PENDING, ' ', $base);
		}
	}

	/**
	 * How long the pending-login transient may live (code lifetime plus one minute slack).
	 *
	 * @return int Seconds.
	 */
	private static function pending_session_ttl(): int {
		$settings = dual_check_settings();
		$minutes  = (int) $settings['code_lifetime_minutes'];

		return $minutes * MINUTE_IN_SECONDS + MINUTE_IN_SECONDS;
	}

	/**
	 * Runs only after WordPress has already validated username + password.
	 * If 2FA is on: create session transient, email code, redirect to {@see ACTION_CODE_PAGE}. Otherwise return user.
	 *
	 * Skips second step for REST, XML-RPC, and cron so programmatic logins are not blocked.
	 *
	 * @param \WP_User|\WP_Error $user      Authenticated user or prior error.
	 * @param string             $password  Cleartext password (unused; required by the filter signature).
	 * @return \WP_User|\WP_Error User to continue login, or error (e.g. cooldown, mail failure).
	 */
	public function after_password_ok_redirect_to_code_page($user, $password) {
		if (!$user instanceof \WP_User) {
			return $user;
		}

		$user_id = (int) $user->ID;
		if ($user_id <= 0) {
			return new \WP_Error(
				'dual_check_invalid_user',
				__('Invalid user.', 'wp-dual-check')
			);
		}

		if (!self::site_requires_second_factor()) {
			return $user;
		}

		$skip = false;
		if ((defined('REST_REQUEST') && REST_REQUEST) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || (defined('DOING_CRON') && DOING_CRON)) {
			$skip = true;
		}

		/**
		 * Filters whether to skip the email second factor for this login.
		 *
		 * @param bool     $skip Whether to skip 2FA (core pre-sets true for REST, XML‑RPC, cron).
		 * @param \WP_User $user Authenticated user.
		 */
		$skip = (bool) apply_filters('wp_dual_check_skip_second_factor', $skip, $user);
		if ($skip) {
			return $user;
		}

		Logger::debug(
			'twofa_triggered',
			array(
				'user_id'     => $user_id,
				'user_login'  => $user->user_login,
			)
		);

		$lock_wait = Code_Step_Rate_Limit::lock_seconds_remaining($user_id);
		if ($lock_wait > 0) {
			Logger::debug(
				'twofa_failed',
				array(
					'user_id' => $user_id,
					'reason'  => 'code_step_locked',
					'wait'    => $lock_wait,
				)
			);

			return new \WP_Error(
				'dual_check_code_step_locked',
				sprintf(
					/* translators: %d: seconds until retry */
					__('Too many wrong codes from this connection. Try again in %d seconds.', 'wp-dual-check'),
					$lock_wait
				)
			);
		}

		$wait = Code_Request_Cooldown::seconds_remaining($user_id);
		if ($wait > 0) {
			Logger::debug(
				'twofa_failed',
				array(
					'user_id' => $user_id,
					'reason'  => 'cooldown',
					'wait'    => $wait,
				)
			);

			return new \WP_Error(
				'dual_check_cooldown',
				sprintf(
					/* translators: %d: seconds to wait */
					__('Please wait %d seconds before requesting another login code.', 'wp-dual-check'),
					$wait
				)
			);
		}

		$issued = Token_Store::issue_login_challenge($user_id, 'wp-login');
		if ($issued === false) {
			Logger::debug(
				'twofa_failed',
				array(
					'user_id' => $user_id,
					'reason'  => 'issue_token',
				)
			);

			return new \WP_Error(
				'dual_check_issue',
				__('Could not create a login code. Please try again in a moment.', 'wp-dual-check')
			);
		}

		$delivered = $this->deliver_login_challenge_email($user, $issued);
		if (is_wp_error($delivered)) {
			Logger::debug(
				'twofa_failed',
				array(
					'user_id'    => $user_id,
					'reason'     => 'mail_send',
					'error_code' => $delivered->get_error_code(),
				)
			);

			return $delivered;
		}

		Code_Request_Cooldown::mark_sent($user_id);

		$session = self::new_session_token();
		$remember = !empty($_POST['rememberme']);
		$raw_redirect = isset($_POST['redirect_to']) ? wp_unslash((string) $_POST['redirect_to']) : '';
		// Persist redirect target in the transient so the code step can finish with the same destination as wp-login.
		$redirect_to  = wp_validate_redirect($raw_redirect, admin_url());

		set_transient(
			self::transient_name($session),
			array(
				'user_id'      => $user_id,
				'challenge_id' => (int) $issued['id'],
				'remember'     => $remember,
				'redirect_to'  => $redirect_to,
			),
			self::pending_session_ttl()
		);

		Logger::debug(
			'twofa_challenge_ready',
			array(
				'user_id'      => $user_id,
				'challenge_id' => (int) $issued['id'],
			)
		);

		self::set_pending_login_cookies($session);

		wp_safe_redirect(
			add_query_arg(
				array(
					'action' => self::ACTION_CODE_PAGE,
				),
				wp_login_url()
			)
		);
		exit;
	}

	/**
	 * Own login route: only the code form (handled before the default username/password screen runs).
	 *
	 * @return void
	 */
	public function run_separate_code_page(): void {
		if (!isset($_REQUEST['action']) || $_REQUEST['action'] !== self::ACTION_CODE_PAGE) {
			return;
		}

		if (!self::site_requires_second_factor()) {
			self::clear_pending_login_cookies();
			wp_safe_redirect(wp_login_url());
			exit;
		}

		$session = self::parse_session_from_request();
		if ($session === '') {
			self::clear_pending_login_cookies();
			wp_safe_redirect(wp_login_url());
			exit;
		}

		$pending = get_transient(self::transient_name($session));
		if (
			!is_array($pending)
			|| empty($pending['user_id'])
			|| empty($pending['challenge_id'])
			|| (int) $pending['challenge_id'] <= 0
		) {
			Logger::debug(
				'twofa_failed',
				array(
					'reason' => 'session_invalid',
				)
			);
			$this->render_code_page_expired();

			return;
		}

		$user_id_pending = (int) $pending['user_id'];
		$lock_wait       = Code_Step_Rate_Limit::lock_seconds_remaining($user_id_pending);
		if ($lock_wait > 0) {
			$errors = new \WP_Error(
				'dual_check_code_step_locked',
				sprintf(
					/* translators: %d: seconds until retry */
					__('Too many wrong codes. Try again in %d seconds.', 'wp-dual-check'),
					$lock_wait
				)
			);
			$this->render_code_page($session, $errors);

			return;
		}

		if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['wp_dual_check_nonce'])) {
			$this->handle_code_page_post($session, $pending);

			return;
		}

		$this->render_code_page($session, new \WP_Error());
	}

	/**
	 * Validates nonce and code, consumes the challenge row, then sets auth cookies and redirects.
	 *
	 * @param string               $session Opaque session token from the request.
	 * @param array<string, mixed> $pending Transient payload: user_id, challenge_id, remember, redirect_to.
	 * @return void
	 */
	private function handle_code_page_post(string $session, array $pending): void {
		if (!isset($_POST['wp_dual_check_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['wp_dual_check_nonce'])), 'wp_dual_check_submit')) {
			Logger::debug(
				'twofa_failed',
				array(
					'user_id' => isset($pending['user_id']) ? (int) $pending['user_id'] : 0,
					'reason'  => 'invalid_nonce',
				)
			);
			wp_die(esc_html__('Invalid request. Go back and try again.', 'wp-dual-check'), esc_html__('Security check failed', 'wp-dual-check'), 400);
		}

		$user_id = (int) $pending['user_id'];

		$lock_wait = Code_Step_Rate_Limit::lock_seconds_remaining($user_id);
		if ($lock_wait > 0) {
			Logger::debug(
				'twofa_failed',
				array(
					'user_id' => $user_id,
					'reason'  => 'code_step_locked',
					'wait'    => $lock_wait,
				)
			);
			$errors = new \WP_Error(
				'dual_check_code_step_locked',
				sprintf(
					/* translators: %d: seconds until retry */
					__('Too many wrong codes. Try again in %d seconds.', 'wp-dual-check'),
					$lock_wait
				)
			);
			$this->render_code_page($session, $errors);

			return;
		}

		$challenge_id = isset($pending['challenge_id']) ? (int) $pending['challenge_id'] : 0;
		$code         = Security::sanitise_code_from_request(self::POST_CODE_KEY);

		if ($code === '') {
			Logger::debug(
				'twofa_failed',
				array(
					'user_id'      => $user_id,
					'challenge_id' => $challenge_id,
					'reason'       => 'empty_code',
				)
			);
			$errors = new \WP_Error('dual_check_empty', __('Please enter the code from your email.', 'wp-dual-check'));
			$this->render_code_page($session, $errors);

			return;
		}

		// Verify against this specific DB row only (prevents guessing an older code after a re-issue).
		$row = Code_Validator::verify_login_challenge($code, $user_id, $challenge_id);
		if ($row === false) {
			Logger::debug(
				'twofa_failed',
				array(
					'user_id'      => $user_id,
					'challenge_id' => $challenge_id,
					'reason'       => 'wrong_code',
				)
			);
			/**
			 * Filters whether a failed verify should count toward IP + user lockout.
			 *
			 * @param bool   $record  Default true for wrong/expired code rows.
			 * @param string $reason  Internal reason key (`wrong_code`).
			 * @param int    $user_id User id from the pending session.
			 */
			if (apply_filters('wp_dual_check_record_code_step_failure', true, 'wrong_code', $user_id)) {
				Code_Step_Rate_Limit::record_failed_verify($user_id);
			}
			$errors = new \WP_Error('dual_check_invalid', __('That code is wrong or expired. Try again.', 'wp-dual-check'));
			$this->render_code_page($session, $errors);

			return;
		}

		// Single-use: mark consumed before setting cookies so the same code cannot complete two sessions.
		if (!Token_Store::consume_row((int) $row['id'])) {
			Logger::debug(
				'twofa_failed',
				array(
					'user_id'      => $user_id,
					'challenge_id' => $challenge_id,
					'reason'       => 'consume_failed',
					'row_id'       => (int) $row['id'],
				)
			);
			$errors = new \WP_Error('dual_check_consume', __('Could not finish login. Request a new code from the login page.', 'wp-dual-check'));
			$this->render_code_page($session, $errors);

			return;
		}

		Code_Step_Rate_Limit::clear_counters($user_id);

		delete_transient(self::transient_name($session));
		self::clear_pending_login_cookies();

		$user = get_userdata($user_id);
		if (!$user instanceof \WP_User) {
			Logger::debug(
				'twofa_failed',
				array(
					'user_id' => $user_id,
					'reason'  => 'missing_user',
				)
			);
			self::clear_pending_login_cookies();
			wp_safe_redirect(wp_login_url());

			exit;
		}

		wp_clear_auth_cookie();
		wp_set_current_user($user_id);
		wp_set_auth_cookie($user_id, !empty($pending['remember']));
		do_action('wp_login', $user->user_login, $user);

		Logger::debug(
			'login_success',
			array(
				'user_id'    => $user_id,
				'user_login' => $user->user_login,
				'remember'   => !empty($pending['remember']),
			)
		);

		$redirect_to = isset($pending['redirect_to']) ? (string) $pending['redirect_to'] : admin_url();
		$redirect_to = wp_validate_redirect($redirect_to, admin_url());
		$redirect_to = apply_filters('login_redirect', $redirect_to, '', $user);

		wp_safe_redirect($redirect_to);
		exit;
	}

	/**
	 * Outputs the HTML code form on wp-login.php and stops execution.
	 *
	 * @param string    $session Server-side session id (cookie; not echoed in HTML).
	 * @param \WP_Error $errors  Errors to show above the form.
	 * @return void
	 */
	private function render_code_page(string $session, \WP_Error $errors): void {
		$message = '<p class="message">' . esc_html__('Check your email, then enter the security code below.', 'wp-dual-check') . '</p>';
		login_header(__('Security code', 'wp-dual-check'), $message, $errors);

		$form_action = esc_url(site_url('wp-login.php', 'login_post'));
		?>
		<form name="wpdualcheck" id="wpdualcheck" action="<?php echo $form_action; ?>" method="post" autocomplete="off">
			<input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_CODE_PAGE); ?>" />
			<?php wp_nonce_field('wp_dual_check_submit', 'wp_dual_check_nonce'); ?>
			<p>
				<label for="<?php echo esc_attr(self::POST_CODE_KEY); ?>"><?php esc_html_e('Security code', 'wp-dual-check'); ?></label>
				<input type="text" name="<?php echo esc_attr(self::POST_CODE_KEY); ?>" id="<?php echo esc_attr(self::POST_CODE_KEY); ?>" class="input" value="" size="20" autocomplete="one-time-code" required="required" />
			</p>
			<p class="submit">
				<input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e('Continue', 'wp-dual-check'); ?>" />
			</p>
		</form>
		<p id="nav">
			<a href="<?php echo esc_url(wp_login_url()); ?>"><?php esc_html_e('Back to log in', 'wp-dual-check'); ?></a>
		</p>
		<?php

		login_footer();
		exit;
	}

	/**
	 * Friendly message when the session transient is missing or invalid.
	 *
	 * @return void
	 */
	private function render_code_page_expired(): void {
		self::clear_pending_login_cookies();
		$errors = new \WP_Error(
			'dual_check_session',
			__('This sign-in step is no longer valid. That usually means the wait was too long, the link was opened twice after you already signed in, or the address was changed. Log in again with your username and password.', 'wp-dual-check')
		);
		login_header(__('Security code', 'wp-dual-check'), '', $errors);
		echo '<p id="nav"><a href="' . esc_url(wp_login_url()) . '">' . esc_html__('Back to log in', 'wp-dual-check') . '</a></p>';
		login_footer();
		exit;
	}

	/**
	 * Builds and sends the login code email via the default mail provider.
	 *
	 * @param \WP_User             $user   User receiving the code.
	 * @param array{plain: string, id: int} $issued From {@see Token_Store::issue_login_challenge()}.
	 * @return true|\WP_Error True on success; WP_Error if no address or send failed.
	 */
	private function deliver_login_challenge_email(\WP_User $user, array $issued) {
		$to = User_Profile_Settings::get_delivery_email((int) $user->ID);
		if ($to === '') {
			return new \WP_Error(
				'dual_check_email',
				__('No email address is available to send your code.', 'wp-dual-check')
			);
		}

		$mail    = Login_Email_Builder::build($issued['plain'], $user->user_login);
		$headers = array('Content-Type: text/html; charset=UTF-8');
		$sent    = get_default_mail_provider()->send($to, $mail['subject'], $mail['html'], $headers);

		if (!$sent) {
			return new \WP_Error(
				'dual_check_mail',
				__('Could not send the email with your code. Check your site mail settings.', 'wp-dual-check')
			);
		}

		return true;
	}

}
