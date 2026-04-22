# Dual Check 2FA — developer notes

## Layout

- Main bootstrap PHP file (`wp-dual-check.php` in this repo) — loads DB helpers and `PluginLoad`.
- `uninstall.php` — delete-only cleanup when the plugin is removed.
- `includes/core/plugin-load.php` — wires admin menus, login integration, profile field, DB activation.
- `includes/core/security.php` — capability matrix, `Security::can_*`, bypass for super admin / administrator / filter.
- `includes/db/dual-check-database.php` — table name, activation, token CRUD, `dual_check_settings()`.
- `includes/integrations/login-flow.php` — intercepts `wp_login`, code step UI, session transients.
- `includes/auth/` — code request cooldown, IP-bound code-step rate limit.
- `includes/email/login-email-builder.php` — wraps outbound login mail HTML/text.
- `includes/admin/` — settings pages (general, capabilities, email), notices, user profile field.
- `includes/logging/logger.php` — optional JSON line log under uploads.
- `templates/email/default-template.php` — default subject/body/header/footer callables.

## Main flows

1. **Login:** After password succeeds, if the site requires 2FA (and filters do not skip), a token row is created, mail is sent, a short-lived session transient is set, and the user is sent to the code form (`?action=dual_check_2fa`).
2. **Verify:** POST with code consumes the token, clears session data, and completes `wp_login` / redirect.
3. **Settings:** Registered under `Settings_Page::MENU_SLUG` with `options.php` POST; `Settings_Page::sanitize()` branches on `save_context`: `main`, `email`, `permissions`.

## Important hooks and filters

| Hook / filter | Purpose |
| --- | --- |
| `dual_check_2fa_site_requires_second_factor` | bool — whether the second step is required for this request. |
| `dual_check_2fa_skip_second_factor` | bool, `WP_User` — skip the email step entirely. |
| `dual_check_2fa_mail_provider` | Choose mail delivery adapter (see `includes/delivery/`). |
| `dual_check_2fa_code_step_ip_binding_enabled` | Toggle IP-bound lockout for failed code attempts. |
| `dual_check_2fa_code_step_ip_max_fails` / `dual_check_2fa_code_step_ip_lockout_seconds` | Tune lockout. |
| `dual_check_2fa_record_code_step_failure` | bool, reason, user id — whether a failure counts toward IP lockout. |
| `dual_check_2fa_security_event` | Fired with event key + context for auditing / extensions. |
| `dual_check_2fa_write_security_event_to_debug_log` | Override whether security events also hit `error_log`. |
| `dual_check_2fa_client_ip` | Override detected client IP for rate limits. |
| `dual_check_2fa_bypass_capability_matrix` | bool — full bypass of cap matrix checks. |
| `dual_check_2fa_user_can` | bool, context (`main` / `email`), array of caps — final OR check after matrix. |

Bundled default email fragments come from the `dual_check_2fa_email_default_*` functions in `templates/email/default-template.php` (see `Login_Email_Builder::DEFAULT_TEMPLATE_FN`).

## Settings and sanitize contexts

The option row `dual_check_2fa_settings` is one array. On save, hidden field `save_context` selects how `Settings_Page::sanitize()` merges input:

- **`main`** — general 2FA policy, numeric limits, debug logging, “use custom email template” flag, preset capability pool entries used by the matrix UI.
- **`email`** — subject/body/header/footer HTML and colour fields (gated by `Security::can_access_email_settings()` and custom-template option).
- **`permissions`** — `cap_context_main`, `cap_context_email`, `cap_custom` lines (gated by `Security::can_access_permissions_settings()`; self-lockout prevention keeps at least one main context cap).

`Settings_Page::normalize_capability_arrays()` runs when reading defaults so missing keys get sane lists.

## Admin notices

`Settings_Notices::GROUP` is `dual_check_2fa`. `Settings_Notices::render()` also prints `settings_errors('general')` so core’s “Settings saved.” after `options.php` appears alongside plugin-specific notices.

## Uninstall

See README “Uninstall”. `uninstall.php` intentionally does not delete arbitrary `_transient_*` rows; plugin transients use TTLs.
