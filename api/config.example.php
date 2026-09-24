<?php
// Copy this file to config.php and fill it in. config.php is ignored by git and must never be committed.
return [
  // Where booking requests are delivered.
  'to_email'   => 'studios@theagencies.net',
  'to_name'    => 'The Agency Studios',

  // Gmail / Google Workspace SMTP. Use an App Password (Google Account → Security → 2-Step Verification → App passwords),
  // not the account password. The from address must be the same mailbox.
  'smtp_host'  => 'smtp.gmail.com',
  'smtp_port'  => 587,
  'smtp_user'  => 'Shawn@theagencies.net',
  'smtp_pass'  => 'xxxx xxxx xxxx xxxx',
  'from_email' => 'Shawn@theagencies.net',
  'from_name'  => 'The Agency Studios Website',

  // Admin sign-in for /admin/. Generate the hash once with:
  //   php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
  'admin_user'      => 'Shawn@theagencies.net',
  'admin_pass_hash' => '',

  // Public address of the site, used in the agreement links emailed to clients (no trailing slash).
  'site_url' => 'https://theagencystudio.com',

  // Sites allowed to post bookings cross-origin. Same-origin (the site itself) always works.
  'allowed_origins' => ['https://theagencystudio.com', 'https://www.theagencystudio.com'],
];
