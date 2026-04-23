# Dual Check 2FA — developer notes

## Layout

- Main bootstrap PHP file (`dual-check-2fa.php` in this repo) — loads Composer `vendor/autoload.php` and `PluginLoad`.
- `uninstall.php` — delete-only cleanup when the plugin is removed.
- `includes/core/plugin-load.php` — wires admin menus, login integration, profile field, DB activation.
- `includes/core/security.php` — capability matrix, `Security::can_*`, bypass for super admin / administrator / filter. Context constants: `CONTEXT_MAIN`, `CONTEXT_EMAIL`, `CONTEXT_ACTIVITY`.
- `includes/db/dual-check-database.php` — table name, activation, token CRUD, `dual_check_settings()`, `dual_check_log_security_event()` (`dual_check_2fa_security_event` + optional `error_log`).
- `includes/integrations/login-flow.php` — `wp_authenticate_user`, dedicated code step (`login_form_dual_check_2fa`), session transients, delivery hint.
- `includes/auth/` — token store/validator, code generator, **code request cooldown**, **IP-bound code-step rate limit** (`Code_Step_Rate_Limit`).
- `includes/email/login-email-builder.php` — subject/HTML for login mail; **`Login_Email_Builder::custom_template_includes_code_token( $settings )`** mirrors the save rule (non-empty custom body must contain `[code]` in subject, body, header, or footer).
- `includes/delivery/` — mail provider resolution (`registry.php`, `mail-provider-catalog.php`, `mail-credentials.php`), `delivery-options/*` (`Wp_Mail_Provider`, SendGrid, Postmark, Mailgun, Amazon SES).
- `includes/cron/token-gc.php` — daily cron: token table GC, expired trusted devices, login activity retention.
- `includes/logging/event-recorder.php` — persists `dual_check_2fa_security_event` to `{prefix}dual_check_events`.
- `includes/admin/login-activity-page.php` — **Login Activity** list table.
- `includes/auth/trusted-device.php` — remembered-browser cookies and `{prefix}dual_check_trusted_devices` rows.
- `includes/admin/user-exemption.php` — per-user exemption meta + filters.
- `includes/util/email-mask.php` — `mask_email()` for the code step hint.
- `includes/admin/` — settings pages (general, capabilities, email), notices, user profile field, save handler (`admin-post.php`).
- `includes/logging/logger.php` — optional JSON line log under uploads (`Logger::debug`); fires `dual_check_2fa_security_event` for `login_success`.
- `templates/email/default-template.php` — default subject/body/header/footer callables (`dual_check_2fa_email_default_*`).

## Main flows

1. **Login:** After password succeeds, if the site requires 2FA (and filters do not skip), a token row is created, mail is sent, a short-lived session transient is set, and the user is sent to the code form (`?action=dual_check_2fa`).
2. **Verify:** POST with code consumes the token, clears session data, and completes login / redirect.
3. **Settings:** Forms POST to `admin-post.php` via `Settings_Save_Handler` (nonce + `Security::can_access_*` by `save_context`). `Settings_Page::sanitize()` merges and clamps input; `option_page_capability_*` keeps a hypothetical `options.php` path on `manage_options` only. Contexts: `main`, `email`, `permissions`.

## Hooks and filters

All names are prefixed with `dual_check_2fa_` unless noted.

### Policy and login flow

| Hook / filter | Signature / notes |
| --- | --- |
| `dual_check_2fa_site_requires_second_factor` | `(bool $required)` — after reading the “require for everyone” option. |
| `dual_check_2fa_skip_second_factor` | `(bool $skip, \WP_User $user)` — skip the email step entirely. Core pre-registers skipping for REST, XML‑RPC, and cron; exemptions run on this filter too. |
| `dual_check_2fa_record_code_step_failure` | `(bool $count, string $reason, int $user_id)` — whether a failed verify counts toward IP lockout (e.g. reason `wrong_code`). |

### Mail

| Hook / filter | Signature / notes |
| --- | --- |
| `dual_check_2fa_registered_mail_providers` | `(array $rows)` — each row `['id' => string, 'label' => string]` for the General settings dropdown. |
| `dual_check_2fa_mail_provider` | `(Mail_Provider_Interface $provider)` — **final** adapter after settings resolution. |

### Code step UI and client metadata

| Hook / filter | Signature / notes |
| --- | --- |
| `dual_check_2fa_mask_delivery_email` | `(bool $show_masked)` — whether to show a masked delivery hint on the code step. |
| `dual_check_2fa_masked_email_output` | `(string $masked, string $delivery_email)` — return a non-empty string to replace the default `mask_email()` output for the code-step hint. |
| `dual_check_2fa_client_ip` | `(string $ip)` — from `Request_Context::client_ip()` (`REMOTE_ADDR` after filter). |

### IP binding and lockout

| Hook / filter | Signature / notes |
| --- | --- |
| `dual_check_2fa_code_step_ip_binding_enabled` | `(bool $on)` — after reading the option. |
| `dual_check_2fa_code_step_ip_max_fails` | `(int $max, int $user_id)` — wrong-code threshold before lockout. |
| `dual_check_2fa_code_step_ip_lockout_seconds` | `(int $seconds, int $user_id)` — lockout duration. |

### Security events and logging

| Hook / filter | Signature / notes |
| --- | --- |
| `dual_check_2fa_security_event` | **action** `(string $event, array $context)` — token issue/verify, login success, etc. `Event_Recorder` listens at priority 20. |
| `dual_check_2fa_write_security_event_to_debug_log` | `(null\|bool $write, string $event, array $context)` — return `true` / `false` to force; default uses `WP_DEBUG` + `WP_DEBUG_LOG`. |

### Capabilities

| Hook / filter | Signature / notes |
| --- | --- |
| `dual_check_2fa_bypass_capability_matrix` | `(bool $bypass)` — default: multisite super admin or `administrator` role. |
| `dual_check_2fa_user_can` | `(bool $ok, string $context, array $caps)` — `$context` is `main`, `email`, or `activity` (`Security::CONTEXT_*`). Runs after OR check against `$caps`. |

### Per-user exemption

| Hook / filter | Signature / notes |
| --- | --- |
| `dual_check_2fa_per_user_exemption_enabled` | `(bool $enabled)` — gates the “allow per-user exemption” setting. |
| `dual_check_2fa_user_is_exempt` | `(bool $exempt, \WP_User $user)` — after reading exemption meta. |

### Cron: tokens, activity, trusted devices

| Hook / filter | Signature / notes |
| --- | --- |
| `dual_check_2fa_token_gc_enabled` | `(bool $on)` — token table GC portion of the daily job. |
| `dual_check_2fa_token_gc_consumed_days` | `(int $days)` — delete consumed tokens older than this (default 30). |
| `dual_check_2fa_token_gc_expired_days` | `(int $days)` — delete expired-unconsumed tokens older than this (default 7). |
| `dual_check_2fa_token_gc_keep_per_user` | `(int $n)` — minimum recent rows to keep per user before age cuts (default 20). |
| `dual_check_2fa_login_activity_enabled` | `(bool $on)` — persist events to `{prefix}dual_check_events`. |
| `dual_check_2fa_login_activity_retention_days` | `(int $days)` — delete events older than this (cron; clamped 1–3650 from settings). |
| `dual_check_2fa_login_activity_record_event` | `(bool $record, string $event, array $context)` — skip noisy rows. |

### Trusted devices

| Hook / filter | Signature / notes |
| --- | --- |
| `dual_check_2fa_trusted_device_enabled` | `(bool $enabled)` — after reading the option. |
| `dual_check_2fa_trusted_device_days` | `(int $days)` — remember duration (from settings, clamped). |
| `dual_check_2fa_trusted_device_label` | `(string $default, \WP_User $user, string $user_agent)` — stored device label. |

### Admin test email

| Hook / filter | Signature / notes |
| --- | --- |
| `dual_check_2fa_general_test_email_enabled` | `(bool $enabled)` — **General → Debugging** “Send test email” button and handler. |

### Core (referenced, not owned by this plugin)

- `login_redirect` — `( $redirect_to, $requested_redirect_to, $user )` after successful code verification; `$requested_redirect_to` is passed as `''` here (same pattern as core login).
- `wp_login` — fired after successful second step.

Bundled default email fragments come from `dual_check_2fa_email_default_*` in `templates/email/default-template.php` (see `Login_Email_Builder::DEFAULT_TEMPLATE_FN`).

## Composer (contributors)

From the plugin directory:

```bash
composer install -o
```

Release archives built by **`.github/workflows/tag-archive.yml`** include `vendor/` plus runtime files (`includes/`, `templates/`, bootstrap, `readme.txt`, etc.); they do **not** ship `composer.json` / `composer.lock`. Without `vendor/`, the plugin exits early with a message until `composer install` is run from a git checkout.

## Settings and sanitize contexts

The option row `dual_check_2fa_settings` is one array. On save, hidden field `save_context` selects how `Settings_Page::sanitize()` merges input:

- **`main`** — general 2FA policy, numeric limits, debug logging, token/activity toggles, “use custom email template”, **mail provider** (`mail_custom_provider_enabled`, `mail_provider_id`, API keys / Mailgun domain), trusted device / exemption toggles, preset capability pool entries used by the matrix UI. If custom template would be **on** but templates violate the **`[code]`** rule (non-empty body without the placeholder in subject/body/header/footer), custom template is forced **off** and an admin notice is shown.
- **`email`** — subject/body/header/footer HTML and colour fields (gated by `Security::can_access_email_template()` and custom-template option). Invalid **`[code]`** placement rejects the whole email save (previous values kept).
- **`permissions`** — `cap_context_main`, `cap_context_email`, `cap_context_activity`, `cap_custom` lines (gated by `Security::can_access_main_settings()`; self-lockout prevents removing your own access to **main** or **login activity**).

`Settings_Save_Handler` calls `Settings_Page::sanitize()` before `update_option()`, so all branches apply to admin-post saves.

`Settings_Page::normalize_capability_arrays()` runs when reading defaults so missing keys get sane lists.

### Mail delivery (built-in providers)

Resolution lives in `get_default_mail_provider()` (`includes/delivery/registry.php`):

1. If `mail_custom_provider_enabled` is empty/false → `Wp_Mail_Provider` (WordPress `wp_mail()`).
2. If true → `create_mail_provider_from_settings()` (`mail-provider-catalog.php`) using `mail_provider_id`: `wp_mail`, `sendgrid`, `postmark`, `mailgun`, or `ses` (must remain in the filtered registry list).
3. Then `apply_filters( 'dual_check_2fa_mail_provider', $provider )`.

HTTP providers use `wp_remote_post` (15s timeout); **do not** log API keys or full provider responses in debug code.

**Secrets:** optional `wp-config.php` constants override the database (and the admin UI omits password fields when a constant is set):

| Constant | Purpose |
| --- | --- |
| `DUAL_CHECK_2FA_SENDGRID_API_KEY` | SendGrid API key |
| `DUAL_CHECK_2FA_POSTMARK_SERVER_TOKEN` | Postmark server token |
| `DUAL_CHECK_2FA_MAILGUN_API_KEY` | Mailgun private API key |
| `DUAL_CHECK_2FA_MAILGUN_DOMAIN` | Mailgun sending domain (e.g. `mg.example.com`) |
| `DUAL_CHECK_2FA_MAILGUN_REGION` | `us` or `eu` (API host) |
| `DUAL_CHECK_2FA_SES_ACCESS_KEY_ID` | SES access key id |
| `DUAL_CHECK_2FA_SES_SECRET_ACCESS_KEY` | SES secret access key |
| `DUAL_CHECK_2FA_SES_REGION` | AWS region (e.g. `us-east-1`) |
| `DUAL_CHECK_2FA_SES_CONFIGURATION_SET` | Optional SES configuration set name |

Option keys (when constants unset): `mail_sendgrid_api_key`, `mail_postmark_server_token`, `mail_mailgun_api_key`, `mail_mailgun_domain`, `mail_mailgun_region` (`us` / `eu`), `mail_ses_access_key_id`, `mail_ses_secret_access_key`, `mail_ses_region`, `mail_ses_configuration_set`. On save, **empty** password-style POST values **preserve** the previous stored secret (so other settings can be saved without re-pasting keys).

**From address:** HTTP APIs use `get_option( 'admin_email' )` as the sender; ensure it is valid and allowed by your transactional provider.

**Testing:** **General → Debugging → Send test email** uses the same `get_default_mail_provider()` path as live login codes (simple subject/body when custom template is off). **Login Email Template → Send test email** uses `Login_Email_Builder::build( '000000', … )` so placeholders render.

## Debug log events (`Logger::debug`)

When debug logging is enabled, JSON lines use an `event` field. Useful values include: `twofa_triggered`, `twofa_challenge_ready`, `twofa_failed` (see `reason` in context: `cooldown`, `wrong_code`, `code_step_locked`, etc.), `login_success`. Security-style rows also flow through `dual_check_2fa_security_event` for the activity table when that feature is on.

## Admin notices

`Settings_Notices::GROUP` is `dual_check_2fa`. `Settings_Notices::render()` also prints `settings_errors('general')` so core-style success notices (including after `options.php`) appear alongside plugin-specific notices after `admin-post.php` saves.

## Uninstall

See README “Uninstall”. `uninstall.php` intentionally does not delete arbitrary `_transient_*` rows; plugin transients use TTLs.
