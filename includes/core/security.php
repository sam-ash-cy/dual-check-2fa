<?php

namespace DualCheck2FA\core;

use function DualCheck2FA\db\dual_check_settings;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Small shared helpers for input handling and capability checks.
 */
final class Security {

	public const CONTEXT_MAIN = 'main';

	public const CONTEXT_EMAIL = 'email';

	/**
	 * Site “full” admins bypass the configurable capability matrix (multisite super admin or Administrator role).
	 *
	 * @return bool
	 */
	public static function bypass_capability_matrix(): bool {
		if (is_multisite() && is_super_admin()) {
			$bypass = true;
		} else {
			$user = wp_get_current_user();
			$bypass = $user && $user->ID && in_array('administrator', (array) $user->roles, true);
		}

		/**
		 * Filters whether the current user bypasses plugin capability checks entirely.
		 *
		 * @param bool $bypass Default: super admin (multisite) or user with the administrator role.
		 */
		return (bool) apply_filters('dual_check_2fa_bypass_capability_matrix', $bypass);
	}

	/**
	 * Sanitise the code from the request.
	 *
	 * @param string $post_key `$_POST` key holding the submitted code.
	 * @return string Trimmed text field, or empty string if missing/invalid.
	 */
	public static function sanitise_code_from_request(string $post_key): string {
		if (!isset($_POST[$post_key])) {
			return '';
		}
		$raw = wp_unslash($_POST[$post_key]);
		if (!is_string($raw)) {
			return '';
		}

		return trim(sanitize_text_field($raw));
	}

	/**
	 * Normalise a capability slug (lowercase letters, digits, underscore only).
	 *
	 * @param string $slug Raw slug.
	 * @return string Empty if invalid after normalisation.
	 */
	public static function sanitize_cap_slug(string $slug): string {
		$slug = strtolower(preg_replace('/[^a-z0-9_]/', '', $slug));

		return $slug;
	}

	/**
	 * Unique, ordered list of valid capability strings.
	 *
	 * @param array<int|string, mixed> $caps Raw list.
	 * @return array<int, string>
	 */
	public static function normalize_cap_list(array $caps): array {
		$seen = array();
		$out  = array();
		foreach ($caps as $c) {
			if (!is_string($c) && !is_numeric($c)) {
				continue;
			}
			$s = self::sanitize_cap_slug((string) $c);
			if ($s === '' || isset($seen[ $s ])) {
				continue;
			}
			$seen[ $s ] = true;
			$out[]      = $s;
		}

		return $out;
	}

	/**
	 * Effective capability list for a context key (OR semantics). Empty stored list becomes manage_options only.
	 *
	 * @param string               $context_key `cap_context_main` or `cap_context_email`.
	 * @param array<string, mixed>|null $settings    Optional settings row; defaults to {@see dual_check_settings()}.
	 * @return array<int, string>
	 */
	public static function effective_caps_for_context(string $context_key, ?array $settings = null): array {
		$settings ??= dual_check_settings();
		$raw      = isset($settings[ $context_key ]) && is_array($settings[ $context_key ]) ? $settings[ $context_key ] : array();
		$caps     = self::normalize_cap_list($raw);
		if ($caps === array()) {
			return array('manage_options');
		}

		return $caps;
	}

	/**
	 * Whether the current user has at least one of the given capabilities.
	 *
	 * @param array<int, string> $caps Capability slugs.
	 * @return bool
	 */
	public static function user_has_any_cap(array $caps): bool {
		foreach ($caps as $cap) {
			if ($cap !== '' && current_user_can($cap)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the current user would still pass the main-context OR check using proposed settings.
	 *
	 * @param array<string, mixed> $proposed Full settings row including `cap_context_main`.
	 * @return bool
	 */
	public static function current_user_passes_main_context_with_settings(array $proposed): bool {
		if (self::bypass_capability_matrix()) {
			return true;
		}

		$caps = self::effective_caps_for_context('cap_context_main', $proposed);

		return self::user_has_any_cap($caps);
	}

	/**
	 * WordPress menu `capability` argument (single string). Uses first slug from main context; not OR by core.
	 *
	 * @return string
	 */
	public static function menu_capability_for_main(): string {
		$caps = self::effective_caps_for_context('cap_context_main');

		return $caps[0] ?? 'manage_options';
	}

	/**
	 * Submenu capability for email template screen.
	 *
	 * @return string
	 */
	public static function menu_capability_for_email(): string {
		$caps = self::effective_caps_for_context('cap_context_email');

		return $caps[0] ?? 'manage_options';
	}

	/**
	 * Main Dual Check 2FA settings + Capabilities screen.
	 *
	 * @return bool
	 */
	public static function can_access_main_settings(): bool {
		if (self::bypass_capability_matrix()) {
			$caps = self::effective_caps_for_context('cap_context_main');

			return (bool) apply_filters('dual_check_2fa_user_can', true, self::CONTEXT_MAIN, $caps);
		}

		$caps = self::effective_caps_for_context('cap_context_main');
		$ok   = self::user_has_any_cap($caps);

		/**
		 * Filters whether the current user may access main plugin settings (and Capabilities page).
		 *
		 * @param bool               $allowed Whether access is granted.
		 * @param string             $context  {@see CONTEXT_MAIN}.
		 * @param array<int, string> $caps     Effective capability list (OR).
		 */
		return (bool) apply_filters('dual_check_2fa_user_can', $ok, self::CONTEXT_MAIN, $caps);
	}

	/**
	 * Login email template admin + test send.
	 *
	 * @return bool
	 */
	public static function can_access_email_template(): bool {
		if (self::bypass_capability_matrix()) {
			$caps = self::effective_caps_for_context('cap_context_email');

			return (bool) apply_filters('dual_check_2fa_user_can', true, self::CONTEXT_EMAIL, $caps);
		}

		$caps = self::effective_caps_for_context('cap_context_email');
		$ok   = self::user_has_any_cap($caps);

		/**
		 * Filters whether the current user may access the login email template UI and test email.
		 *
		 * @param bool               $allowed Whether access is granted.
		 * @param string             $context  {@see CONTEXT_EMAIL}.
		 * @param array<int, string> $caps     Effective capability list (OR).
		 */
		return (bool) apply_filters('dual_check_2fa_user_can', $ok, self::CONTEXT_EMAIL, $caps);
	}

	/**
	 * @deprecated Use {@see can_access_main_settings()}.
	 * @return bool
	 */
	public static function current_user_can_manage_plugin(): bool {
		return self::can_access_main_settings();
	}
}
