# WP Dual Check

Email-based second step after WordPress accepts your password. Pending state and code expiry use **transients**. Mail is sent with **Symfony Mailer** and a **DSN** (SMTP), not `wp_mail`.

## Requirements

- PHP 8.2+ (see `composer.json`)
- WordPress 6.0+
- Run Composer inside this folder:

```bash
cd wp-dual-check
composer install
```

## Configuration

### Secrets (environment only)

Set via **environment variables**, **`.env`** in the plugin directory (optional), or **`wp-config.php` constants**. Priority: constant → `getenv()` → `.env`.

| Variable / constant | Purpose |
|---------------------|---------|
| `WP_DUAL_CHECK_MAILER_DSN` | Symfony Mailer DSN (preferred) |
| `WP_DUAL_CHECK_SECRET` | Optional HMAC secret (preferred) |

Legacy names `WP2FA_MAILER_DSN` and `WP2FA_SECRET` are still read if the new names are empty.

Example in `wp-config.php`:

```php
define( 'WP_DUAL_CHECK_MAILER_DSN', 'smtp://user:pass@mail.example.com:587' );
```

### Everything else (wp-admin)

Open **WP Dual Check** in the admin sidebar:

1. Turn on **Require email verification code for every user after a correct password (all logins)** so **every** WordPress login (including `wp-admin`) must complete the email code step.
2. Adjust expiry, attempts, resend cooldown, from name/email, and REST as needed.

### Optional: different inbox per user

Under **Users → Profile**, users with permission can set **Login code delivery email** if codes should not go to their normal account email.

## REST (optional)

When enabled in the WP Dual Check admin screen:

- `POST /wp-json/dual-check/v1/verify` — body: `token`, `code`
- `POST /wp-json/dual-check/v1/resend` — body: `token`

## Filters

- `wp_dual_check_email_subject` — `( $subject, $user )`
- `wp_dual_check_email_body` — `( $body, $user, $plain_code )`

Legacy hooks `wp2fa_email_subject` / `wp2fa_email_body` are **not** fired; update any custom code to the names above.

## License

GPL-2.0-or-later
