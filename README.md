# WP Dual Check

WordPress plugin that adds an email-based second step after a correct password on the standard login form. Site owners can require the step for everyone, tune code lifetime and limits, customize login email layout (optional), and restrict who may change settings via a capability matrix.

## Requirements

- WordPress 6.0 or newer (use a supported release in production).
- PHP 8.0 or newer.
- Outbound email must work so users can receive verification codes.

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

See [DEVELOPMENT.md](DEVELOPMENT.md) for layout, hooks, and how settings contexts (`main`, `email`, `permissions`) are sanitized.

## License

GPLv2 or later.
