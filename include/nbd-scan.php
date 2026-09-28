<?php
/** LAN scan (Pull tab). POST + csrf_token; user-selected private /24s only. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  header('Allow: POST');
  echo json_encode(['ok' => false, 'error' => 'POST required']);
  exit;
}
require_once __DIR__ . '/nbd-csrf.php';
// Fail closed: no readable token means no request goes through.
if (!nbd_csrf_ok()) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Invalid csrf_token']);
  exit;
}

require_once __DIR__ . '/nbd-lib.php';

$cidrs = $_POST['cidrs'] ?? [];
if (!is_array($cidrs)) {
  $cidrs = [$cidrs];
}
$mode = ((string)($_POST['mode'] ?? 'beacon') === 'nbd') ? 'nbd' : 'beacon';

$result = nbd_scan_network(null, true, $cidrs, $mode);
echo json_encode($result, JSON_UNESCAPED_SLASHES);
