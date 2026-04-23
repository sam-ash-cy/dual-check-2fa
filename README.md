# Dual Check 2FA

**Open source** WordPress plugin (see [License](#license)). It adds an email-based second step after a correct password on the standard login form. Site owners can require the step for everyone, tune code lifetime and limits, customize login email layout (optional), and restrict who may change settings via a capability matrix.

## Requirements

- WordPress 6.0 or newer (use a supported release in production).
- PHP 8.0 or newer.
- Outbound email must work so users can receive verification codes.

## Features

### Login and second step

- **Email code after password** on the normal `wp-login.php` flow: when the site requires it, a one-time code is sent after a correct username/password, then the user completes login on a **dedicated “security code” step** (no long session id in the URL by default; pending state uses an **HttpOnly** cookie with **SameSite Strict** where supported).
- **Site-wide policy** to require the second step for **everyone** (off by default so you do not lock out users by surprise).
- **Skips the email step** for REST API, XML‑RPC, and cron by default so automated flows keep working; fully **filterable** (`dual_check_2fa_skip_second_factor`, `dual_check_2fa_site_requires_second_factor`). Optional **per-user exemption** (admin-only profile checkbox) and **trusted devices** (“remember this browser” on the code step) when enabled.
- **Hashed codes** stored in the database (not plaintext), bound to a **specific challenge row**, **single-use** consumption, and **invalidation** of older unconsumed codes when a new one is issued.

### Limits and abuse resistance

- Configurable **code lifetime**, **code length**, and **maximum wrong attempts** per challenge before that code is exhausted.
- **Resend cooldown** so users cannot spam new codes immediately.
- Optional **IP-aware code-step lockout**: after repeated wrong codes for the same **IP + user** pair, the code step is temporarily blocked; **max failures** and **lockout duration** are configurable (can be tuned or disabled via filters).

### Email and delivery

- Login messages are **HTML email** with sensible defaults; optional **custom template** mode (subject, body, header/footer HTML, link/header/footer colours) with placeholders: **`[site-name]`**, **`[code]`**, **`[user-login]`**, **`[expires]`**, **`[site-url]`**.
- **Custom template guardrails:** if the body field is **not empty**, **`[code]`** must appear (any letter case) in at least one of subject, body, header, or footer—saves are rejected otherwise, and enabling custom mode on **General** is turned off if stored templates would violate that rule. Leave the body **empty** to use the built-in default body, which always includes the code.
- **Formatting:** the body is HTML; plain line breaks in the textarea are usually collapsed in mail clients. Use **`<p>`**, **`<br>`**, or a wrapper with **`white-space: pre-line`** if you want predictable spacing.
- **Send test email** from **General → Debugging** (to the current user’s address) using the **same mail provider** as live login codes. When custom templates are enabled, **Login Email Template** offers a **template-aware** test send (sample code in the template).
- **Mail provider choice (General settings):** leave “Use a selectable mail provider…” **unchecked** to always send through WordPress **`wp_mail()`** (including any SMTP plugin). When **checked**, pick **WordPress wp_mail()** in the list or a **transactional HTTP** provider (**SendGrid**, **Postmark**, **Mailgun**, **Amazon SES**). Save after enabling the checkbox so the provider dropdown and credential fields appear; change provider and save again if needed. API keys can live in the database or in **`wp-config.php`** constants (see [DEVELOPMENT.md](DEVELOPMENT.md) for names and option keys).
- **Pluggable overrides:** `dual_check_2fa_registered_mail_providers` extends the dropdown; `dual_check_2fa_mail_provider` replaces the resolved `Mail_Provider_Interface` instance (runs last).

### User and admin experience

- Optional **profile field** for an **alternate email** used only for login codes (when the administrator enables “2FA delivery email on profile”).
- Optional **masked delivery hint** on the code step (where the code was sent), filterable.
- **Dual Check 2FA** admin area: **General**, **Capabilities**, **Login Activity** (when recording is enabled), and **Login Email Template** (when custom email is enabled).
- **Capability matrix** with **OR** semantics for **main settings**, **email template**, and **login activity**; settings are saved through **`admin-post.php`** with explicit capability checks so delegated roles can persist changes without relying on core `options.php` alone.
- **Self-lockout guard** when editing capabilities: saves that would remove your own access to **main** or **login activity** contexts are rejected with a notice.
- Multisite **super admins** and users with the **Administrator** role may **bypass** the matrix for compatibility (also overridable with a filter).
- Optional **JSON debug log** under uploads when debug logging is enabled; optional **daily maintenance** (token GC, trusted-device expiry, login activity retention) driven by settings and filters.

## What it stores

- **Database:** `{prefix}dual_check` for short-lived login tokens; `{prefix}dual_check_events` for login activity (when enabled); `{prefix}dual_check_trusted_devices` for remembered browsers (when used).
- **Options:** `dual_check_2fa_settings` (all plugin settings), `dual_check_2fa_db_version`, `dual_check_2fa_events_db_version`, and `dual_check_2fa_trusted_devices_db_version` (schema markers).
- **User meta:** `dual_check_2fa_email` when a user sets an alternate address for codes (profile field); `dual_check_2fa_exempt` when an administrator marks a user exempt from 2FA (optional feature).
- **Transients:** session and rate-limit keys prefixed with `dc2fa_` (they expire on their own; uninstall does not scan the options table for them).
- **Uploads (optional):** if “Debug logging” is enabled, JSON lines may be written under `wp-content/uploads/dual-check-2fa/logs/debug.log`.

## Capability matrix and admin menus

Runtime checks use **OR** logic: a user who has **any** of the capabilities listed for a context (main settings, email template, login activity) may perform the corresponding action.

WordPress submenu registration still requires a single **`$capability` string** for menu visibility. This plugin uses the **first** capability from the relevant list for that string. Users who match a later cap but not the first may still use the screen if they reach it by URL and pass the same OR check inside `render_*` / save handlers. Prefer putting the broadest or intended “menu owner” cap first if that matters for your site.

Multisite super admins, users with the **administrator** role, and anything that makes `dual_check_2fa_bypass_capability_matrix` return true bypass the matrix for compatibility.

## Uninstall

Deleting the plugin from **Plugins → Delete** runs `uninstall.php`, which:

- Drops the `{prefix}dual_check`, `{prefix}dual_check_events`, and `{prefix}dual_check_trusted_devices` tables on each site (multisite: every blog in `wp_blogs`).
- Deletes `dual_check_2fa_settings`, `dual_check_2fa_db_version`, `dual_check_2fa_events_db_version`, and `dual_check_2fa_trusted_devices_db_version` per site.
- Removes all `dual_check_2fa_email` and `dual_check_2fa_exempt` user meta network-wide.
- Clears the `dual_check_2fa_token_gc` cron hook.
- Deletes the uploads folder `dual-check-2fa` under each site’s upload base (including log files), if present.

It does **not** bulk-delete unrelated transients or other plugins’ data. Transient keys used by this plugin expire naturally.

## Development

See [DEVELOPMENT.md](DEVELOPMENT.md) for repository layout, **filters and actions** (including capability contexts and mail resolution), `wp-config` secret constants, save contexts (`main`, `email`, `permissions`), and debug log event names.

## License

This project is **free / open source software** licensed under the **GNU General Public License v2.0 or later (GPLv2+)** — the same family of license used by WordPress. You may use, study, share, and modify it under those terms. See [LICENSE](LICENSE), the `License` and `License URI` headers in the main plugin bootstrap file, and the [GNU GPL v2](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html) full text.
