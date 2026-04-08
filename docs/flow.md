# WP Dual Check login flow

1. **`Login_Intercept::maybe_start_challenge`** runs on `wp_authenticate_user` after the password is valid. If **`require_all_logins`** is enabled in **WP Dual Check** settings, it creates a **pending session** (transient), emails a **6-digit code** (hashed in the transient), then redirects to `wp-login.php?wdc_challenge=TOKEN`.

2. **`login_message`** renders **`templates/challenge-form.php`**: verify and resend forms (`wdc_*` fields).

3. **`Login_Intercept::handle_verify_post`** validates the nonce and code, then **`wp_set_auth_cookie`**.

Mail is sent with **`Mailer`**: site default and optional per-user transport (**DSN**, **SendGrid/Mailgun/SES/Postmark** HTTP APIs, **Gmail SMTP + app password**, **wp_mail**, **PHP mail**, **sendmail**). REST endpoints are not used.

Core namespace: **`WPDualCheck`**. Composer package: **`sa/wp-dual-check`**.
