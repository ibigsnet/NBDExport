<?php
/** LAN scan (Pull tab). POST + csrf_token only. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  header('Allow: POST');
  echo json_encode(['ok' => false, 'error' => 'POST required']);
  exit;
}
$csrf_expected = '';
if (is_readable('/var/local/emhttp/var.ini')) {
  $var_ini = @parse_ini_file('/var/local/emhttp/var.ini');
  $csrf_expected = is_array($var_ini) ? (string)($var_ini['csrf_token'] ?? '') : '';
}
if ($csrf_expected !== '' && !hash_equals($csrf_expected, (string)($_POST['csrf_token'] ?? ''))) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Invalid csrf_token']);
  exit;
}

require_once __DIR__ . '/nbd-lib.php';

$probe = true;
if (isset($_POST['probe_info']) && ($_POST['probe_info'] === '0' || $_POST['probe_info'] === 'false')) {
  $probe = false;
}

$result = nbd_scan_network(null, $probe);
echo json_encode($result, JSON_UNESCAPED_SLASHES);
