<?php

namespace DualCheck2FA\admin;

use DualCheck2FA\core\Security;
use function DualCheck2FA\db\get_events_table_name;

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('\WP_List_Table', false)) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Admin list of persisted security / login events.
 */
final class Login_Activity_Page {

	public const MENU_SLUG = 'dual-check-2fa-login-activity';

	public const PER_PAGE_OPTION = 'dual_check_2fa_activity_per_page';

	/**
	 * @return void
	 */
	public function register(): void {
		add_action('admin_menu', array($this, 'add_submenu'));
		add_filter('set_screen_option_' . self::PER_PAGE_OPTION, array($this, 'set_screen_option'), 10, 3);
	}

	/**
	 * @param mixed  $screen_value Prior value (false).
	 * @param string $option       Option name.
	 * @param int    $value         Posted per-page value.
	 * @return int|false
	 */
	public function set_screen_option($screen_value, string $option, $value) {
		if ($option !== self::PER_PAGE_OPTION) {
			return $screen_value;
		}
		$v = absint($value);

		return in_array($v, array(25, 50, 100), true) ? $v : 25;
	}

	/**
	 * @return void
	 */
	public function add_submenu(): void {
		$hook = add_submenu_page(
			Settings_Page::MENU_SLUG,
			__('Login Activity', 'dual-check-2fa'),
			__('Login Activity', 'dual-check-2fa'),
			Security::menu_capability_for_activity(),
			self::MENU_SLUG,
			array($this, 'render_page')
		);
		if ($hook) {
			add_action('load-' . $hook, array($this, 'screen_options'));
		}
	}

	/**
	 * @return void
	 */
	public function screen_options(): void {
		$args = array(
			'label'   => __('Events per page', 'dual-check-2fa'),
			'default' => 25,
			'option'  => self::PER_PAGE_OPTION,
		);
		add_screen_option('per_page', $args);
	}

	/**
	 * @return void
	 */
	public function render_page(): void {
		if (!is_user_logged_in()) {
			wp_die(esc_html__('You must be logged in.', 'dual-check-2fa'), esc_html__('Error', 'dual-check-2fa'), array('response' => 403));
		}
		if (!Security::can_access_login_activity()) {
			wp_die(esc_html__('You do not have permission to access this page.', 'dual-check-2fa'), esc_html__('Error', 'dual-check-2fa'), array('response' => 403));
		}

		$table = new Login_Activity_List_Table();
		$table->prepare_items();

		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__('Login Activity', 'dual-check-2fa') . '</h1><hr class="wp-header-end" />';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '" />';
		$table->views();
		$table->search_box(__('Search users', 'dual-check-2fa'), 'dc2fa-activity-user');
		$table->display();
		echo '</form></div>';
	}
}

/**
 * @internal
 */
final class Login_Activity_List_Table extends \WP_List_Table {

	/**
	 * @return array<string, string>
	 */
	protected function get_searchable_columns(): array {
		return array(
			'user' => 'user',
		);
	}

	/**
	 * @return void
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'dc2fa_event',
				'plural'   => 'dc2fa_events',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Same as {@see \WP_List_Table::display()} with two adjustments for this log screen:
	 * no duplicate {@code <tfoot>} header row; no bottom tablenav when there is only one page
	 * (avoids a second identical “N items” line; bottom nav stays when paginated).
	 *
	 * @return void
	 */
	public function display(): void {
		$singular = $this->_args['singular'];

		$this->display_tablenav('top');

		$this->screen->render_screen_reader_content('heading_list');
		?>
<table class="wp-list-table <?php echo esc_attr(implode(' ', $this->get_table_classes())); ?>">
		<?php $this->print_table_description(); ?>
	<thead>
	<tr>
		<?php $this->print_column_headers(); ?>
	</tr>
	</thead>

	<tbody id="the-list"
		<?php
		if ($singular) {
			printf(' data-wp-lists="%s"', esc_attr('list:' . $singular));
		}
		?>
		>
		<?php $this->display_rows_or_placeholder(); ?>
	</tbody>

</table>
		<?php
		$total_pages = isset($this->_pagination_args['total_pages']) ? (int) $this->_pagination_args['total_pages'] : 0;
		if ($total_pages > 1) {
			$this->display_tablenav('bottom');
		}
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'created_at' => __('Time', 'dual-check-2fa'),
			'user'       => __('User', 'dual-check-2fa'),
			'event'      => __('Event', 'dual-check-2fa'),
			'reason'     => __('Reason', 'dual-check-2fa'),
			'ip_address' => __('IP', 'dual-check-2fa'),
			'user_agent' => __('User agent', 'dual-check-2fa'),
		);
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'created_at' => array('created_at', true),
		);
	}

	/**
	 * @return void
	 */
	protected function extra_tablenav($which): void {
		if ($which !== 'top') {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter GET args; screen requires {@see Security::can_access_login_activity()}.
		$events = array(
			''                => __('All events', 'dual-check-2fa'),
			'token_issued'    => 'token_issued',
			'token_verify_success' => 'token_verify_success',
			'token_verify_failed'  => 'token_verify_failed',
			'login_success'   => 'login_success',
			'trusted_device_used' => 'trusted_device_used',
		);

		$cur_event = isset($_GET['dc2fa_event']) ? sanitize_key((string) wp_unslash($_GET['dc2fa_event'])) : '';
		$from      = isset($_GET['dc2fa_from']) ? sanitize_text_field((string) wp_unslash($_GET['dc2fa_from'])) : '';
		$to        = isset($_GET['dc2fa_to']) ? sanitize_text_field((string) wp_unslash($_GET['dc2fa_to'])) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="dc2fa_event">' . esc_html__('Event', 'dual-check-2fa') . '</label>';
		echo '<select name="dc2fa_event" id="dc2fa_event">';
		foreach ($events as $val => $label) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr($val),
				selected($cur_event, $val, false),
				esc_html($label)
			);
		}
		echo '</select> ';
		echo '<input type="date" name="dc2fa_from" value="' . esc_attr($from) . '" /> ';
		echo '<input type="date" name="dc2fa_to" value="' . esc_attr($to) . '" /> ';
		submit_button(__('Filter', 'dual-check-2fa'), 'secondary', 'filter_action', false);
		echo '</div>';
	}

	/**
	 * @return void
	 */
	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list table sort/filter GET args; {@see Login_Activity_Page::render_page()} requires capability.
		$per_page = (int) $this->get_items_per_page(Login_Activity_Page::PER_PAGE_OPTION, 25);
		if (!in_array($per_page, array(25, 50, 100), true)) {
			$per_page = 25;
		}

		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = $this->get_sortable_columns();
		$this->_column_headers = array($columns, $hidden, $sortable);

		global $wpdb;
		$table = get_events_table_name();

		$where  = array('1=1');
		$params = array();

		if (isset($_GET['s']) && is_string($_GET['s']) && $_GET['s'] !== '') {
			$search = '%' . $wpdb->esc_like(sanitize_user(wp_unslash($_GET['s']), true)) . '%';
			$where[] = 'user_id IN (SELECT ID FROM ' . $wpdb->users . ' WHERE user_login LIKE %s)';
			$params[] = $search;
		}

		if (isset($_GET['dc2fa_event']) && is_string($_GET['dc2fa_event']) && $_GET['dc2fa_event'] !== '') {
			$where[]  = 'event = %s';
			$params[] = sanitize_key(wp_unslash($_GET['dc2fa_event']));
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by date-format regex on the line below.
		$dc2fa_from = isset($_GET['dc2fa_from']) ? (string) wp_unslash($_GET['dc2fa_from']) : '';
		if ($dc2fa_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dc2fa_from)) {
			$where[]  = 'created_at >= %s';
			$params[] = $dc2fa_from . ' 00:00:00';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by date-format regex on the line below.
		$dc2fa_to = isset($_GET['dc2fa_to']) ? (string) wp_unslash($_GET['dc2fa_to']) : '';
		if ($dc2fa_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dc2fa_to)) {
			$where[]  = 'created_at <= %s';
			$params[] = $dc2fa_to . ' 23:59:59';
		}

		$where_sql = implode(' AND ', $where);
		$sql_count = "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}";
		if ($params !== array()) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WHERE built from %s placeholders above; table name from get_events_table_name().
			$sql_count = $wpdb->prepare($sql_count, $params);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql_count is result of $wpdb->prepare(); custom events table.
		$total = (int) $wpdb->get_var($sql_count);

		$paged = max(1, absint($_GET['paged'] ?? 1));
		$offset = ($paged - 1) * $per_page;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- result restricted to ASC|DESC on the next line.
		$order_raw = isset($_GET['order']) ? strtolower((string) wp_unslash($_GET['order'])) : '';
		$dir       = ('asc' === $order_raw) ? 'ASC' : 'DESC';

		$sql_items = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY created_at {$dir} LIMIT %d OFFSET %d";
		$item_params = array_merge($params, array($per_page, $offset));
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $dir is only ASC|DESC; remaining placeholders passed to prepare().
		$sql_items = $wpdb->prepare($sql_items, $item_params);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql_items produced by $wpdb->prepare(); custom events table.
		$rows = $wpdb->get_results($sql_items, ARRAY_A);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if (!is_array($rows)) {
			$rows = array();
		}

		$this->items = $rows;
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil($total / $per_page),
			)
		);
	}

	/**
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_default($item, $column_name): string {
		if (!is_array($item)) {
			return '';
		}

		$v = $item[ $column_name ] ?? '';

		return esc_html(is_scalar($v) ? (string) $v : '');
	}

	/**
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_created_at($item): string {
		if (!is_array($item) || empty($item['created_at'])) {
			return '';
		}

		return esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) $item['created_at'], true));
	}

	/**
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_user($item): string {
		if (!is_array($item)) {
			return '';
		}
		$uid = (int) ($item['user_id'] ?? 0);
		if ($uid <= 0) {
			return '&mdash;';
		}
		$user = get_userdata($uid);
		if (!$user) {
			return (string) $uid;
		}
		$url = get_edit_user_link($uid);

		return '<a href="' . esc_url($url) . '">' . esc_html($user->user_login) . '</a>';
	}

	/**
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_event($item): string {
		if (!is_array($item)) {
			return '';
		}
		$e = esc_html((string) ($item['event'] ?? ''));
		$class = 'notice-info';
		if (strpos((string) ($item['event'] ?? ''), 'failed') !== false) {
			$class = 'notice-error';
		} elseif (strpos((string) ($item['event'] ?? ''), 'success') !== false || ($item['event'] ?? '') === 'login_success') {
			$class = 'notice-success';
		}

		return '<span class="notice ' . esc_attr($class) . ' inline" style="padding:2px 8px;border-left-width:4px;"><code>' . $e . '</code></span>';
	}

	/**
	 * @param array<string, mixed> $item Row.
	 * @return string
	 */
	protected function column_user_agent($item): string {
		if (!is_array($item)) {
			return '';
		}
		$ua = (string) ($item['user_agent'] ?? '');
		if (strlen($ua) > 80) {
			$ua = substr($ua, 0, 80) . '…';
		}

		return esc_html($ua);
	}
}
