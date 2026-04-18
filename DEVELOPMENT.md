# WP Dual Check — developer notes

## Layout

- `wp-dual-check.php` — bootstrap, loads DB helpers and `PluginLoad`.
- `uninstall.php` — delete-only cleanup when the plugin is removed.
- `includes/core/plugin-load.php` — wires admin menus, login integration, profile field, DB activation.
- `includes/core/security.php` — capability matrix, `Security::can_*`, bypass for super admin / administrator / filter.
- `includes/db/dual-check-database.php` — table name, activation, token CRUD, `dual_check_settings()`.
- `includes/integrations/login-flow.php` — intercepts `wp_login`, code step UI, session transients.
- `includes/auth/` — code request cooldown, IP-bound code-step rate limit.
- `includes/email/login-email-builder.php` — wraps outbound login mail HTML/text.
- `includes/delivery/` — mail provider resolution (`registry.php`, `mail-provider-catalog.php`, `mail-credentials.php`), `delivery-options/*` (`Wp_Mail_Provider`, SendGrid, Postmark, Mailgun).
- `includes/admin/` — settings pages (general, capabilities, email), notices, user profile field.
- `includes/logging/logger.php` — optional JSON line log under uploads.
- `templates/email/default-template.php` — default subject/body/header/footer callables.

## Main flows

1. **Login:** After password succeeds, if the site requires 2FA (and filters do not skip), a token row is created, mail is sent, a short-lived session transient is set, and the user is sent to the code form (`?action=wp_dual_check`).
2. **Verify:** POST with code consumes the token, clears session data, and completes `wp_login` / redirect.
3. **Settings:** Forms POST to `admin-post.php` via `Settings_Save_Handler` (nonce + `Security::can_access_*` by `save_context`). `Settings_Page::sanitize()` merges and clamps input; `option_page_capability_*` keeps a hypothetical `options.php` path on `manage_options` only. Contexts: `main`, `email`, `permissions`.

## Important hooks and filters

| Hook / filter | Purpose |
| --- | --- |
| `wp_dual_check_site_requires_second_factor` | bool — whether the second step is required for this request. |
| `wp_dual_check_skip_second_factor` | bool, `WP_User` — skip the email step entirely. |
| `wp_dual_check_registered_mail_providers` | `array` of `{ id, label }` rows for the General settings provider dropdown (extend with custom ids; implement sending via `wp_dual_check_mail_provider` if not built-in). |
| `wp_dual_check_mail_provider` | **Final** mail adapter: receives the instance already resolved from settings (`Wp_Mail_Provider` when “selectable provider” is off, or factory output when on). Return a `Mail_Provider_Interface` to override. |
| `wp_dual_check_code_step_ip_binding_enabled` | Toggle IP-bound lockout for failed code attempts. |
| `wp_dual_check_code_step_ip_max_fails` / `wp_dual_check_code_step_ip_lockout_seconds` | Tune lockout. |
| `wp_dual_check_record_code_step_failure` | bool, reason, user id — whether a failure counts toward IP lockout. |
| `wp_dual_check_security_event` | Fired with event key + context for auditing / extensions. |
| `wp_dual_check_write_security_event_to_debug_log` | Override whether security events also hit `error_log`. |
| `wp_dual_check_client_ip` | Override detected client IP for rate limits. |
| `wp_dual_check_bypass_capability_matrix` | bool — full bypass of cap matrix checks. |
| `wp_dual_check_user_can` | bool, context (`main` / `email`), array of caps — final OR check after matrix. |

Default email fragments are filterable via `wp_dual_check_email_default_subject`, `wp_dual_check_email_default_body`, `wp_dual_check_email_default_header`, `wp_dual_check_email_default_footer` (see `Login_Email_Builder`).

## Settings and sanitize contexts

The option row `wp_dual_check_settings` is one array. On save, hidden field `save_context` selects how `Settings_Page::sanitize()` merges input:

- **`main`** — general 2FA policy, numeric limits, debug logging, “use custom email template” flag, **mail provider** (`mail_custom_provider_enabled`, `mail_provider_id`, API keys / Mailgun domain), preset capability pool entries used by the matrix UI.
- **`email`** — subject/body/header/footer HTML and colour fields (gated by `Security::can_access_email_template()` and custom-template option).
- **`permissions`** — `cap_context_main`, `cap_context_email`, `cap_custom` lines (gated by `Security::can_access_main_settings()`; self-lockout prevention keeps at least one main context cap).

`Settings_Save_Handler` calls `Settings_Page::sanitize()` before `update_option()`, so all branches (including capability gates inside `sanitize()`) apply to admin-post saves.

`Settings_Page::normalize_capability_arrays()` runs when reading defaults so missing keys get sane lists.

### Mail delivery (built-in providers)

Resolution lives in `get_default_mail_provider()` (`includes/delivery/registry.php`):

1. If `mail_custom_provider_enabled` is empty/false → `Wp_Mail_Provider` (WordPress `wp_mail()`).
2. If true → `create_mail_provider_from_settings()` (`mail-provider-catalog.php`) using `mail_provider_id`: `wp_mail`, `sendgrid`, `postmark`, or `mailgun` (must remain in the filtered registry list).
3. Then `apply_filters( 'wp_dual_check_mail_provider', $provider )`.

HTTP providers use `wp_remote_post` (15s timeout); **do not** log API keys or full provider responses in debug code.

**Secrets:** optional `wp-config.php` constants override the database (and the admin UI omits password fields when a constant is set):

| Constant | Purpose |
| --- | --- |
| `WP_DUAL_CHECK_SENDGRID_API_KEY` | SendGrid API key |
| `WP_DUAL_CHECK_POSTMARK_SERVER_TOKEN` | Postmark server token |
| `WP_DUAL_CHECK_MAILGUN_API_KEY` | Mailgun private API key |
| `WP_DUAL_CHECK_MAILGUN_DOMAIN` | Mailgun sending domain (e.g. `mg.example.com`) |
| `WP_DUAL_CHECK_MAILGUN_REGION` | `us` or `eu` (API host) |

Option keys (when constants unset): `mail_sendgrid_api_key`, `mail_postmark_server_token`, `mail_mailgun_api_key`, `mail_mailgun_domain`, `mail_mailgun_region` (`us` / `eu`). On save, **empty** password-style POST values **preserve** the previous stored secret (so other settings can be saved without re-pasting keys).

**From address:** HTTP APIs use `get_option( 'admin_email' )` as the sender; ensure it is valid and allowed by your transactional provider.

**Testing:** With “Use custom email template” enabled, **Login Email Template → Send test email** uses the same `get_default_mail_provider()` path as live login codes.

## Admin notices

`Settings_Notices::GROUP` is `wp_dual_check`. `Settings_Notices::render()` also prints `settings_errors('general')` so core-style success notices appear alongside plugin-specific notices after redirects from `admin-post.php` saves.

## Uninstall

See README “Uninstall”. `uninstall.php` intentionally does not delete arbitrary `_transient_*` rows; plugin transients use TTLs.
