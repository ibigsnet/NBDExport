<?php
/**
 * Browser download of NBD Export settings + memory/presets (JSON).
 * POST + csrf_token (Settings tab). GET does not export.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  header('Allow: POST');
  header('Content-Type: text/plain; charset=UTF-8');
  echo "POST required\n";
  exit;
}
$csrf_expected = '';
if (is_readable('/var/local/emhttp/var.ini')) {
  $var_ini = @parse_ini_file('/var/local/emhttp/var.ini');
  $csrf_expected = is_array($var_ini) ? (string)($var_ini['csrf_token'] ?? '') : '';
}
if ($csrf_expected !== '' && !hash_equals($csrf_expected, (string)($_POST['csrf_token'] ?? ''))) {
  http_response_code(403);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "Invalid csrf_token\n";
  exit;
}

require_once '/usr/local/emhttp/plugins/NBDExport/include/nbd-lib.php';

$bundle = nbd_config_export_bundle();
$json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "NBD Export: failed to build config export\n";
  exit;
}

$name = 'nbdexport-config-' . date('Ymd-His') . '.json';
header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo $json . "\n";
