# Deploying to GoDaddy

The site is static HTML except for two PHP pieces: `api/booking.php` (receives the booking forms) and `admin/` (Shawn's sign-in to see every request). GoDaddy Linux Web Hosting runs PHP 8 with SQLite, which is all this needs.

1. Upload everything in this folder to `public_html/` (or the domain's root folder) with the File Manager or SFTP.
2. Copy `api/config.example.php` to `api/config.php` on the server and fill in:
   - `smtp_user` / `from_email`: Shawn@theagencies.net
   - `smtp_pass`: a Google **App Password** for that mailbox (Google Account → Security → 2-Step Verification → App passwords). Not the normal password.
   - `admin_pass_hash`: run this on any machine with PHP and paste the output:
     `php -r "echo password_hash('choose-a-password', PASSWORD_DEFAULT), PHP_EOL;"`
3. Make sure `api/data/` is writable by PHP (755 usually works on GoDaddy; use 775 if bookings fail to save).
4. Send a test booking from `/podcast.html#book`. Shawn should get a plain-text email with `booking-request-1.pdf` attached, and the request appears at `/admin/`.

Notes
- `api/config.php` and `api/data/` are excluded from git on purpose. Never commit them.
- The two `.htaccess` files stop Apache from serving the database or config. Keep them.
- If the API is missing (for example on the GitHub Pages preview), the forms fall back to opening the visitor's mail app addressed to Shawn.
- Gmail sending limit is about 500 messages a day, far above booking volume.
