<?php
// Shared by booking.php and admin/. Config, database, plain-text + PDF rendering, email.
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
function booking_pdf(array $lines): string {
  $esc = fn(string $s) => strtr(@iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
  $wrapped = [];
  foreach ($lines as $l) foreach (explode("\n", wordwrap($l, 88, "\n", true)) as $w) $wrapped[] = $w;
  $pages = array_chunk($wrapped, 46);
  if (!$pages) $pages = [['']];

  $objs = [];               // 1 catalog, 2 pages, 3 font, then page+content pairs
  $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
  $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
  $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
  $kids = [];
  $n = 5;
  foreach ($pages as $pi => $page) {
    $stream = "BT /F2 16 Tf 54 750 Td (The Agency Studios) Tj ET\n";
    $stream .= "BT /F1 8 Tf 54 736 Td (270 W Duarte Rd, Suite C, Monrovia, CA 91016  |  626.844.0028  |  info@theagencies.net) Tj ET\n";
    $stream .= "0.5 w 54 728 m 558 728 l S\n";
    $stream .= "BT /F1 10 Tf 12.5 TL 54 706 Td\n";
    foreach ($page as $i => $l) {
      $font = ($pi === 0 && $i === 0) ? '/F2 13 Tf' : '/F1 10 Tf';
      $stream .= "$font (" . $esc($l) . ") Tj T*\n";
    }
    $stream .= "ET\nBT /F1 8 Tf 54 40 Td (Page " . ($pi + 1) . " of " . count($pages) . ") Tj ET\n";
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

// Plain-text email with the PDF attached. Returns null on success, an error string otherwise.
function send_booking_email(array $b): ?string {
  $c = cfg();
  if (empty($c['smtp_user']) || empty($c['smtp_pass'])) return 'SMTP not configured';
  require_once __DIR__ . '/lib/Exception.php';
  require_once __DIR__ . '/lib/PHPMailer.php';
  require_once __DIR__ . '/lib/SMTP.php';
  $lines = booking_lines($b);
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
    $m->addAddress($c['to_email'], $c['to_name'] ?? '');
    if (filter_var($b['email'], FILTER_VALIDATE_EMAIL)) $m->addReplyTo($b['email'], $b['name']);
    $m->Subject = $lines[0] . ' — ' . $b['name'] . ($b['date'] ? ', ' . $b['date'] : '');
    $m->isHTML(false);
    $m->Body = implode("\n", $lines) . "\n\nOpen /admin/ on the website to see every request.";
    $m->addStringAttachment(booking_pdf($lines), 'booking-request-' . $b['id'] . '.pdf', 'base64', 'application/pdf');
    $m->send();
    return null;
  } catch (Throwable $e) {
    return $e->getMessage();
  }
}
