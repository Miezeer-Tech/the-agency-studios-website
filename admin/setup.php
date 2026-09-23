<?php
// One-time setup: writes api/config.php with the admin password and Gmail App Password typed here.
// Works only while no admin password is set; after that it refuses, so do this right after deploying.
declare(strict_types=1);
require __DIR__ . '/../api/common.php';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$file = __DIR__ . '/../api/config.php';
$c = cfg();
if (!empty($c['admin_pass_hash'])) { http_response_code(403); exit('<p style="font:16px system-ui;padding:40px">Setup is already done. Sign in at <a href="./">/admin/</a>. To change passwords, edit api/config.php in File Manager.</p>'); }

$base = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . preg_replace('#/admin/.*$#', '', $_SERVER['SCRIPT_NAME'] ?? '');
$defaults = file_exists(__DIR__ . '/../api/config.example.php') ? require __DIR__ . '/../api/config.example.php' : [];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? ''); $pass = $_POST['pass'] ?? ''; $pass2 = $_POST['pass2'] ?? ''; $app = str_replace(' ', '', trim($_POST['app'] ?? '')); $site = rtrim(trim($_POST['site'] ?? ''), '/');
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Enter the studio Gmail address.';
  elseif (strlen($pass) < 8) $error = 'The admin password needs at least 8 characters.';
  elseif ($pass !== $pass2) $error = 'The two admin passwords do not match.';
  elseif (strlen($app) < 16) $error = 'The Google App Password is 16 characters (Google shows it in four groups of four).';
  else {
    $cfg = array_merge($defaults, [
      'to_email' => $email, 'smtp_user' => $email, 'from_email' => $email, 'smtp_pass' => $app,
      'admin_user' => $email, 'admin_pass_hash' => password_hash($pass, PASSWORD_DEFAULT),
      'site_url' => $site ?: $base,
      'allowed_origins' => array_values(array_unique(array_merge($defaults['allowed_origins'] ?? [], [$site ?: $base]))),
    ]);
    $php = "<?php\n// Written by admin/setup.php. Edit by hand if anything changes. Never commit this file.\nreturn " . var_export($cfg, true) . ";\n";
    if (@file_put_contents($file, $php) === false) $error = 'Could not write api/config.php. In File Manager, set the api folder permissions to 755 and try again.';
    else { @chmod($file, 0600); header('Location: ./?setup=done'); exit; }
  }
}
?>
<!doctype html>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Set up · The Agency Studios</title>
<style>
  body { margin:0; background:#0B0B0C; color:#F4F2EE; font:15px/1.5 system-ui, sans-serif; }
  .box { max-width:460px; margin:8vh auto; padding:0 20px; display:grid; gap:14px; }
  h1 { font-size:24px; margin:0; } p { margin:0; color:rgba(244,242,238,.65); font-size:14px; }
  label { display:grid; gap:6px; font-size:13px; color:rgba(244,242,238,.65); }
  input { font:inherit; color:#F4F2EE; background:#15151A; border:1px solid rgba(244,242,238,.18); border-radius:10px; padding:10px 12px; width:100%; box-sizing:border-box; }
  button { font:600 13px system-ui; letter-spacing:.1em; text-transform:uppercase; background:#005FE6; color:#fff; border:0; padding:14px 18px; border-radius:8px; cursor:pointer; }
  .err { color:#FF7A7A; font-size:14px; }
</style>
<form class="box" method="post" autocomplete="off">
  <h1>Set up the studio admin</h1>
  <p>This runs once. It creates the server config with your admin sign-in and the Gmail App Password used to send booking emails and signed agreements.</p>
  <label>Studio Gmail address (admin sign-in and sender) <input name="email" type="email" required value="<?= $h($_POST['email'] ?? $defaults['to_email'] ?? '') ?>"></label>
  <label>Admin password <input name="pass" type="password" required minlength="8" autocomplete="new-password"></label>
  <label>Admin password again <input name="pass2" type="password" required minlength="8" autocomplete="new-password"></label>
  <label>Google App Password for that mailbox <input name="app" type="password" required placeholder="xxxx xxxx xxxx xxxx"></label>
  <p>Google Account → Security → 2-Step Verification → App passwords. Not the normal Gmail password.</p>
  <label>Site address <input name="site" value="<?= $h($_POST['site'] ?? $base) ?>"></label>
  <?php if ($error): ?><p class="err"><?= $h($error) ?></p><?php endif ?>
  <button>Save and finish</button>
</form>
