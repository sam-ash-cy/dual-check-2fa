# Dual Check 2FA — developer notes

## Layout

- Main bootstrap PHP file (`dual-check-2fa.php` in this repo) — loads Composer `vendor/autoload.php` and `PluginLoad`.
- `uninstall.php` — delete-only cleanup when the plugin is removed.
- `includes/core/plugin-load.php` — wires admin menus, login integration, profile field, DB activation.
- `includes/core/security.php` — capability matrix, `Security::can_*`, bypass for super admin / administrator / filter.
- `includes/db/dual-check-database.php` — table name, activation, token CRUD, `dual_check_settings()`.
- `includes/integrations/login-flow.php` — intercepts `wp_login`, code step UI, session transients.
- `includes/auth/` — code request cooldown, IP-bound code-step rate limit.
- `includes/email/login-email-builder.php` — wraps outbound login mail HTML/text.
- `includes/delivery/` — mail provider resolution (`registry.php`, `mail-provider-catalog.php`, `mail-credentials.php`), `delivery-options/*` (`Wp_Mail_Provider`, SendGrid, Postmark, Mailgun, Amazon SES).
- `includes/cron/token-gc.php` — daily cron: token table GC, expired trusted devices, login activity retention.
- `includes/logging/event-recorder.php` — persists `dual_check_2fa_security_event` to `{prefix}dual_check_events`.
- `includes/admin/login-activity-page.php` — **Login Activity** list table.
- `includes/auth/trusted-device.php` — remembered-browser cookies and `{prefix}dual_check_trusted_devices` rows.
- `includes/admin/user-exemption.php` — per-user exemption meta + filters.
- `includes/util/email-mask.php` — `mask_email()` for the code step hint.
- `includes/admin/` — settings pages (general, capabilities, email), notices, user profile field.
- `includes/logging/logger.php` — optional JSON line log under uploads.
- `templates/email/default-template.php` — default subject/body/header/footer callables.

## Main flows

1. **Login:** After password succeeds, if the site requires 2FA (and filters do not skip), a token row is created, mail is sent, a short-lived session transient is set, and the user is sent to the code form (`?action=dual_check_2fa`).
2. **Verify:** POST with code consumes the token, clears session data, and completes `wp_login` / redirect.
3. **Settings:** Forms POST to `admin-post.php` via `Settings_Save_Handler` (nonce + `Security::can_access_*` by `save_context`). `Settings_Page::sanitize()` merges and clamps input; `option_page_capability_*` keeps a hypothetical `options.php` path on `manage_options` only. Contexts: `main`, `email`, `permissions`.

## Important hooks and filters

| Hook / filter | Purpose |
| --- | --- |
| `dual_check_2fa_site_requires_second_factor` | bool — whether the second step is required for this request. |
| `dual_check_2fa_skip_second_factor` | bool, `WP_User` — skip the email step entirely. |
| `dual_check_2fa_registered_mail_providers` | `array` of `{ id, label }` rows for the General settings provider dropdown (extend with custom ids; implement sending via `dual_check_2fa_mail_provider` if not built-in). |
| `dual_check_2fa_mail_provider` | **Final** mail adapter: receives the instance already resolved from settings (`Wp_Mail_Provider` when “selectable provider” is off, or factory output when on). Return a `Mail_Provider_Interface` to override. |
| `dual_check_2fa_code_step_ip_binding_enabled` | Toggle IP-bound lockout for failed code attempts. |
| `dual_check_2fa_code_step_ip_max_fails` / `dual_check_2fa_code_step_ip_lockout_seconds` | Tune lockout. |
| `dual_check_2fa_record_code_step_failure` | bool, reason, user id — whether a failure counts toward IP lockout. |
| `dual_check_2fa_security_event` | Fired with event key + context for auditing / extensions. |
| `dual_check_2fa_write_security_event_to_debug_log` | Override whether security events also hit `error_log`. |
| `dual_check_2fa_client_ip` | Override detected client IP for rate limits. |
| `dual_check_2fa_bypass_capability_matrix` | bool — full bypass of cap matrix checks. |
| `dual_check_2fa_user_can` | bool, context (`main` / `email`), array of caps — final OR check after matrix. |
| `dual_check_2fa_per_user_exemption_enabled` | bool — gate for the “allow per-user exemption” setting. |
| `dual_check_2fa_user_is_exempt` | bool, `WP_User` — programmatic exemption (after user meta baseline). |
| `dual_check_2fa_token_gc_enabled` | bool — run token table garbage collection on the daily cron. |
| `dual_check_2fa_token_gc_consumed_days` / `dual_check_2fa_token_gc_expired_days` / `dual_check_2fa_token_gc_keep_per_user` | int — GC tuning. |
| `dual_check_2fa_general_test_email_enabled` | bool — show/use the General → Debugging “Send test email” button. |
| `dual_check_2fa_login_activity_enabled` | bool — persist rows to `{prefix}dual_check_events`. |
| `dual_check_2fa_login_activity_retention_days` | int — delete events older than this many days (cron). |
| `dual_check_2fa_login_activity_record_event` | bool, event key, context array — drop noisy events. |
| `dual_check_2fa_trusted_device_enabled` | bool — trusted device feature. |
| `dual_check_2fa_trusted_device_days` | int — cookie lifetime (1–365). |
| `dual_check_2fa_trusted_device_label` | string, `WP_User`, user agent — stored device label. |
| `dual_check_2fa_mask_delivery_email` | bool — mask the delivery hint on the code step. |
| `dual_check_2fa_masked_email_output` | string, raw email — return non-empty to replace built-in masking. |

Bundled default email fragments come from the `dual_check_2fa_email_default_*` functions in `templates/email/default-template.php` (see `Login_Email_Builder::DEFAULT_TEMPLATE_FN`).

## Composer (contributors)

From the plugin directory:

```bash
composer install -o
```

The distributed zip includes `vendor/` and `composer.json` (see `.github/workflows/tag-archive.yml`). Without `vendor/`, the plugin shows an admin-facing error at load time until dependencies are installed.

## Settings and sanitize contexts

The option row `dual_check_2fa_settings` is one array. On save, hidden field `save_context` selects how `Settings_Page::sanitize()` merges input:

- **`main`** — general 2FA policy, numeric limits, debug logging, “use custom email template” flag, **mail provider** (`mail_custom_provider_enabled`, `mail_provider_id`, API keys / Mailgun domain), preset capability pool entries used by the matrix UI.
- **`email`** — subject/body/header/footer HTML and colour fields (gated by `Security::can_access_email_template()` and custom-template option).
- **`permissions`** — `cap_context_main`, `cap_context_email`, `cap_custom` lines (gated by `Security::can_access_main_settings()`; self-lockout prevention keeps at least one main context cap).

`Settings_Save_Handler` calls `Settings_Page::sanitize()` before `update_option()`, so all branches (including capability gates inside `sanitize()`) apply to admin-post saves.

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

**Testing:** **General → Debugging → Send test email** uses the same `get_default_mail_provider()` path as live login codes (simple subject/body, regardless of custom template). When “Use custom email template” is enabled, **Login Email Template → Send test email** still exercises template content.

## Admin notices

`Settings_Notices::GROUP` is `dual_check_2fa`. `Settings_Notices::render()` also prints `settings_errors('general')` so core-style success notices (including after `options.php`) appear alongside plugin-specific notices after `admin-post.php` saves.

## Uninstall

See README “Uninstall”. `uninstall.php` intentionally does not delete arbitrary `_transient_*` rows; plugin transients use TTLs.
