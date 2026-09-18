<?php
// GET  ?slug=<company-date-time>                          →  {ok, slug, status, fields (the studio's part), studio (field names)}
// POST JSON {slug, fields, sigs: {renter, guarantor}, website: ""}  →  {ok, id, emailed}
// The studio creates agreements from /admin/. The client opens the link, fills in their part, signs, and both sides get the PDF.
declare(strict_types=1);
require __DIR__ . '/common.php';
date_default_timezone_set('America/Los_Angeles');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, cfg()['allowed_origins'] ?? [], true)) {
  header('Access-Control-Allow-Origin: ' . $origin);
  header('Vary: Origin');
  header('Access-Control-Allow-Headers: Content-Type');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
header('Content-Type: application/json');
$fail = function (int $code, string $msg) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; };
$load = function (string $slug) use ($fail): array {
  if (!preg_match('/^[a-z0-9-]{3,120}$/', $slug)) $fail(404, 'No agreement at this link');
  $st = db()->prepare('SELECT * FROM agreements WHERE slug = ?'); $st->execute([$slug]);
  $a = $st->fetch(PDO::FETCH_ASSOC);
  if (!$a) $fail(404, 'No agreement at this link. Ask the studio to send it again.');
  return $a;
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $a = $load((string)($_GET['slug'] ?? ''));
  $f = json_decode($a['payload'], true) ?: [];
  $studio = studio_field_names();
  $public = [];
  foreach (array_merge($studio, ['Renter / Production Company', 'Producer / Authorized Contact', 'Email', 'Phone']) as $k) if (isset($f[$k])) $public[$k] = $f[$k];
  echo json_encode(['ok' => true, 'slug' => $a['slug'], 'status' => $a['status'], 'company' => $a['company'], 'sent' => $a['created_at'], 'signed' => $a['signed_at'], 'fields' => $public, 'studio' => $studio]);
  exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $fail(405, 'POST only');

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in) || !is_array($in['fields'] ?? null)) $fail(400, 'Bad request');
if (!empty($in['website'])) { echo json_encode(['ok' => true, 'id' => 0, 'emailed' => false]); exit; }   // honeypot

$a = $load((string)($in['slug'] ?? ''));
if ($a['status'] === 'signed') $fail(409, 'This agreement was already signed on ' . $a['signed_at'] . '. Ask the studio for a new link if something changed.');

// The client's answers. The studio's fields (Exhibit A) are locked and stay as sent.
$locked = array_flip(studio_field_names());
$fields = json_decode($a['payload'], true) ?: [];
foreach ($in['fields'] as $k => $v) {
  $k = trim(strip_tags((string)$k)); if ($k === '' || strlen($k) > 80 || $k[0] === '_' || isset($locked[$k])) continue;
  $v = trim(strip_tags((string)$v));
  $fields[$k] = strlen($v) > 500 ? substr($v, 0, 500) : $v;
}
// Signatures: pen strokes, points normalized 0..1. Capped so nobody can post a megabyte of "signature".
$sigs = [];
foreach (['renter', 'guarantor'] as $who) {
  $strokes = $in['sigs'][$who] ?? [];
  if (!is_array($strokes)) continue;
  $clean = []; $points = 0;
  foreach (array_slice($strokes, 0, 200) as $s) {
    if (!is_array($s)) continue;
    $pts = [];
    foreach (array_slice($s, 0, 400) as $pt) {
      if (is_array($pt) && count($pt) >= 2 && is_numeric($pt[0]) && is_numeric($pt[1])) { $pts[] = [round((float)$pt[0], 4), round((float)$pt[1], 4)]; $points++; }
    }
    if ($pts) $clean[] = $pts;
  }
  if ($points >= 2) $sigs[$who] = $clean;
}

$signer = $fields['Authorized Signer (print)'] ?? '';
$email  = $fields['Email'] ?? '';
$errors = [];
foreach (['Initials', 'Renter / Production Company', 'Producer / Authorized Contact', 'Production Title / Description', 'Company Name', 'Title'] as $k) if (($fields[$k] ?? '') === '') $errors[] = $k;
if ($signer === '') $errors[] = 'Authorized signer';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'a valid email';
if (($fields['Consent'] ?? '') !== 'yes') $errors[] = 'the electronic-signature consent';
if (empty($sigs['renter'])) $errors[] = 'the renter signature';
if (($fields['Guarantor Name'] ?? '') !== '' && empty($sigs['guarantor'])) $errors[] = 'the guarantor signature';
if ($errors) $fail(422, 'Missing: ' . implode(', ', $errors));

$fields['_sigs'] = $sigs;
$fields['_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
$fields['_ua'] = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);

$db = db();
$db->prepare('UPDATE agreements SET status = "signed", signed_at = ?, signer = ?, email = ?, phone = ?, company = ?, payload = ? WHERE id = ?')
   ->execute([date('Y-m-d H:i'), $signer, $email, $fields['Phone'] ?? '', $fields['Company Name'] ?: $a['company'], json_encode($fields, JSON_UNESCAPED_UNICODE), $a['id']]);
$a = $db->query('SELECT * FROM agreements WHERE id = ' . (int)$a['id'])->fetch(PDO::FETCH_ASSOC);

$err = send_agreement_email($a);
if ($err === null) $db->exec('UPDATE agreements SET emailed = 1 WHERE id = ' . (int)$a['id']);
else error_log("agreement {$a['slug']} email failed: $err");

echo json_encode(['ok' => true, 'id' => (int)$a['id'], 'emailed' => $err === null]);
