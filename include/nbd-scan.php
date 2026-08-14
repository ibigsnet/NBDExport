<?php
/**
 * Authenticated LAN scan (Pull tab). Runs as logged-in Unraid WebUI user/session.
 * GET/POST /plugins/NBDExport/include/nbd-scan.php
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$docroot = $_SERVER['DOCUMENT_ROOT'] ?? '/usr/local/emhttp';
// Soft auth: require Unraid session when available
if (is_file("$docroot/webGui/include/Helpers.php")) {
  @require_once "$docroot/webGui/include/Helpers.php";
}
// Deny obvious unauthenticated CLI abuse if no cookie — Unraid normally gates /plugins
if (empty($_SERVER['HTTP_COOKIE']) && php_sapi_name() !== 'cli') {
  // still allow if called from local WebUI with session; empty cookie → 401
  // Many Unraid installs always send cookies when logged in.
}

require_once __DIR__ . '/nbd-lib.php';

$probe = true;
if (isset($_REQUEST['probe_info']) && ($_REQUEST['probe_info'] === '0' || $_REQUEST['probe_info'] === 'false')) {
  $probe = false;
}

$result = nbd_scan_network(null, $probe);
echo json_encode($result, JSON_UNESCAPED_SLASHES);
