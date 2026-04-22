<?php

namespace DualCheck2FA\delivery;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Resolves mail API secrets from wp-config constants (preferred) or stored settings.
 */
final class Mail_Credentials {

	public const SENDGRID_KEY_OPTION     = 'mail_sendgrid_api_key';

	public const POSTMARK_TOKEN_OPTION   = 'mail_postmark_server_token';

	public const MAILGUN_KEY_OPTION      = 'mail_mailgun_api_key';

	public const MAILGUN_DOMAIN_OPTION   = 'mail_mailgun_domain';

	public const SENDGRID_KEY_CONSTANT   = 'DUAL_CHECK_2FA_SENDGRID_API_KEY';

	public const POSTMARK_TOKEN_CONSTANT = 'DUAL_CHECK_2FA_POSTMARK_SERVER_TOKEN';

	public const MAILGUN_KEY_CONSTANT    = 'DUAL_CHECK_2FA_MAILGUN_API_KEY';

	public const MAILGUN_DOMAIN_CONSTANT = 'DUAL_CHECK_2FA_MAILGUN_DOMAIN';

	public const MAILGUN_REGION_CONSTANT = 'DUAL_CHECK_2FA_MAILGUN_REGION';

	/** @var array<string, string> Primary wp-config constant => legacy WP_DUAL_CHECK_* name. */
	private const LEGACY_CONSTANT_ALIASES = array(
		'DUAL_CHECK_2FA_SENDGRID_API_KEY'        => 'WP_DUAL_CHECK_SENDGRID_API_KEY',
		'DUAL_CHECK_2FA_POSTMARK_SERVER_TOKEN'   => 'WP_DUAL_CHECK_POSTMARK_SERVER_TOKEN',
		'DUAL_CHECK_2FA_MAILGUN_API_KEY'         => 'WP_DUAL_CHECK_MAILGUN_API_KEY',
		'DUAL_CHECK_2FA_MAILGUN_DOMAIN'          => 'WP_DUAL_CHECK_MAILGUN_DOMAIN',
		'DUAL_CHECK_2FA_MAILGUN_REGION'          => 'WP_DUAL_CHECK_MAILGUN_REGION',
	);

	/**
	 * Whether a non-empty constant is defined (secrets should not be echoed in admin).
	 */
	public static function constant_is_set(string $constant_name): bool {
		if (defined($constant_name) && (string) constant($constant_name) !== '') {
			return true;
		}
		$legacy = self::LEGACY_CONSTANT_ALIASES[ $constant_name ] ?? null;

		return $legacy !== null && defined($legacy) && (string) constant($legacy) !== '';
	}

	/**
	 * Prefer wp-config constant over option value.
	 *
	 * @param string               $constant_name PHP constant name.
	 * @param string               $option_key    Key inside {@see dual_check_settings()} row.
	 * @param array<string, mixed> $settings      Settings row.
	 */
	public static function constant_or_option(string $constant_name, string $option_key, array $settings): string {
		if (defined($constant_name) && (string) constant($constant_name) !== '') {
			return (string) constant($constant_name);
		}
		$legacy = self::LEGACY_CONSTANT_ALIASES[ $constant_name ] ?? null;
		if ($legacy !== null && defined($legacy) && (string) constant($legacy) !== '') {
			return (string) constant($legacy);
		}
		$v = $settings[ $option_key ] ?? '';

		return is_string($v) ? $v : '';
	}

	/**
	 * Default From address for transactional APIs (matches typical wp_mail origin).
	 */
	public static function default_from_email(): string {
		$email = (string) get_option('admin_email', '');
		$email = sanitize_email($email);

		return is_email($email) ? $email : '';
	}

	/**
	 * Default From display name.
	 */
	public static function default_from_name(): string {
		$name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
		$name = trim($name);

		return $name !== '' ? $name : __('WordPress', 'dual-check-2fa');
	}
}
