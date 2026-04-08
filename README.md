# WP Dual Check

Email-based second step after WordPress accepts your password. Pending state and code expiry use **transients**. Mail can use **Symfony Mailer** (DSN, PHP `mail()`, or sendmail) or **`wp_mail()`**, per site default and optional per-user override.

## Requirements

- PHP 8.2+ (see `composer.json`)
- WordPress 6.0+
- The **`vendor/`** folder must be present (ship it in your distribution zip so end users never run Composer).

Developers: run Composer in this folder:

```bash
cd wp-dual-check
composer install
```

## Configuration

### Secrets (environment only)

Set via **environment variables**, **`.env`** in the plugin directory (optional), or **`wp-config.php` constants**. Priority: constant → `getenv()` → `.env`.

| Variable / constant | Purpose |
|---------------------|---------|
| `WP_DUAL_CHECK_MAILER_DSN` | Symfony Mailer DSN (generic SMTP, etc.) |
| `WP_DUAL_CHECK_SECRET` | Optional HMAC secret (preferred) |
| `WP_DUAL_CHECK_SENDGRID_API_KEY` | SendGrid API (overrides wp-admin field) |
| `WP_DUAL_CHECK_MAILGUN_API_KEY` | Mailgun private API key |
| `WP_DUAL_CHECK_MAILGUN_DOMAIN` | Mailgun sending domain |
| `WP_DUAL_CHECK_MAILGUN_REGION` | `us` or `eu` |
| `WP_DUAL_CHECK_SES_ACCESS_KEY` | Amazon SES access key ID |
| `WP_DUAL_CHECK_SES_SECRET_KEY` | Amazon SES secret |
| `WP_DUAL_CHECK_SES_REGION` | e.g. `us-east-1` |
| `WP_DUAL_CHECK_POSTMARK_TOKEN` | Postmark server token |
| `WP_DUAL_CHECK_GMAIL_ADDRESS` | Gmail address (Google SMTP transport) |
| `WP_DUAL_CHECK_GMAIL_APP_PASSWORD` | [Google app password](https://support.google.com/accounts/answer/185833) (not OAuth) |

Legacy names `WP2FA_MAILER_DSN` and `WP2FA_SECRET` are still read if the new names are empty.

**Gmail OAuth2 (API):** Symfony’s `google-mailer` bridge only supports **SMTP + app password**, not full OAuth. For OAuth, use **wp_mail** with a Google plugin, or the **`wp_dual_check_symfony_dsn`** filter to supply a custom DSN/transport.

Example in `wp-config.php`:

```php
define( 'WP_DUAL_CHECK_MAILER_DSN', 'smtp://user:pass@mail.example.com:587' );
```

### Everything else (wp-admin)

Open **WP Dual Check** in the admin sidebar:

1. Turn on **Require email verification code for every user after a correct password (all logins)** so **every** WordPress login (including `wp-admin`) must complete the email code step.
2. Choose **Default mail transport** — includes **SendGrid**, **Mailgun**, **Amazon SES**, **Postmark** (HTTP APIs), **Gmail (SMTP + app password)**, generic **DSN**, **wp_mail**, **PHP mail**, and **sendmail**.
3. Open **Mail Transport Providers** and pick the tab for your service (**SendGrid**, **Mailgun**, **Amazon SES**, **Postmark**, or **Gmail (SMTP)**), or use environment variables. Secret fields left blank keep the previous saved value.
4. Adjust expiry, attempts, resend cooldown, and from name/email as needed on **General**.

### Optional: different inbox or transport per user

Under **Users → Profile**, administrators (or anyone who can edit other users) can set **Login code mail transport** to override the site default. Any user who can edit the profile can set **Login code delivery email** if codes should not go to their normal account email.

## Filters

- `wp_dual_check_email_subject` — `( $subject, $user )`
- `wp_dual_check_email_body` — `( $body, $user, $plain_code )`
- `wp_dual_check_mail_transport_choices` — `( $choices )` associative array of transport id => label
- `wp_dual_check_symfony_dsn` — `( $dsn, $transport, $user )` return a DSN string to override or replace built-in API DSNs (e.g. custom OAuth bridge)

Legacy hooks `wp2fa_email_subject` / `wp2fa_email_body` are **not** fired; update any custom code to the names above.

## License

GPL-2.0-or-later
