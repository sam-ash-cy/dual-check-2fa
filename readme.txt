=== Dual Check 2FA ===
Contributors: samuelashman
Tags: two-factor, 2fa, login, security, email
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds an email verification code after the user has logged in on the standard WordPress login.

== Description ==

Dual Check 2FA adds a second step to the normal WordPress login. After a user enters the right username and password on `wp-login.php`, the plugin emails them a short code. They enter that code on a follow-up screen to finish signing in.

= What you get =

* Email code step after a correct password, on the standard login form.
* One switch to require it site-wide (off by default so you don't lock yourself out by accident).
* REST API, XML-RPC, and cron requests skip the code step by default so automated tools keep working. This is filterable.
* Codes are hashed in the database (never stored in plain text), tied to a single challenge, single-use, and any older unused code is invalidated when a new one is issued.

= Controls you can tune =

* Code length, lifetime, and max wrong attempts per code.
* Resend cooldown so a user can't keep requesting new codes back to back.
* Optional IP-aware lockout: after repeated wrong codes from the same IP for the same user, the code step is temporarily blocked. Max failures and lockout length are configurable (or can be disabled with a filter).

= Email =

* Default login email is HTML with sensible defaults — works out of the box.
* Optional custom template mode with subject, body, header, footer, and colour fields. Placeholders like `[site-name]`, `[code]`, and `[user-login]` are supported.
* "Send test email" button on the admin screen when custom templates are enabled.
* Mail delivery is pluggable through the `dual_check_2fa_mail_provider` filter. Default uses `wp_mail()`, so any SMTP plugin you already use will work.

= Admin experience =

* Dedicated **Dual Check 2FA** admin area with General, Capabilities, and Login Email Template pages.
* Capability matrix (OR logic) so you can let non-admins manage settings or the email template without giving them full admin rights.
* Self-lockout guard: the plugin refuses to save a capability change that would remove your own access to the main settings.
* Super admins (multisite) and users with the Administrator role can bypass the capability matrix for compatibility. This is also filterable.

= Optional per-user alternate email =

If you enable "2FA delivery email on profile", each user gets a profile field where they can set a different address just for login codes. If they leave it blank, their normal account email is used.

= Open source =

Released under GPLv2 or later — the same license family as WordPress itself. You can use it, read the code, modify it, and share it under those terms.

== Installation ==

= Before you start =

1. Make sure your site can send email. Send yourself a test email from any other source (contact form, password reset, etc.). If those don't arrive, fix email delivery first — otherwise no one will get their login codes. An SMTP plugin (for example WP Mail SMTP, FluentSMTP, Post SMTP) with a real mail provider is strongly recommended.
2. Keep a second admin user or a way to access your server (FTP, SSH, hosting file manager) in case email breaks and you need to disable the plugin.

= Install the plugin =

1. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
2. Upload the plugin zip and click **Install Now**, then **Activate**.
3. (Or) upload this plugin’s folder to `/wp-content/plugins/` over FTP/SFTP and activate from the **Plugins** screen.

= Turn it on =

1. Go to **Dual Check 2FA → General** in the admin menu.
2. Once that works, switch on the site-wide requirement for everyone to be able to have 2FA and save.

= Sensible starting settings =

* Code length: 6
* Code lifetime: 10 minutes
* Max wrong attempts per code: 5
* Resend cooldown: 30–60 seconds
* IP lockout: on, with a few minutes of lockout after several wrong attempts

== Frequently Asked Questions ==

= What if email delivery is slow or unreliable? =

The plugin can only send as fast as WP Mail can send it.

= What if I get locked out? =

A few options, in order of how disruptive they are:

1. Log in as a second admin account and turn off the site-wide requirement, or change the required-for-everyone setting.
2. Rename this plugin’s folder under `wp-content/plugins/` over FTP/SFTP or your host's file manager. WordPress will deactivate the plugin on the next admin page load, and you can log in normally. Rename it back afterwards to re-enable it.
3. If you have database access, delete the row for `dual_check_2fa_settings` in the `wp_options` table. The plugin will fall back to defaults (second step not required) until you save settings again.

= Can some requests skip the code step? =

Yes. By default REST API, XML-RPC, and cron skip it so normal WordPress automation still works. You can change this with the `dual_check_2fa_skip_second_factor` filter.

= Does it work on multisite? =

Yes. Settings are per site. Super admins bypass the capability matrix by default (you can override this with a filter). Uninstall cleans up data on every site in the network.

= What does it store? =

* A custom table `{prefix}dual_check` for short-lived login tokens with helpful information/data.
* Two options: `dual_check_2fa_settings` (all plugin settings) and `dual_check_2fa_db_version` (schema marker).
* User meta `dual_check_2fa_email` if a user sets an alternate email on their profile.
* Short-lived transients prefixed with `dc2fa_` (they expire on their own).
* If debug logging is enabled: JSON log lines under `wp-content/uploads/dual-check-2fa/logs/debug.log`.

= What happens when I delete the plugin? =

Deleting from **Plugins → Delete** runs a cleanup that:

* Drops the `{prefix}dual_check` table on every site.
* Deletes the two plugin options on every site.
* Removes the alternate email user meta network-wide.
* Deletes the `dual-check-2fa` folder under each site's uploads (including logs), if present.

It does not touch transients directly — they expire on their own — and it does not touch any other plugin's data.

= Can I let an editor or custom role manage the settings without being an admin? =

Yes. Under **Dual Check 2FA → Capabilities** you can set which capabilities grant access to the main settings and to the email template separately. The check uses OR logic: any matching capability gets access. The plugin will not let you save a change that would lock yourself out of the main settings.

== Requirements ==

* WordPress 6.8 or newer.
* PHP 8.0 or newer.
* Outbound email that actually delivers to your users.

== Known issues and things to watch for ==

* **Spam folders.** Tell users to check there the first time. Ask them to whitelist or mark the sender as "not spam" so later codes land in the inbox.
* **Clock drift on the server.** Codes expire based on server time. If your server clock is badly wrong, codes may appear to expire immediately. Check NTP/time sync on the host.
* **Aggressive caching.** Some hosts cache the login page. If the code screen behaves oddly (same code accepted twice, blank page after submit), exclude `wp-login.php` from full-page caching.
* **Reverse proxies / CDNs and IP lockout.** The IP-aware lockout reads the client IP. If all traffic reaches WordPress from a single proxy IP, one user's failures can lock out others on the same address. Either terminate the real IP upstream (so WordPress sees `REMOTE_ADDR` correctly), or override it with the `dual_check_2fa_client_ip` filter, or turn off the IP binding with `dual_check_2fa_code_step_ip_binding_enabled`.
* **Automated tools (REST API, XML-RPC, cron) skip the second step by default.** If you want them covered too, override with the `dual_check_2fa_skip_second_factor` filter, but be aware that third-party apps using application passwords or similar cannot complete an email code step.
* **Menu visibility with custom capabilities.** WordPress submenu registration needs a single capability string. If you configure several capabilities for a context, the first one in the list is used for menu visibility. Users matching a later capability can still use the page by URL. If that matters for your site, put the "menu owner" capability first.

==
