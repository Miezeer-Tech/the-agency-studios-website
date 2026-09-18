<?php
// Shared by booking.php, agreement.php, and admin/. Config, database, plain-text + PDF rendering, email.
declare(strict_types=1);

function cfg(): array {
  static $c = null;
  if ($c === null) {
    $f = __DIR__ . '/config.php';
    $c = file_exists($f) ? require $f : [];
  }
  return $c;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $dir = __DIR__ . '/data';
  if (!is_dir($dir)) { mkdir($dir, 0750, true); file_put_contents("$dir/.htaccess", "Require all denied\n"); }
  $pdo = new PDO('sqlite:' . $dir . '/bookings.sqlite');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec('CREATE TABLE IF NOT EXISTS bookings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    kind TEXT NOT NULL,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    phone TEXT,
    date TEXT,
    payload TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT "new",
    emailed INTEGER NOT NULL DEFAULT 0
  )');
  $pdo->exec('CREATE TABLE IF NOT EXISTS agreements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    company TEXT NOT NULL,
    signer TEXT NOT NULL,
    email TEXT NOT NULL,
    phone TEXT,
    payload TEXT NOT NULL,
    emailed INTEGER NOT NULL DEFAULT 0
  )');
  return $pdo;
}

// The request as ordered "Label: value" lines. Same text goes in the email body and the PDF.
function booking_lines(array $b): array {
  $f = json_decode($b['payload'], true) ?: [];
  $lines = [
    ($b['kind'] === 'podcast' ? 'Podcast Room booking request' : 'Studio booking request'),
    'Received: ' . $b['created_at'] . ' (Pacific)',
    'Request #' . $b['id'],
    '',
  ];
  foreach ($f as $k => $v) {
    if ($v === '' || $v === null || $v === []) continue;
    $lines[] = $k . ': ' . (is_array($v) ? implode(', ', $v) : $v);
  }
  return $lines;
}

// Minimal PDF writer: Helvetica, one column, as many pages as the text needs. No dependency.
// Line tokens: "\x01H text" bold heading, "\x01B text" bold line, "\x01PB" page break, "\x01SIG key" draws $opt['sigs'][key]
// (pen strokes as [[x,y],...] normalized 0..1). $opt['initials'] prints in every footer.
function booking_pdf(array $lines, array $opt = []): string {
  $esc = fn(string $s) => strtr(@iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
  $items = [];   // [text, font, cost]
  foreach ($lines as $l) {
    if ($l === "\x01PB") { $items[] = ['', 'pb', 0]; continue; }
    if (str_starts_with($l, "\x01SIG ")) { $items[] = [substr($l, 5), 'sig', 7]; continue; }
    $font = '/F1 10 Tf'; $width = 88;
    if (str_starts_with($l, "\x01H ")) { $l = substr($l, 3); $font = '/F2 12 Tf'; $width = 72; }
    elseif (str_starts_with($l, "\x01B ")) { $l = substr($l, 3); $font = '/F2 10 Tf'; }
    foreach (explode("\n", wordwrap($l, $width, "\n", true)) as $w) $items[] = [$w, $font, 1];
  }
  $pages = [[]]; $used = 0;
  foreach ($items as $it) {
    if ($it[1] === 'pb') { if ($pages[count($pages) - 1]) { $pages[] = []; $used = 0; } continue; }
    if ($used + $it[2] > 46 && $pages[count($pages) - 1]) { $pages[] = []; $used = 0; }
    $pages[count($pages) - 1][] = $it; $used += $it[2];
  }

  $objs = [];               // 1 catalog, 2 pages, 3+4 fonts, then page+content pairs
  $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
  $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
  $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
  $kids = [];
  $n = 5;
  $footer = ($opt['initials'] ?? '') !== '' ? '   |   Renter initials: ' . $opt['initials'] : '';
  foreach ($pages as $pi => $page) {
    $stream = "BT /F2 16 Tf 54 750 Td (The Agency Studios) Tj ET\n";
    $stream .= "BT /F1 8 Tf 54 736 Td (Laska Entertainment, LLC  |  270 W Duarte Rd, Suite C, Monrovia, CA 91016  |  626.844.0028) Tj ET\n";
    $stream .= "0.5 w 54 728 m 558 728 l S\n";
    $y = 706;
    foreach ($page as $i => [$text, $font, $cost]) {
      if ($font === 'sig') {
        $strokes = $opt['sigs'][$text] ?? [];
        $top = $y + 4; $x0 = 130; $w = 220; $h = 55;   // signature box, 4:1 like the pad on the page
        $stream .= "BT /F1 10 Tf 54 " . ($top - $h + 4) . " Td (Signature:) Tj ET\n";
        $stream .= "0.9 w 1 J 1 j\n";
        foreach ($strokes as $s) {
          if (!is_array($s) || count($s) < 1) continue;
          $first = true;
          foreach ($s as $pt) {
            if (!is_array($pt) || count($pt) < 2) continue;
            $px = $x0 + max(0, min(1, (float)$pt[0])) * $w; $py = $top - max(0, min(1, (float)$pt[1])) * $h;
            $stream .= sprintf("%.1f %.1f %s\n", $px, $py, $first ? 'm' : 'l'); $first = false;
          }
          if (!$first) $stream .= "S\n";
        }
        $stream .= "0.5 w $x0 " . ($top - $h) . " m " . ($x0 + $w) . " " . ($top - $h) . " l S\n";
        $y -= 12.5 * $cost;
        continue;
      }
      $stream .= "BT $font 54 $y Td (" . $esc($text) . ") Tj ET\n";
      $y -= $font === '/F2 12 Tf' ? 16 : 12.5;
    }
    $stream .= "BT /F1 8 Tf 54 40 Td (Page " . ($pi + 1) . " of " . count($pages) . $esc($footer) . ") Tj ET\n";
    $objs[$n] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents " . ($n + 1) . " 0 R >>";
    $objs[$n + 1] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
    $kids[] = "$n 0 R";
    $n += 2;
  }
  $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
  ksort($objs);
  $out = "%PDF-1.4\n";
  $offsets = [];
  foreach ($objs as $id => $body) { $offsets[$id] = strlen($out); $out .= "$id 0 obj\n$body\nendobj\n"; }
  $xref = strlen($out);
  $max = max(array_keys($objs));
  $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
  for ($i = 1; $i <= $max; $i++) $out .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
  $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
  return $out;
}

// The signed rental agreement as PDF lines: the same text the page shows (agreement/agreement.json) with the answers filled in.
function agreement_lines(array $a): array {
  $f = json_decode($a['payload'], true) ?: [];
  $v = fn(string $k) => trim((string)($f[$k] ?? ''));
  $blank = fn(string $k) => $v($k) !== '' ? $v($k) : '________';
  $doc = json_decode(file_get_contents(__DIR__ . '/../agreement/agreement.json'), true) ?: [];
  $lines = ["\x01H STUDIO RENTAL AGREEMENT", 'This Agreement is made as of the date signed below between the parties named herein.', 'Agreement #' . $a['id'] . '  |  Signed ' . $a['created_at'] . ' (Pacific)', ''];
  foreach ($doc as $b) {
    if (isset($b['h'])) { $lines[] = ''; $lines[] = "\x01H " . $b['h']; }
    elseif (isset($b['sub'])) { $lines[] = ''; $lines[] = "\x01B " . $b['sub']; }
    elseif (isset($b['p'])) { $lines[] = $b['p']; }
    elseif (isset($b['ul'])) { foreach ($b['ul'] as $u) $lines[] = '  - ' . $u; }
    elseif (isset($b['pb'])) { $lines[] = "\x01PB"; }
    elseif (isset($b['fields'])) { foreach ($b['fields'] as $fd) $lines[] = $fd[0] . ': ' . $blank($fd[0]); }
    elseif (isset($b['row'])) {
      $parts = [];
      foreach ($b['cols'] as $col) { $k = $b['row'] . ' · ' . $col[0]; if ($v($k) !== '') $parts[] = $col[0] . ' ' . $v($k); }
      $lines[] = $b['row'] . ': ' . ($parts ? implode('   ', $parts) : '________');
    }
    elseif (isset($b['table'])) {
      foreach ($b['table']['rows'] as $r) {
        $parts = [];
        foreach ($b['table']['cols'] as $col) { $k = $r . ' · ' . $col[0]; if ($v($k) !== '' && !($col[0] === 'Status' && $v($k) === 'N/A')) $parts[] = $col[0] . ' ' . $v($k); }
        $lines[] = $r . ': ' . ($parts ? implode('   ', $parts) : 'N/A');
      }
    }
    elseif (isset($b['sig'])) {
      $who = $b['sig'];
      if ($who === 'guarantor' && $v('Guarantor Name') === '') { $lines[] = 'Not applicable: no guarantor named.'; continue; }
      $lines[] = "\x01SIG $who";
      $lines[] = 'Signed by ' . ($who === 'renter' ? $blank('Authorized Signer (print)') : $blank('Guarantor Name')) . '   Date: ' . substr($a['created_at'], 0, 10);
    }
  }
  $lines[] = ''; $lines[] = "\x01B Electronic signature record";
  $lines[] = 'Signed electronically on the studio website on ' . $a['created_at'] . ' (Pacific) from IP ' . ($f['_ip'] ?? '') . '. ' . 'The signer confirmed: "I agree to sign this agreement electronically and that this signature is legally binding."';
  $lines[] = 'Browser: ' . substr((string)($f['_ua'] ?? ''), 0, 160);
  return $lines;
}

function agreement_pdf(array $a): string {
  $f = json_decode($a['payload'], true) ?: [];
  return booking_pdf(agreement_lines($a), ['initials' => (string)($f['Initials'] ?? ''), 'sigs' => $f['_sigs'] ?? []]);
}

// Plain-text email with attachments over SMTP. Returns null on success, an error string otherwise.
function send_mail(string $toEmail, string $toName, string $subject, string $body, array $attachments = [], ?array $replyTo = null): ?string {
  $c = cfg();
  if (empty($c['smtp_user']) || empty($c['smtp_pass'])) return 'SMTP not configured';
  require_once __DIR__ . '/lib/Exception.php';
  require_once __DIR__ . '/lib/PHPMailer.php';
  require_once __DIR__ . '/lib/SMTP.php';
  $m = new PHPMailer\PHPMailer\PHPMailer(true);
  try {
    $m->isSMTP();
    $m->Host = $c['smtp_host'] ?? 'smtp.gmail.com';
    $m->Port = (int)($c['smtp_port'] ?? 587);
    $m->SMTPAuth = true;
    $m->SMTPSecure = $m->Port === 465 ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $m->Username = $c['smtp_user'];
    $m->Password = $c['smtp_pass'];
    $m->CharSet = 'UTF-8';
    $m->setFrom($c['from_email'] ?? $c['smtp_user'], $c['from_name'] ?? 'The Agency Studios Website');
    $m->addAddress($toEmail, $toName);
    if ($replyTo && filter_var($replyTo[0], FILTER_VALIDATE_EMAIL)) $m->addReplyTo($replyTo[0], $replyTo[1] ?? '');
    $m->Subject = $subject;
    $m->isHTML(false);
    $m->Body = $body;
    foreach ($attachments as $name => $data) $m->addStringAttachment($data, $name, 'base64', 'application/pdf');
    $m->send();
    return null;
  } catch (Throwable $e) {
    return $e->getMessage();
  }
}

// Booking request to the studio, plain text with the PDF attached.
function send_booking_email(array $b): ?string {
  $c = cfg();
  $lines = booking_lines($b);
  return send_mail($c['to_email'] ?? '', $c['to_name'] ?? '', $lines[0] . ' — ' . $b['name'] . ($b['date'] ? ', ' . $b['date'] : ''),
    implode("\n", $lines) . "\n\nOpen /admin/ on the website to see every request.",
    ['booking-request-' . $b['id'] . '.pdf' => booking_pdf($lines)], [$b['email'], $b['name']]);
}

// Signed agreement: one copy to the signer, one to the studio. Returns null when both went out.
function send_agreement_email(array $a): ?string {
  $c = cfg();
  $pdf = agreement_pdf($a);
  $file = 'studio-rental-agreement-' . $a['id'] . '.pdf';
  $subject = 'Studio Rental Agreement — ' . $a['company'] . ' (signed ' . substr($a['created_at'], 0, 10) . ')';
  $body = "Studio Rental Agreement #{$a['id']}\nRenter: {$a['company']}\nSigned by: {$a['signer']} <{$a['email']}>\nSigned: {$a['created_at']} (Pacific)\n\nThe signed agreement is attached as a PDF. The studio countersigns and returns a fully executed copy once payment and the Certificate of Insurance are received.\n\nThe Agency Studios · 270 W Duarte Rd, Suite C, Monrovia, CA 91016 · 626.844.0028";
  $e1 = send_mail($a['email'], $a['signer'], $subject, $body, [$file => $pdf], [$c['to_email'] ?? '', $c['to_name'] ?? '']);
  $e2 = send_mail($c['to_email'] ?? '', $c['to_name'] ?? '', $subject, $body . "\n\nOpen /admin/ on the website to see every signed agreement.", [$file => $pdf], [$a['email'], $a['signer']]);
  return $e1 ?? $e2;
}
