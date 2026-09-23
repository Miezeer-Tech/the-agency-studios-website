# Deploying to GoDaddy

The site is static HTML except for three PHP pieces: `api/booking.php` (receives the booking forms), `api/agreement.php` (receives the signed rental agreement from the hidden `/agreement/` page) and `admin/` (Shawn's sign-in to see every request and signed agreement). GoDaddy Linux Web Hosting runs PHP 8 with SQLite, which is all this needs.

1. Upload everything in this folder to the domain's document root. On this account that is `public_html/theagencystudio.com/` (cPanel's Git Version Control deploys there through `.cpanel.yml`; SFTP works too, shell access is off).
2. Open `https://theagencystudio.com/admin/setup.php` once. It asks for the studio Gmail address, an admin password, and the Google App Password, and writes `api/config.php` for you. Do it right after deploying: the page switches itself off once a password exists. (Manual alternative: copy `api/config.example.php` to `api/config.php` and fill in:
   - `smtp_user` / `from_email`: Shawn@theagencies.net
   - `smtp_pass`: a Google **App Password** for that mailbox (Google Account → Security → 2-Step Verification → App passwords). Not the normal password.
   - `admin_pass_hash`: run this on any machine with PHP and paste the output:
     `php -r "echo password_hash('choose-a-password', PASSWORD_DEFAULT), PHP_EOL;"`)
3. Make sure `api/data/` is writable by PHP (755 usually works on GoDaddy; use 775 if bookings fail to save).
4. Send a test booking from `/podcast.html#book`. Shawn should get a plain-text email with `booking-request-1.pdf` attached, and the request appears at `/admin/`.
5. Set `site_url` in `api/config.php` to the live address so agreement links are right.

## Rental agreements

1. In `/admin/`, open **New agreement**, fill in Exhibit A (rates, fees, amenities; totals compute themselves), enter the client's company and email, and save. The agreement is created at `/agreement/<company>-<date>-<time>` and that link is emailed to the client. It is not linked from anywhere on the site and the page carries a noindex tag.
2. The client opens the link, sees the studio's numbers locked in, fills in their blanks with the **Next** button, draws a signature, and submits.
3. Both the client and `to_email` get `studio-rental-agreement-<n>.pdf`. The admin list shows every agreement as **not signed** or **signed**, with a PDF button once signed and a **Resend link** button before.
4. If terms change, create a new agreement; the date and time in the link tell the versions apart. A signed link cannot be signed twice.

The agreement text lives once, in `agreement/agreement.json`; the page, the admin form, and the PDF all read it, so edit it there. `agreement/.htaccess` turns the clean link into the page on Apache. The original blank template is `assets/Studio-Rental-Agreement-2026.pdf`.

Notes
- `api/config.php` and `api/data/` are excluded from git on purpose. Never commit them.
- The two `.htaccess` files stop Apache from serving the database or config. Keep them.
- If the API is missing (for example on the GitHub Pages preview), the forms fall back to opening the visitor's mail app addressed to Shawn.
- Gmail sending limit is about 500 messages a day, far above booking volume.
