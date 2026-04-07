# WP 2FA

Email-based second step after WordPress accepts your password. Pending state and code expiry use **transients**. Mail is sent with **Symfony Mailer** and a **DSN** (SMTP), not `wp_mail`.

## Requirements

- PHP 8.1+
- WordPress 6.0+
- Run Composer inside this folder:

```bash
cd wp-2fa
composer install
```

## Configuration

### Secrets (environment only)

Do **not** put SMTP credentials in the database. Set these via **environment variables**, **`.env`** in the plugin directory (optional), or **`wp-config.php` constants** (same names). Priority: constant → `getenv()` → `.env`.

| Variable / constant   | Purpose |
|-----------------------|---------|
| `WP2FA_MAILER_DSN`    | Symfony Mailer DSN, e.g. `smtp://user:pass@host:587` |
| `WP2FA_SECRET`        | Optional explicit secret for HMAC of codes (multi-server setups) |

Example in `wp-config.php`:

```php
define( 'WP2FA_MAILER_DSN', 'smtp://user:pass@mail.example.com:587' );
```

### Everything else (wp-admin)

Under **Settings → WP 2FA** you can change:

- Code / session expiry (seconds)
- Max wrong code attempts
- Resend cooldown (seconds)
- From email and from name (defaults: admin email / site title)
- Whether **REST** endpoints `wp2fa/v1` are registered

## Enabling 2FA for a user

Profile screen: **Email two-factor login (WP 2FA)** → enable the checkbox. Optional **delivery email** overrides `user_email` for the code.

## Object cache

Transients respect a persistent object cache if one is configured; otherwise they live in `wp_options` with an expiry timestamp.

## Security notes

- The challenge **token** in the URL is high-entropy; the **code** is only stored as an HMAC hash in the transient.
- **REST** is off until enabled in settings. When on, `POST /wp-json/wp2fa/v1/verify` with `token` + `code` can complete login—use HTTPS and consider edge rate limits.

## Filters

- `wp2fa_email_subject` — `( $subject, $user )`
- `wp2fa_email_body` — `( $body, $user, $plain_code )`

## REST (optional)

When enabled in **Settings → WP 2FA**:

- `POST /wp-json/wp2fa/v1/verify` — JSON body: `token`, `code`
- `POST /wp-json/wp2fa/v1/resend` — JSON body: `token`

## License

GPL-2.0-or-later
