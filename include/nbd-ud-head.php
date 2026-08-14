<?php
/**
 * Soft head hook for Unassigned Devices (Main → Unassigned Devices) NBD badges + panel.
 * Injected into Unraid HeadInlineJS.php (install). Emits nothing unless opt-in.
 *
 * JS decides whether the UD tables are present (works for /Main/UnassignedDevices and
 * similar Main paths). Best-effort DOM overlay — UD owns that page.
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

// Load on any WebUI page when opt-in; overlay.js no-ops unless UD disk table exists.
// (Avoid URI-only gates — Unraid Main tab URLs vary.)
$ver = '1';
$plg = $docroot . '/plugins/NBDExport/nbd.plg';
if (is_file($plg)) {
  $t = @file_get_contents($plg);
  if (is_string($t) && preg_match('/ENTITY version "([^"]+)"/', $t, $m)) {
    $ver = $m[1];
  }
}
$js = '/plugins/NBDExport/include/nbd-ud-overlay.js?v=' . rawurlencode($ver);
echo "\n<!-- NBDExport UD overlay (opt-in; Main → Unassigned Devices) -->\n";
echo '<script src="' . htmlspecialchars($js, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
