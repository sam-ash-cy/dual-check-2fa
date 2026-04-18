# WP Dual Check

**Open source** WordPress plugin (see [License](#license)). It adds an email-based second step after a correct password on the standard login form. Site owners can require the step for everyone, tune code lifetime and limits, customize login email layout (optional), and restrict who may change settings via a capability matrix.

## Requirements

- WordPress 6.0 or newer (use a supported release in production).
- PHP 8.0 or newer.
- Outbound email must work so users can receive verification codes.

## Features

### Login and second step

- **Email code after password** on the normal `wp-login.php` flow: when the site requires it, a one-time code is sent after a correct username/password, then the user completes login on a **dedicated “security code” step** (no long session id in the URL; pending state uses an **HttpOnly** cookie with **SameSite Strict** where supported).
- **Site-wide policy** to require the second step for **everyone** (off by default so you do not lock out users by surprise).
- **Skips the email step** for REST API, XML‑RPC, and cron by default so automated flows keep working; fully **filterable** (`wp_dual_check_skip_second_factor`, `wp_dual_check_site_requires_second_factor`).
- **Hashed codes** stored in the database (not plaintext), bound to a **specific challenge row**, **single-use** consumption, and **invalidation** of older unconsumed codes when a new one is issued.

### Limits and abuse resistance

- Configurable **code lifetime**, **code length**, and **maximum wrong attempts** per challenge before that code is exhausted.
- **Resend cooldown** so users cannot spam new codes immediately.
- Optional **IP-aware code-step lockout**: after repeated wrong codes for the same **IP + user** pair, the code step is temporarily blocked; **max failures** and **lockout duration** are configurable (can be tuned or disabled via filters).

### Email and delivery

- Login messages are **HTML email** with sensible defaults; optional **custom template** mode (subject, body, header/footer HTML, link/header/footer colours) with placeholders such as site name, code, user login, expiry text, and site URL (`[site-name]`, `[code]`, etc.).
- **Send test email** from the admin screen (to the current user’s address) when custom templates are enabled — it uses the **same mail provider** as live login codes, so it is the quickest way to verify SendGrid, Postmark, Mailgun, or `wp_mail` after you change **General → Mail delivery for security codes**.
- **Mail provider choice (General settings):** leave “Use a selectable mail provider…” **unchecked** to always send through WordPress **`wp_mail()`** (including any SMTP plugin). When **checked**, pick **WordPress wp_mail()** in the list or a **transactional HTTP** provider (**SendGrid**, **Postmark**, **Mailgun**). Save after enabling the checkbox so the provider dropdown and credential fields appear; change provider and save again if needed. API keys can live in the database or in **`wp-config.php`** constants (see [DEVELOPMENT.md](DEVELOPMENT.md) for names and option keys).
- **Pluggable overrides:** `wp_dual_check_registered_mail_providers` extends the dropdown; `wp_dual_check_mail_provider` replaces the resolved `Mail_Provider_Interface` instance (runs last).

### User and admin experience

- Optional **profile field** for an **alternate email** used only for login codes (when the administrator enables “2FA delivery email on profile”).
- **WP Dual Check** admin area: **General** settings, **Capabilities** (who may access main settings vs login email template), and **Login Email Template** (when custom email is enabled).
- **Capability matrix** with **OR** semantics for runtime access; settings are saved through **`admin-post.php`** with explicit capability checks so delegated roles can persist changes without being blocked by core `options.php` behaviour alone.
- **Self-lockout guard** when editing capabilities: changes that would remove your own access to “main” context are rejected with a notice.
- Multisite **super admins** and users with the **Administrator** role may **bypass** the matrix for compatibility (also overridable with a filter).

## What it stores

- **Database:** a custom table `{prefix}dual_check` for short-lived login tokens.
- **Options:** `wp_dual_check_settings` (all plugin settings) and `wp_dual_check_db_version` (schema marker).
- **User meta:** `wp_dual_check_2fa_email` when a user sets an alternate address for codes (profile field).
- **Transients:** session and rate-limit keys prefixed with `wpdc_` (they expire on their own; uninstall does not scan the options table for them).
- **Uploads (optional):** if “Debug logging” is enabled, JSON lines may be written under `wp-content/uploads/wp-dual-check/logs/debug.log`.

## Capability matrix and admin menus

Runtime checks use **OR** logic: a user who has **any** of the capabilities listed for a context (main settings, email template, etc.) may perform the corresponding action.

WordPress submenu registration still requires a single **`$capability` string** for menu visibility. This plugin uses the **first** capability from the relevant list for that string. Users who match a later cap but not the first may still use the screen if they reach it by URL and pass the same OR check inside `render_*` / save handlers. Prefer putting the broadest or intended “menu owner” cap first if that matters for your site.

Multisite super admins, users with the **administrator** role, and anything that makes `wp_dual_check_bypass_capability_matrix` return true bypass the matrix for compatibility.

## Uninstall

Deleting the plugin from **Plugins → Delete** runs `uninstall.php`, which:

- Drops the `{prefix}dual_check` table on each site (multisite: every blog in `wp_blogs`).
- Deletes `wp_dual_check_settings` and `wp_dual_check_db_version` per site.
- Removes all `wp_dual_check_2fa_email` user meta network-wide.
- Deletes the uploads folder `wp-dual-check` under each site’s upload base (including log files), if present.

It does **not** bulk-delete unrelated transients or other plugins’ data. Transient keys used by this plugin expire naturally.

## Development

See [DEVELOPMENT.md](DEVELOPMENT.md) for layout, hooks, mail provider resolution, `wp-config` secret constants, and how settings contexts (`main`, `email`, `permissions`) are sanitized.

## License

This project is **free / open source software** licensed under the **GNU General Public License v2.0 or later (GPLv2+)** — the same family of license used by WordPress. You may use, study, share, and modify it under those terms. See [LICENSE](LICENSE), the `License` and `License URI` headers in `wp-dual-check.php`, and the [GNU GPL v2](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html) full text.
