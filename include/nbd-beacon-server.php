<?php
/**
 * Lightweight discovery beacon for php -S (no Unraid session).
 * Private clients only. See docs/discovery.md.
 *
 * Usage: php -S 0.0.0.0:10808 /path/to/nbd-beacon-server.php
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
// php -S may put IPv4-mapped IPv6
if (strpos($remote, '::ffff:') === 0) {
  $remote = substr($remote, 7);
}

$lib = __DIR__ . '/nbd-lib.php';
if (!is_file($lib)) {
  http_response_code(500);
  echo json_encode(['error' => 'nbd-lib missing']);
  exit;
}
require_once $lib;

if (!function_exists('nbd_is_private_ipv4') || !nbd_is_private_ipv4($remote)) {
  http_response_code(403);
  echo json_encode(['error' => 'private clients only', 'remote' => $remote]);
  exit;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($uri !== '/' && $uri !== '/index.json' && $uri !== '/beacon') {
  http_response_code(404);
  echo json_encode(['error' => 'not found']);
  exit;
}

echo json_encode(nbd_beacon_payload(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
