<?php
/**
 * Lightweight live status for NBD Export WebUI auto-refresh.
 * GET /plugins/NBDExport/include/nbd-live-status.php
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$docroot = $_SERVER['DOCUMENT_ROOT'] ?? '/usr/local/emhttp';
require_once $docroot . '/plugins/NBDExport/include/nbd-lib.php';

$snap = nbd_live_snapshot();
echo json_encode(['ok' => true] + $snap, JSON_UNESCAPED_SLASHES);
