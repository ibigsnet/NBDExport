<?php
/**
 * Browser download of NBD Export settings + memory/presets (JSON).
 * Linked from Settings → NBD; requires WebGUI session (Unraid root UI).
 */
require_once '/usr/local/emhttp/plugins/NbdExport/include/nbd-lib.php';

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
