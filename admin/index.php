<?php
// Admin: sign in, see every booking request, change its status, download the PDF.
declare(strict_types=1);
require __DIR__ . '/../api/common.php';
date_default_timezone_set('America/Los_Angeles');
session_name('agency_admin');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
$c = cfg();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

if (isset($_GET['logout'])) { session_destroy(); header('Location: ./'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
  $ok = !empty($c['admin_pass_hash'])
     && hash_equals(strtolower($c['admin_user'] ?? ''), strtolower(trim($_POST['user'] ?? '')))
     && password_verify($_POST['pass'] ?? '', $c['admin_pass_hash']);
  if ($ok) { session_regenerate_id(true); $_SESSION['admin'] = true; header('Location: ./'); exit; }
  usleep(500000);   // ponytail: half-second slowdown on bad logins; add lockout counter if brute force ever shows up
  $error = empty($c['admin_pass_hash']) ? 'Admin password is not set up yet. See api/config.example.php.' : 'Wrong email or password.';
}
$signedIn = !empty($_SESSION['admin']);

if ($signedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status'], $_POST['id'])) {
  $s = in_array($_POST['status'], ['new', 'contacted', 'booked', 'closed'], true) ? $_POST['status'] : 'new';
  db()->prepare('UPDATE bookings SET status = ? WHERE id = ?')->execute([$s, (int)$_POST['id']]);
  header('Location: ./?id=' . (int)$_POST['id']); exit;
}
if ($signedIn && isset($_GET['pdf'])) {
  $b = db()->query('SELECT * FROM bookings WHERE id = ' . (int)$_GET['pdf'])->fetch(PDO::FETCH_ASSOC);
  if ($b) { header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="booking-request-' . $b['id'] . '.pdf"'); echo booking_pdf(booking_lines($b)); }
  exit;
}
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Bookings · The Agency Studios</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;700&family=Archivo:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root { --ink:#0B0B0C; --ink-2:#15151A; --paper:#F4F2EE; --mute:rgba(244,242,238,.55); --line:rgba(244,242,238,.12); --blue:#005FE6; --blue-2:#4A94FF; }
  * { box-sizing:border-box; } body { margin:0; background:var(--ink); color:var(--paper); font:15px/1.5 Archivo,system-ui,sans-serif; }
  a { color:var(--blue-2); text-decoration:none; } a:hover { text-decoration:underline; }
  h1,h2 { font-family:Outfit,system-ui,sans-serif; margin:0; } h1 { font-size:26px; } h2 { font-size:20px; }
  .top { display:flex; align-items:center; gap:18px; padding:16px clamp(16px,3vw,32px); border-bottom:1px solid var(--line); }
  .top img { height:34px; } .top .sp { margin-left:auto; color:var(--mute); font-size:13px; }
  .wrap { max-width:1200px; margin:0 auto; padding:clamp(16px,3vw,32px); }
  .login { max-width:380px; margin:12vh auto; display:grid; gap:12px; }
  label { display:grid; gap:6px; font-size:13px; color:var(--mute); }
  input, select { font:inherit; color:var(--paper); background:var(--ink-2); border:1px solid rgba(244,242,238,.18); border-radius:10px; padding:10px 12px; width:100%; color-scheme:dark; }
  button, .btn { font:600 12px/1 Archivo,system-ui,sans-serif; letter-spacing:.12em; text-transform:uppercase; background:var(--blue); color:#fff; border:0; padding:13px 18px; border-radius:8px; cursor:pointer; }
  .err { color:#FF7A7A; font-size:14px; }
  table { width:100%; border-collapse:collapse; font-size:14px; } th, td { text-align:left; padding:10px 8px; border-top:1px solid var(--line); vertical-align:top; }
  th { color:var(--mute); font-weight:600; font-size:11px; letter-spacing:.14em; text-transform:uppercase; border-top:0; }
  tr.row:hover td { background:rgba(244,242,238,.04); cursor:pointer; }
  .pill { display:inline-block; padding:3px 9px; border-radius:999px; font-size:11px; font-weight:600; letter-spacing:.08em; text-transform:uppercase; }
  .new { background:rgba(0,95,230,.25); color:var(--blue-2); } .contacted { background:rgba(240,190,82,.2); color:#F0BE52; } .booked { background:rgba(46,204,113,.18); color:#5BE08F; } .closed { background:rgba(244,242,238,.1); color:var(--mute); }
  .grid { display:grid; grid-template-columns:minmax(0,1.3fr) minmax(0,1fr); gap:24px; align-items:start; }
  .card { background:var(--ink-2); border:1px solid var(--line); border-radius:16px; padding:20px; }
  dl { display:grid; grid-template-columns:max-content 1fr; gap:8px 18px; margin:16px 0; font-size:14px; } dt { color:var(--mute); } dd { margin:0; }
  .actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:16px; }
  .muted { color:var(--mute); font-size:13px; }
  .tbl { overflow-x:auto; }
  @media (max-width: 860px) { .grid { grid-template-columns:1fr; } h1 { font-size:20px; } .top { flex-wrap:wrap; } }
  @media (max-width: 600px) { th:nth-child(2), td:nth-child(2), th:nth-child(7), td:nth-child(7) { display:none; } }
</style>
<div class="top"><img src="../assets/Agency-Studio-Logo-white.png" alt=""><h1>Booking requests</h1>
  <?php if ($signedIn): ?><span class="sp"><?= $h($c['admin_user'] ?? '') ?> · <a href="./?logout=1">Sign out</a></span><?php endif ?>
</div>

<?php if (!$signedIn): ?>
<form class="login" method="post">
  <h2>Sign in</h2>
  <label>Email <input name="user" type="email" autocomplete="username" required></label>
  <label>Password <input name="pass" type="password" autocomplete="current-password" required></label>
  <?php if ($error): ?><p class="err"><?= $h($error) ?></p><?php endif ?>
  <button name="login" value="1">Sign in</button>
</form>

<?php else:
  $rows = db()->query('SELECT id, created_at, kind, name, email, phone, date, status, emailed FROM bookings ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
  $sel = isset($_GET['id']) ? db()->query('SELECT * FROM bookings WHERE id = ' . (int)$_GET['id'])->fetch(PDO::FETCH_ASSOC) : null;
?>
<div class="wrap">
  <div class="grid">
    <div class="card">
      <h2><?= count($rows) ?> request<?= count($rows) === 1 ? '' : 's' ?></h2>
      <?php if (!$rows): ?><p class="muted">Nothing yet. Requests from the booking forms land here the moment they're sent.</p><?php else: ?>
      <div class="tbl"><table>
        <tr><th>#</th><th>Received</th><th>Room</th><th>Name</th><th>Shoot date</th><th>Status</th><th>Email</th></tr>
        <?php foreach ($rows as $r): ?>
        <tr class="row" onclick="location.href='./?id=<?= $r['id'] ?>'">
          <td><?= $r['id'] ?></td><td><?= $h($r['created_at']) ?></td><td><?= $r['kind'] === 'podcast' ? 'Podcast Room' : 'Stage' ?></td>
          <td><?= $h($r['name']) ?></td><td><?= $h($r['date']) ?></td>
          <td><span class="pill <?= $h($r['status']) ?>"><?= $h($r['status']) ?></span></td>
          <td class="muted"><?= $r['emailed'] ? 'sent' : 'not sent' ?></td>
        </tr>
        <?php endforeach ?>
      </table></div>
      <?php endif ?>
    </div>
    <div class="card">
      <?php if (!$sel): ?><p class="muted">Select a request to see every field, download the PDF, or change its status.</p>
      <?php else: $f = json_decode($sel['payload'], true) ?: []; ?>
      <h2>Request #<?= $sel['id'] ?> <span class="pill <?= $h($sel['status']) ?>"><?= $h($sel['status']) ?></span></h2>
      <p class="muted"><?= $sel['kind'] === 'podcast' ? 'Podcast Room' : 'Stage' ?> · received <?= $h($sel['created_at']) ?> · email <?= $sel['emailed'] ? 'sent to ' . $h($c['to_email'] ?? '') : 'not sent' ?></p>
      <dl>
        <?php foreach ($f as $k => $v): if ($v === '' || $v === []) continue; ?>
        <dt><?= $h($k) ?></dt><dd><?= $h(is_array($v) ? implode(', ', $v) : $v) ?></dd>
        <?php endforeach ?>
      </dl>
      <form class="actions" method="post">
        <input type="hidden" name="id" value="<?= $sel['id'] ?>">
        <select name="status" style="width:auto">
          <?php foreach (['new', 'contacted', 'booked', 'closed'] as $s): ?><option <?= $s === $sel['status'] ? 'selected' : '' ?>><?= $s ?></option><?php endforeach ?>
        </select>
        <button>Save status</button>
        <a class="btn" href="./?pdf=<?= $sel['id'] ?>" target="_blank" rel="noopener">PDF</a>
        <a href="mailto:<?= $h($sel['email']) ?>?subject=<?= rawurlencode('Your booking request at The Agency Studios (#' . $sel['id'] . ')') ?>">Reply by email</a>
      </form>
      <?php endif ?>
    </div>
  </div>
</div>
<?php endif ?>
