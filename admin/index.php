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
if ($signedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_agreement'])) {
  $company = trim($_POST['company'] ?? ''); $contact = trim($_POST['contact'] ?? ''); $email = trim($_POST['email'] ?? ''); $phone = trim($_POST['phone'] ?? '');
  if ($company === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'Company name and a valid client email are required.'; }
  else {
    $a = create_agreement($_POST['f'] ?? [], $company, $contact, $email, $phone);
    $err = send_agreement_link($a);
    if ($err === null) db()->exec('UPDATE agreements SET link_emailed = 1 WHERE id = ' . (int)$a['id']); else error_log("agreement link {$a['slug']} email failed: $err");
    header('Location: ./?sent=' . rawurlencode($a['slug']) . ($err ? '&mail=0' : '')); exit;
  }
}
if ($signedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
  $a = db()->query('SELECT * FROM agreements WHERE id = ' . (int)$_POST['resend'])->fetch(PDO::FETCH_ASSOC);
  $err = $a ? send_agreement_link($a) : 'not found';
  if ($a && $err === null) db()->exec('UPDATE agreements SET link_emailed = 1 WHERE id = ' . (int)$a['id']);
  header('Location: ./?sent=' . rawurlencode($a['slug'] ?? '') . ($err ? '&mail=0' : '') . '#agreements'); exit;
}
if ($signedIn && isset($_GET['apdf'])) {
  $a = db()->query('SELECT * FROM agreements WHERE id = ' . (int)$_GET['apdf'])->fetch(PDO::FETCH_ASSOC);
  if ($a) { header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="studio-rental-agreement-' . $a['id'] . '.pdf"'); echo agreement_pdf($a); }
  exit;
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
<title>Admin · The Agency Studios</title>
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
  .sent { background:rgba(240,190,82,.2); color:#F0BE52; } .signed { background:rgba(46,204,113,.18); color:#5BE08F; }
  .ok { color:#5BE08F; font-size:14px; }
  details.newagr summary { cursor:pointer; font-family:Outfit,system-ui,sans-serif; font-size:18px; font-weight:600; list-style:none; display:flex; align-items:center; gap:12px; } details.newagr summary::after { content:"+"; margin-left:auto; font-size:22px; color:var(--mute); } details[open].newagr summary::after { content:"–"; }
  .f3 { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin:16px 0; }
  .sub { font-family:Outfit,system-ui,sans-serif; font-size:15px; font-weight:600; margin:22px 0 8px; color:var(--paper); }
  .arow { display:grid; grid-template-columns:minmax(170px,1.2fr) repeat(auto-fit,minmax(90px,1fr)); gap:8px 10px; align-items:end; padding:6px 0; border-top:1px solid var(--line); }
  .arow .rl { font-size:13px; align-self:center; } .arow label { font-size:11px; } .arow input { padding:7px 9px; font-size:13px; }
  .arow.tot input { font-weight:600; color:#5BE08F; }
  .atbl table { min-width:640px; } .atbl td, .atbl th { padding:4px 5px; } .atbl input, .atbl select { padding:6px 8px; font-size:13px; border-radius:8px; }
  .link { font-family:ui-monospace,Menlo,monospace; font-size:12px; word-break:break-all; }
  @media (max-width: 860px) { .grid { grid-template-columns:1fr; } h1 { font-size:20px; } .top { flex-wrap:wrap; } }
  @media (max-width: 600px) { th:nth-child(2), td:nth-child(2), th:nth-child(7), td:nth-child(7) { display:none; } }
</style>
<div class="top"><img src="../assets/Agency-Studio-Logo-white.png" alt=""><h1>Bookings and agreements</h1>
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
  $agreements = db()->query('SELECT id, slug, created_at, status, company, contact, signer, email, signed_at, link_emailed, emailed FROM agreements ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
  $doc = agreement_doc();
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
  <div class="card" style="margin-top:24px" id="agreements">
    <h2>Rental agreements</h2>
    <?php if (isset($_GET['sent'])): $u = agreement_url($_GET['sent']); ?>
      <p class="<?= isset($_GET['mail']) ? 'err' : 'ok' ?>" style="margin:12px 0"><?= isset($_GET['mail']) ? 'Saved, but the email did not go out (check SMTP in config.php). Send the client this link yourself:' : 'Sent. The client got this link by email:' ?> <a class="link" href="<?= $h($u) ?>" target="_blank" rel="noopener"><?= $h($u) ?></a></p>
    <?php endif ?>
    <?php if ($error && isset($_POST['new_agreement'])): ?><p class="err"><?= $h($error) ?></p><?php endif ?>

    <details class="newagr" <?= isset($_POST['new_agreement']) ? 'open' : '' ?>>
      <summary>New agreement: fill in Exhibit A, then send the client their link</summary>
      <form method="post" id="newagr">
        <input type="hidden" name="new_agreement" value="1">
        <div class="f3">
          <label>Production company <input name="company" required value="<?= $h($_POST['company'] ?? '') ?>"></label>
          <label>Contact name <input name="contact" value="<?= $h($_POST['contact'] ?? '') ?>"></label>
          <label>Client email (the link goes here) <input name="email" type="email" required value="<?= $h($_POST['email'] ?? '') ?>"></label>
          <label>Phone <input name="phone" type="tel" value="<?= $h($_POST['phone'] ?? '') ?>"></label>
        </div>
        <p class="muted">Totals fill themselves from the lines above them (rate × days, and the grand total). Change any total by hand if you need to. Leave lines blank when they don't apply.</p>
        <?php foreach ($doc as $b): if (($b['by'] ?? '') !== 'studio') { if (isset($b['sub']) && $exA ?? false) echo '<p class="sub">' . $h($b['sub']) . '</p>'; if (isset($b['h']) && str_starts_with($b['h'], 'EXHIBIT A')) $exA = true; elseif (isset($b['h'])) $exA = false; continue; } ?>
          <?php if (isset($b['row'])): ?>
            <div class="arow <?= preg_match('/total/i', $b['row']) ? 'tot' : '' ?>"><div class="rl"><?= $h($b['row']) ?></div>
              <?php foreach ($b['cols'] as $col): $k = $b['row'] . ' · ' . $col[0]; ?><label><?= $h($col[0]) ?><input name="f[<?= $h($k) ?>]" type="<?= $col[1] === 'date' ? 'date' : 'text' ?>" inputmode="<?= $col[1] === 'number' ? 'decimal' : 'text' ?>" data-col="<?= $h($col[0]) ?>" data-row="<?= $h($b['row']) ?>"></label><?php endforeach ?>
            </div>
          <?php elseif (isset($b['table'])): ?>
            <div class="tbl atbl"><table>
              <tr><th>Item</th><?php foreach ($b['table']['cols'] as $col): ?><th><?= $h($col[0]) ?></th><?php endforeach ?></tr>
              <?php foreach ($b['table']['rows'] as $r): ?>
                <tr><td><?= $h($r) ?></td>
                <?php foreach ($b['table']['cols'] as $col): $k = $r . ' · ' . $col[0]; ?>
                  <td><?php if ($col[1] === 'select'): ?><select name="f[<?= $h($k) ?>]" data-col="Status" data-row="<?= $h($r) ?>"><?php foreach ($col[2] as $o): ?><option><?= $h($o) ?></option><?php endforeach ?></select>
                  <?php else: ?><input name="f[<?= $h($k) ?>]" type="text" inputmode="<?= $col[1] === 'number' ? 'decimal' : 'text' ?>" data-col="<?= $h($col[0]) ?>" data-row="<?= $h($r) ?>" aria-label="<?= $h($k) ?>"><?php endif ?></td>
                <?php endforeach ?></tr>
              <?php endforeach ?>
            </table></div>
          <?php endif ?>
        <?php endforeach ?>
        <div class="actions"><button>Save and email the link to the client</button><span class="muted">Creates /agreement/&lt;company&gt;-&lt;date&gt;-&lt;time&gt;</span></div>
      </form>
    </details>

    <h2 style="margin-top:28px">Sent out agreements</h2>
    <?php if (!$agreements): ?><p class="muted">None yet. Create one above; it appears here as "sent" and flips to "signed" when the client signs.</p><?php else: ?>
    <div class="tbl"><table>
      <tr><th>Link</th><th>Sent</th><th>Company</th><th>Client</th><th>Status</th><th>Signed</th><th></th></tr>
      <?php foreach ($agreements as $a): ?>
      <tr>
        <td><a class="link" href="<?= $h(agreement_url($a['slug'])) ?>" target="_blank" rel="noopener"><?= $h($a['slug']) ?></a></td>
        <td><?= $h($a['created_at']) ?><?= $a['link_emailed'] ? '' : ' <span class="muted">(link not emailed)</span>' ?></td>
        <td><?= $h($a['company']) ?></td>
        <td><?= $h($a['contact'] ?: $a['signer']) ?><br><a href="mailto:<?= $h($a['email']) ?>" class="muted"><?= $h($a['email']) ?></a></td>
        <td><span class="pill <?= $h($a['status']) ?>"><?= $a['status'] === 'signed' ? 'signed' : 'not signed' ?></span></td>
        <td><?= $h($a['signed_at'] ?? '') ?><?= $a['status'] === 'signed' && !$a['emailed'] ? ' <span class="muted">(PDF email failed)</span>' : '' ?></td>
        <td style="white-space:nowrap"><?php if ($a['status'] === 'signed'): ?><a class="btn" href="./?apdf=<?= $a['id'] ?>" target="_blank" rel="noopener">PDF</a>
          <?php else: ?><form method="post" style="display:inline"><button name="resend" value="<?= $a['id'] ?>" style="background:transparent;border:1px solid var(--line)">Resend link</button></form><?php endif ?></td>
      </tr>
      <?php endforeach ?>
    </table></div>
    <?php endif ?>
  </div>
<script>
  // Exhibit A totals: "= $" = rate × days; fee totals; base = stages + fees; amenities = sum; grand = base + amenities.
  const F = document.getElementById('newagr');
  if (F) {
    const q = (row, col) => F.querySelector(`[data-row="${CSS.escape(row)}"][data-col="${CSS.escape(col)}"]`);
    const num = el => { const n = parseFloat((el?.value || '').replace(/[^0-9.]/g, '')); return isNaN(n) ? 0 : n; };
    const set = (el, v, force) => { if (el && (force || !el.dataset.manual)) el.value = v ? String(Math.round(v * 100) / 100) : ''; };
    const stages = ['Pre-light · Main Stage', 'Pre-light · Insert Stage', 'Shoot · Main Stage', 'Shoot · Insert Stage'];
    const recalc = () => {
      let base = 0;
      stages.forEach(r => { const t = num(q(r, 'Rate $')) * (num(q(r, 'x Days')) || 1); if (num(q(r, 'Rate $'))) set(q(r, '= $'), t); base += num(q(r, '= $')); });
      set(q('Paint fee', 'Total $'), (num(q('Paint fee', 'Labor $')) + num(q('Paint fee', 'Materials $'))) * (num(q('Paint fee', 'Days')) || 1) || 0);
      set(q('Clean fee', 'Total $'), num(q('Clean fee', 'Rate $')) * (num(q('Clean fee', 'Days')) || 1) || 0);
      set(q('Site representative', 'Total $'), num(q('Site representative', 'Rate $ / hr')) * (num(q('Site representative', 'Hours')) || 1) * (num(q('Site representative', 'Days')) || 1) || 0);
      ['Paint fee', 'Clean fee', 'Site representative'].forEach(r => base += num(q(r, 'Total $')));
      set(q('Total base rental (studio stages + fees above)', '$'), base);
      set(q('Total rental (studio stages + additional fees)', '$'), base);
      let am = 0;
      F.querySelectorAll('.atbl tr').forEach(tr => { const s = tr.querySelector('select'); if (!s) return; const row = s.dataset.row; const rate = q(row, 'Rate $'), days = q(row, 'Days'), tot = q(row, 'Total $'); if (s.value === 'Added' && num(rate)) set(tot, num(rate) * (num(days) || 1)); if (s.value === 'N/A') set(tot, 0, true); am += num(tot); });
      set(q('Total amenities', '$'), am);
      set(q('Grand total', '$'), num(q('Total rental (studio stages + additional fees)', '$')) + am);
    };
    F.addEventListener('input', e => { const t = e.target; if (t.dataset.col && /^(= \$|Total \$|\$)$/.test(t.dataset.col) && !/Grand|Total amenities|Total base|Total rental/.test(t.dataset.row)) t.dataset.manual = t.value ? '1' : ''; if (/Grand total|Total amenities|Total base|Total rental/.test(t.dataset.row || '')) t.dataset.manual = t.value ? '1' : ''; recalc(); });
    F.addEventListener('change', recalc);
  }
</script>
</div>
<?php endif ?>
