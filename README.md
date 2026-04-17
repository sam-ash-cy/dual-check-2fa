# WP Dual Check

**Open source** WordPress plugin (see [License](#license)). It adds an email-based second step after a correct password on the standard login form. Site owners can require the step for everyone, tune code lifetime and limits, customize login email layout (optional), and restrict who may change settings via a capability matrix.

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

## Releases and tagging

1. Bump **`Version:`** in `wp-dual-check.php` to match the release (e.g. `1.0.1`).
2. Commit and push, then create an annotated tag, for example:
   - `git tag -a v1.0.1 -m "Release v1.0.1"`
   - `git push origin v1.0.1`
3. The workflow [`.github/workflows/tag-archive.yml`](.github/workflows/tag-archive.yml) runs on tags like `v1.0.1` or `v1.0.0-beta.1`, builds `wp-dual-check-<tag>.zip` (with a top-level **`wp-dual-check/`** folder for WordPress uploads), and uploads it to a **GitHub Release** for that tag.

**Do you need a GitHub token?** For this workflow, **no**. GitHub Actions provides **`GITHUB_TOKEN`** automatically for the run. The workflow sets `permissions: contents: write` so that token can create the release and attach the zip. You only need a **personal access token (PAT)** in repo secrets if you later add steps that talk to another repo, a private API, or need scopes the default token does not have.

If the job fails with **403** on `gh release`, check **Settings → Actions → General → Workflow permissions** for this repository and allow **Read and write** (so `GITHUB_TOKEN` can publish releases).

## License

This project is **free / open source software** licensed under the **GNU General Public License v2.0 or later (GPLv2+)** — the same family of license used by WordPress. You may use, study, share, and modify it under those terms. See [LICENSE](LICENSE), the `License` and `License URI` headers in `wp-dual-check.php`, and the [GNU GPL v2](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html) full text.
