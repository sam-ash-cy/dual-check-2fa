# WP Dual Check

Email-based second step after WordPress accepts your password. Pending state and code expiry use **transients**. Mail is sent with **Symfony Mailer** and a **DSN** (SMTP), not `wp_mail`.

## Requirements

- PHP 8.2+ (see `composer.json`)
- WordPress 6.0+
- Run Composer inside this folder:

```bash
cd wp-2fa
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

Under **Settings → WP Dual Check** you can change expiry, attempts, resend cooldown, from name/email, and REST.

## Enabling for a user

Profile: **Email two-factor login (WP Dual Check)** → enable **Email dual check**. Optional delivery email overrides `user_email`.

## REST (optional)

When enabled in settings:

- `POST /wp-json/dual-check/v1/verify` — body: `token`, `code`
- `POST /wp-json/dual-check/v1/resend` — body: `token`

## Filters

- `wp_dual_check_email_subject` — `( $subject, $user )`
- `wp_dual_check_email_body` — `( $body, $user, $plain_code )`

Legacy hooks `wp2fa_email_subject` / `wp2fa_email_body` are **not** fired; update any custom code to the names above.

## License

GPL-2.0-or-later
