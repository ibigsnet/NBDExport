<?php
/**
 * Authenticated LAN scan (Pull tab). Runs as logged-in Unraid WebUI user/session.
 * POST /plugins/NBDExport/include/nbd-scan.php (csrf_token + optional probe_info)
 *
 * The scan sweeps private subnets and remembers peers, so it must only start
 * from the plugin page: POST with the WebUI csrf_token, never a bare GET.
 */
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
