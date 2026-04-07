# WP 2FA login flow

1. **`Login_Intercept::maybe_start_challenge`** runs on `wp_authenticate_user` after the password is valid. If the user has meta `wp2fa_enabled`, it creates a **pending session** (transient keyed by an opaque token), emails a **6-digit code** (hashed in the transient), then **redirects** to `wp-login.php?wp2fa_challenge=TOKEN`.

2. **`login_message`** renders **`templates/challenge-form.php`**: verify form and optional resend (rate-limited per user).

3. **`Login_Intercept::handle_verify_post`** (on `login_init`) checks the nonce, loads the transient, compares the submitted code with `hash_hmac` via **`Code::verify_plain_against_hash`**, then calls **`wp_set_auth_cookie`** and redirects to the stored `redirect_to` (validated).

4. **`Rest`** (only if enabled under **Settings → WP 2FA**) exposes the same verify/resend logic for JSON clients.

Core classes: `Config`, `Admin_Settings`, `Pending_Session`, `Code`, `Mailer`, `User_Settings`, `Login_Intercept`, `Rest`, `Plugin`.
