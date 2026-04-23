=== Dual Check 2FA ===
Contributors: samuelashman
Tags: two-factor, 2fa, login, security, email
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.1.0

License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==

Dual Check 2FA runs after a correct username and password on `wp-login.php`.
It emails an OTP (One time Password) to the users email (configurable to change if needed per account).
Once the user receives the email, they can enter the code that has been given to them to log in.
Administrators can configure capabilities (to access this plugin), monitor login activity, change mail providers, and customise the email sent among other things. 

= Why use it =

* **Security-minded by design:** Codes are stored hashed, bound to one challenge, single-use, and superseded when a new code is issued. Pending login uses an HttpOnly cookie with SameSite Strict.
* **Customisable without code first:** Code length, lifetime, max wrong guesses per code, resend cooldown, optional IP-aware lockout (per user + IP, with configurable thresholds), trusted-device duration, login activity retention, and mail provider choice are all in **Dual Check 2FA → General** (plus dedicated screens for template and permissions).
* **Easy to operate:** One place for policy, mail, and limits; test send uses the same delivery path as live codes; capability matrix so editors or custom roles can own the email template (or activity list) without full admin—without letting you save a change that locks *you* out of main settings.

= Login flow =

* Second factor only after a successful password on the standard login.
* Optional **require dual-check for everyone** (off by default so you do not strand the site).
* Optional **trusted devices** (“remember this browser”) with configurable remember duration.
* Optional **per-user exemption** when the site allows it.
* Optional **separate 2FA delivery address** on the user profile when enabled; otherwise the account email is used.
* On the code step, optional **masked hint** showing where the email was sent (filterable).

= Abuse resistance and automation =

* Wrong-code limit per issued code before that code is dead.
* Minimum delay between “send a new code” requests.
* Optional **IP + user binding** on the code step: repeated failures from the same IP for the same user can trigger a short lockout (tunable or disabled via filters).
* By default **REST API**, **XML-RPC**, and **WP Cron** skip the email step so integrations keep working; override with `dual_check_2fa_skip_second_factor` if your risk model differs.

= Email and delivery =

* Default HTML email works out of the box with `wp_mail()` (including any SMTP plugin you already use).
* Optional **built-in HTTP APIs:** SendGrid, Postmark, Mailgun, Amazon SES—with credentials in settings or via `wp-config` constants where supported.
* **`dual_check_2fa_mail_provider`** filter for a custom sender or extra providers.
* Optional **custom template** mode: subject, body, header, footer, colours; placeholders such as `[site-name]`, `[code]`, `[user-login]`.
* **Send test email** on the Debugging section; when custom templates are on, the test respects that template.

= Admin =

* **Dual Check 2FA** menu: General, **Login Email Template**, **Capabilities**, and **Login Activity** (when recording is enabled).
* **Capabilities** screen: separate OR-lists for main settings, email template, and login activity; administrators / super admins bypass the matrix by default (`dual_check_2fa_bypass_capability_matrix` to change).

= Observability and cleanup =

* Optional **login activity** list with configurable retention.
* Optional **JSON debug log** under uploads (separate from core `debug.log` behaviour described in code paths).
* Optional **scheduled cleanup** of old token rows.
* **`dual_check_2fa_security_event`** action (and optional debug logging hook) for token issue/verify and related audit signals.

= Multisite and data lifecycle =

* Settings are **per site** in a network.
* Uninstall removes plugin tables, options, scheduled events, uploads folder (including logs), and relevant user meta across the network. See FAQ for what is stored while active.

GPLv2 or later, same licence family as WordPress.

== Installation ==

= Before you start =

1. Confirm the site can deliver mail (password reset, contact form, etc.). If nothing arrives, fix delivery before relying on codes. A transactional provider or SMTP plugin is strongly recommended.
2. Keep a second administrator account or filesystem access so you can recover if mail breaks.

= Install =

1. **Plugins → Add New → Upload Plugin**, install the zip, activate; or upload the plugin folder to `wp-content/plugins/` and activate from **Plugins**.

= Enable =

1. Open **Dual Check 2FA → General**.
2. Send a test email; when satisfied, enable **require dual-check for everyone** if that matches your policy.

= Sensible starting values =

* Code length: 6  
* Code lifetime: 10 minutes  
* Max wrong attempts per code: 5  
* Resend cooldown: 30–60 seconds  
* IP lockout: on, short lockout after several wrong codes  

== Frequently Asked Questions ==

= What if email is slow or unreliable? =

The plugin sends through your configured path as fast as that path allows. Use a reputable transactional provider if deliverability matters.

= What if I get locked out? =

1. Sign in with another admin and relax policy or exemptions.  
2. Rename the plugin folder under `wp-content/plugins/` via SFTP or the host file manager so WordPress deactivates it; sign in; rename back.  
3. With database access, remove the `dual_check_2fa_settings` option row to fall back to defaults until you save settings again.

= Can some requests skip the code step? =

Yes. REST, XML-RPC, and cron skip by default. Use `dual_check_2fa_skip_second_factor` to change behaviour.

= Does it work on multisite? =

Yes. Settings are per site. Super admins bypass the capability matrix unless you filter that. Uninstall cleans every site in the network.

= What does it store? =

* Tables: `{prefix}dual_check` (tokens), `{prefix}dual_check_events` (login activity), `{prefix}dual_check_trusted_devices` (remembered browsers).  
* Options: `dual_check_2fa_settings` and schema version keys.  
* User meta: `dual_check_2fa_email` (alternate delivery address), `dual_check_2fa_exempt` (when exemptions are enabled).  
* Transients prefixed `dc2fa_` (short-lived).  
* Optional debug log: `wp-content/uploads/dual-check-2fa/logs/debug.log` when that mode is on.

= What happens when I delete the plugin? =

Deleting from **Plugins** runs cleanup: drops plugin tables on each site, deletes plugin options and scheduled GC event, removes the listed user meta network-wide, and deletes the `dual-check-2fa` folder under each site’s uploads. Transients are not bulk-deleted; they expire on their own.

= Can a non-admin manage settings or the template? =

Yes. **Capabilities** assigns OR-capability lists for main settings, email template, and login activity. The plugin blocks saves that would remove your own access to contexts you currently satisfy.

== Requirements ==

* WordPress 6.0 or newer.  
* PHP 8.0 or newer.  
* Outbound email that reaches real inboxes.

== Known issues and things to watch for ==

* **Spam folders** on first delivery; ask users to whitelist the sender.  
* **Server clock:** expiry uses server time; fix NTP if codes appear to die instantly.  
* **Full-page caching** of `wp-login.php` can break the code step; exclude it from aggressive cache.  
* **Reverse proxies:** IP lockout uses the IP WordPress sees. If everyone appears as one proxy IP, tune `dual_check_2fa_client_ip`, disable binding with `dual_check_2fa_code_step_ip_binding_enabled`, or fix upstream forwarded headers.  
* **REST/XML-RPC/cron** skip the second step by default; forcing them through email may break clients that cannot complete an interactive step.  
* **Submenu capability:** WordPress needs one capability string for menu visibility; with multiple caps, the first listed “owns” the menu. Users who match a later cap can still open the screen by URL—order caps with that in mind.

