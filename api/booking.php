<?php
// POST JSON {kind: "studio"|"podcast", fields: {Label: value, ...}, website: ""}  →  {ok, id, emailed}
// Saves the request, emails Shawn a plain-text copy with a PDF attached. A failed email never loses the booking.
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
if (!empty($in['website'])) { echo json_encode(['ok' => true, 'id' => 0, 'emailed' => false]); exit; }   // honeypot: bots fill it, people never see it

$fields = [];
foreach ($in['fields'] as $k => $v) {
  $k = trim(strip_tags((string)$k)); if ($k === '' || strlen($k) > 60) continue;
  if (is_array($v)) $v = array_values(array_filter(array_map(fn($x) => trim(strip_tags((string)$x)), $v), 'strlen'));
  else $v = trim(strip_tags((string)$v));
  if (is_string($v) && strlen($v) > 500) $v = substr($v, 0, 500);
  $fields[$k] = $v;
}
$name  = trim(($fields['First name'] ?? '') . ' ' . ($fields['Last name'] ?? ''));
$email = $fields['Email'] ?? '';
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'Name and a valid email are required']); exit; }

$kind = ($in['kind'] ?? '') === 'podcast' ? 'podcast' : 'studio';
$date = $fields['Date'] ?? $fields['Load in'] ?? '';
$db = db();
$db->prepare('INSERT INTO bookings (created_at, kind, name, email, phone, date, payload) VALUES (?,?,?,?,?,?,?)')
   ->execute([date('Y-m-d H:i'), $kind, $name, $email, $fields['Phone'] ?? '', $date, json_encode($fields, JSON_UNESCAPED_UNICODE)]);
$id = (int)$db->lastInsertId();
$booking = $db->query("SELECT * FROM bookings WHERE id = $id")->fetch(PDO::FETCH_ASSOC);

$err = send_booking_email($booking);
if ($err === null) $db->exec("UPDATE bookings SET emailed = 1 WHERE id = $id");
else error_log("booking #$id email failed: $err");

echo json_encode(['ok' => true, 'id' => $id, 'emailed' => $err === null]);
