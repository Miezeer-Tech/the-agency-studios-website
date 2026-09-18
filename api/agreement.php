<?php
// POST JSON {fields: {Label: value, ...}, sigs: {renter: [[[x,y],...],...], guarantor: [...]}, website: ""}  →  {ok, id, emailed}
// Saves the signed agreement, builds the PDF, emails a copy to the signer and to the studio. A failed email never loses the record.
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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST only']); exit; }

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in) || !is_array($in['fields'] ?? null)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Bad request']); exit; }
if (!empty($in['website'])) { echo json_encode(['ok' => true, 'id' => 0, 'emailed' => false]); exit; }   // honeypot

$fields = [];
foreach ($in['fields'] as $k => $v) {
  $k = trim(strip_tags((string)$k)); if ($k === '' || strlen($k) > 80 || $k[0] === '_') continue;
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

$company = $fields['Company Name'] ?? ($fields['Renter / Production Company'] ?? '');
$signer  = $fields['Authorized Signer (print)'] ?? '';
$email   = $fields['Email'] ?? '';
$errors = [];
if ($company === '') $errors[] = 'Company name';
if ($signer === '') $errors[] = 'Authorized signer';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'a valid email';
if (($fields['Consent'] ?? '') !== 'yes') $errors[] = 'the electronic-signature consent';
if (empty($sigs['renter'])) $errors[] = 'the renter signature';
if (($fields['Guarantor Name'] ?? '') !== '' && empty($sigs['guarantor'])) $errors[] = 'the guarantor signature';
if ($errors) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'Missing: ' . implode(', ', $errors)]); exit; }

$fields['_sigs'] = $sigs;
$fields['_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
$fields['_ua'] = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);

$db = db();
$db->prepare('INSERT INTO agreements (created_at, company, signer, email, phone, payload) VALUES (?,?,?,?,?,?)')
   ->execute([date('Y-m-d H:i'), $company, $signer, $email, $fields['Phone'] ?? '', json_encode($fields, JSON_UNESCAPED_UNICODE)]);
$id = (int)$db->lastInsertId();
$a = $db->query("SELECT * FROM agreements WHERE id = $id")->fetch(PDO::FETCH_ASSOC);

$err = send_agreement_email($a);
if ($err === null) $db->exec("UPDATE agreements SET emailed = 1 WHERE id = $id");
else error_log("agreement #$id email failed: $err");

echo json_encode(['ok' => true, 'id' => $id, 'emailed' => $err === null]);
