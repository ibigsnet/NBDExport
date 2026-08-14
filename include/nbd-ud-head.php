<?php
/**
 * Soft head hook for Unassigned Devices status badges.
 * Injected into Unraid HeadInlineJS.php (install). Emits nothing unless:
 *  - Settings → ud_status_overlay = yes
 *  - Unassigned Devices plugin is installed
 *  - Current page looks like Main → Unassigned Devices
 *
 * Best-effort DOM overlay only — UD owns that page; layout can change.
 */
if (!defined('NBDEXPORT_UD_HEAD')) {
  define('NBDEXPORT_UD_HEAD', 1);
}

$docroot = $_SERVER['DOCUMENT_ROOT'] ?? '/usr/local/emhttp';
$cfg_path = '/boot/config/plugins/NBDExport/NBDExport.cfg';
$opt = 'no';
if (is_file($cfg_path)) {
  $raw = @file_get_contents($cfg_path);
  if (is_string($raw) && preg_match('/^\s*ud_status_overlay\s*=\s*"?(yes|no)"?/mi', $raw, $m)) {
    $opt = strtolower($m[1]);
  }
}
if ($opt !== 'yes') {
  return;
}
if (!is_dir($docroot . '/plugins/unassigned.devices')) {
  return;
}

$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
// Unraid: /Main/UnassignedDevices (and occasional query strings)
if (stripos($uri, 'UnassignedDevices') === false) {
  return;
}

$ver = '1';
$plg = $docroot . '/plugins/NBDExport/nbd.plg';
if (is_file($plg)) {
  $t = @file_get_contents($plg);
  if (is_string($t) && preg_match('/ENTITY version "([^"]+)"/', $t, $m)) {
    $ver = $m[1];
  }
}
$js = '/plugins/NBDExport/include/nbd-ud-overlay.js?v=' . rawurlencode($ver);
echo "\n<!-- NBDExport UD overlay (opt-in; best-effort) -->\n";
echo '<script src="' . htmlspecialchars($js, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
