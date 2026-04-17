<?php

namespace WP_DUAL_CHECK\integrations;

use WP_DUAL_CHECK\admin\Settings_Page;
use WP_DUAL_CHECK\admin\User_Profile_Settings;
use WP_DUAL_CHECK\auth\Code_Request_Cooldown;
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
 */
final class LoginFlow {

	public const POST_CODE_KEY = 'dual_check_code';

	public const ACTION_CODE_PAGE = 'wp_dual_check';

	public const QUERY_SESSION = 'wpdc_session';

	private const TRANSIENT_PREFIX = 'wpdc_sess_';

	public function register(): void {
		add_filter('wp_authenticate_user', array($this, 'after_password_ok_redirect_to_code_page'), 20, 2);
		add_action('login_init', array($this, 'run_separate_code_page'), 0);
		add_action('login_form_' . self::ACTION_CODE_PAGE, '__return_empty_string', 1);
	}

	private static function site_requires_second_factor(): bool {
		return Settings_Page::is_2fa_required_for_all();
	}

	private static function transient_name(string $session): string {
		return self::TRANSIENT_PREFIX . $session;
	}

	/**
	 * URL-safe token (hex only). Do not use sanitize_text_field() on it — that can change characters and break transient lookup.
	 */
	private static function new_session_token(): string {
		return bin2hex(random_bytes(24));
	}

	/**
	 * @return string 48-char hex or '' if missing/invalid
	 */
	private static function parse_session_from_request(): string {
		if (!isset($_REQUEST[self::QUERY_SESSION])) {
			return '';
		}
		$raw = preg_replace('/[^a-f0-9]/i', '', (string) wp_unslash($_REQUEST[self::QUERY_SESSION]));

		return strlen($raw) === 48 ? strtolower($raw) : '';
	}

	private static function pending_session_ttl(): int {
		$settings = dual_check_settings();
		$minutes  = (int) $settings['code_lifetime_minutes'];

		return $minutes * MINUTE_IN_SECONDS + MINUTE_IN_SECONDS;
	}

	/**
	 * Runs only after WordPress has already validated username + password.
	 * If 2FA is on: create session transient, email code, redirect to {@see ACTION_CODE_PAGE}. Otherwise return user.
	 *
	 * @param \WP_User|\WP_Error $user
	 * @param string              $password
	 * @return \WP_User|\WP_Error
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

		if ((defined('REST_REQUEST') && REST_REQUEST) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || (defined('DOING_CRON') && DOING_CRON)) {
			return $user;
		}

		Logger::debug(
			'twofa_triggered',
			array(
				'user_id'     => $user_id,
				'user_login'  => $user->user_login,
			)
		);

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

		wp_safe_redirect(
			add_query_arg(
				array(
					'action'        => self::ACTION_CODE_PAGE,
					self::QUERY_SESSION => $session,
				),
				wp_login_url()
			)
		);
		exit;
	}

	/**
	 * Own login route: only the code form (handled before the default username/password screen runs).
	 */
	public function run_separate_code_page(): void {
		if (!isset($_REQUEST['action']) || $_REQUEST['action'] !== self::ACTION_CODE_PAGE) {
			return;
		}

		if (!self::site_requires_second_factor()) {
			wp_safe_redirect(wp_login_url());
			exit;
		}

		$session = self::parse_session_from_request();
		if ($session === '') {
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

		if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['wp_dual_check_nonce'])) {
			$this->handle_code_page_post($session, $pending);

			return;
		}

		$this->render_code_page($session, new \WP_Error());
	}

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

		$user_id      = (int) $pending['user_id'];
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
			$errors = new \WP_Error('dual_check_invalid', __('That code is wrong or expired. Try again.', 'wp-dual-check'));
			$this->render_code_page($session, $errors);

			return;
		}

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

		delete_transient(self::transient_name($session));

		$user = get_userdata($user_id);
		if (!$user instanceof \WP_User) {
			Logger::debug(
				'twofa_failed',
				array(
					'user_id' => $user_id,
					'reason'  => 'missing_user',
				)
			);
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

	private function render_code_page(string $session, \WP_Error $errors): void {
		$message = '<p class="message">' . esc_html__('Check your email, then enter the security code below.', 'wp-dual-check') . '</p>';
		login_header(__('Security code', 'wp-dual-check'), $message, $errors);

		$form_action = esc_url(site_url('wp-login.php', 'login_post'));
		?>
		<form name="wpdualcheck" id="wpdualcheck" action="<?php echo $form_action; ?>" method="post" autocomplete="off">
			<input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_CODE_PAGE); ?>" />
			<input type="hidden" name="<?php echo esc_attr(self::QUERY_SESSION); ?>" value="<?php echo esc_attr($session); ?>" />
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

	private function render_code_page_expired(): void {
		$errors = new \WP_Error(
			'dual_check_session',
			__('This sign-in step is no longer valid. That usually means the wait was too long, the link was opened twice after you already signed in, or the address was changed. Log in again with your username and password.', 'wp-dual-check')
		);
		login_header(__('Security code', 'wp-dual-check'), '', $errors);
		echo '<p id="nav"><a href="' . esc_url(wp_login_url()) . '">' . esc_html__('Back to log in', 'wp-dual-check') . '</a></p>';
		login_footer();
		exit;
	}

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
