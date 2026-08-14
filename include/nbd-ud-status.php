<?php
/**
 * JSON: active Host exports for Unassigned Devices overlay.
 * Authenticated WebUI only (same session as other plugin includes).
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$docroot = $_SERVER['DOCUMENT_ROOT'] ?? '/usr/local/emhttp';
require_once $docroot . '/plugins/NBDExport/include/nbd-lib.php';

$cfg = nbd_load_cfg();
$opt_in = (($cfg['ud_status_overlay'] ?? 'no') === 'yes');
$ud = is_dir($docroot . '/plugins/unassigned.devices');

if (!$opt_in) {
  echo json_encode(['ok' => true, 'enabled' => false, 'ud_present' => $ud, 'exports' => []]);
  exit;
}

$out = [];
foreach (nbd_exports_state() as $e) {
  if (empty($e['alive']) && empty($e['listening'])) {
    continue;
  }
  $path = (string)($e['device'] ?? '');
  $base = preg_replace('#^/dev/#', '', $path);
  if ($base === '') {
    continue;
  }
  $out[] = [
    'device' => $base,
    'path' => $path !== '' && $path[0] === '/' ? $path : ('/dev/' . $base),
    'read_only' => !empty($e['read_only']),
    'url' => (string)($e['url'] ?? ''),
    'label' => (string)($e['label'] ?? ''),
    'port' => (int)($e['port'] ?? 0),
    'bind' => (string)($e['bind'] ?? ''),
  ];
}

echo json_encode([
  'ok' => true,
  'enabled' => true,
  'ud_present' => $ud,
  'exports' => $out,
], JSON_UNESCAPED_SLASHES);
