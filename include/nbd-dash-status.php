<?php
/**
 * Async Dashboard tile body — keep NBDDashboard.page itself cheap so Main
 * Dashboard first paint is not blocked by process scans.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$docroot = $_SERVER['DOCUMENT_ROOT'] ?? '/usr/local/emhttp';
try {
  require_once "$docroot/plugins/NBDExport/include/nbd-lib.php";
  $cfg = nbd_load_cfg();
  $enabled = (($cfg['enabled'] ?? 'yes') === 'yes');

  $hosts = [];
  foreach (nbd_exports_state() as $e) {
    if (empty($e['alive']) && empty($e['listening'])) {
      continue;
    }
    $st = nbd_export_ui_status($e);
    $hosts[] = [
      'device' => (string)($e['device'] ?? ''),
      'url' => (string)($e['url'] ?? ''),
      'ro' => !empty($e['read_only']),
      'label' => (string)($st['label'] ?? ''),
    ];
  }

  $pulls = [];
  foreach ((function_exists('nbd_jobs_with_external') ? nbd_jobs_with_external() : nbd_jobs_state()) as $j) {
    $st = nbd_job_ui_status($j);
    $key = $st['key'] ?? 'idle';
    if (!in_array($key, ['running', 'queued', 'paused'], true)) {
      continue;
    }
    $pct = nbd_job_progress_pct($j);
    $eta = function_exists('nbd_job_progress_eta') ? nbd_job_progress_eta($j) : ['label' => ''];
    $elapsed = function_exists('nbd_job_elapsed_seconds') ? nbd_job_elapsed_seconds($j) : null;
    $rates = ($key === 'running' && function_exists('nbd_job_io_rates')) ? nbd_job_io_rates($j) : [];
    $out = (string)($j['output'] ?? '');
    $short = $out;
    if (strlen($short) > 36) {
      $short = '…' . substr($short, -34);
    }
    $pulls[] = [
      'url' => (string)($j['url'] ?? ''),
      'out' => $out,
      'short' => $short,
      'key' => $key,
      'label' => (string)($st['label'] ?? $key),
      'pct' => $pct,
      'eta' => (string)($eta['label'] ?? ''),
      'elapsed' => ($elapsed !== null) ? nbd_format_duration($elapsed) : '',
      'net' => (string)($rates['net_h'] ?? ''),
      'disk' => (string)($rates['disk_h'] ?? ''),
      'size' => (string)($j['output_size_h'] ?? '—'),
    ];
  }

  $n_host = count($hosts);
  $n_pull = count($pulls);
  $summary = $enabled
    ? ($n_host . ' host' . ($n_host === 1 ? '' : 's') . ' · ' . $n_pull . ' pull' . ($n_pull === 1 ? '' : 's'))
    : 'Plugin disabled';

  ob_start();
  if (!$enabled) {
    echo '<span class="orange-text">NBD Export is disabled</span>';
  } elseif (!$hosts && !$pulls) {
    echo '<span style="opacity:0.75">No active hosts or pulls</span>';
  } else {
    echo '<div style="font-size:0.92em;line-height:1.4">';
    foreach ($hosts as $h) {
      $badge = $h['ro'] ? 'RO' : 'RW';
      $bg = $h['ro'] ? 'rgba(46,160,90,0.4)' : 'rgba(220,140,40,0.45)';
      echo '<div style="margin:0.35em 0;padding:0.35em 0;border-bottom:1px solid rgba(128,128,128,0.25)">';
      echo '<span style="display:inline-block;padding:0.1em 0.45em;border-radius:4px;font-size:0.8em;font-weight:600;background:'
        . $bg . '">' . htmlspecialchars($badge) . '</span> ';
      echo '<code>' . htmlspecialchars($h['device']) . '</code>';
      echo ' <span style="opacity:0.75">· ' . htmlspecialchars($h['label']) . '</span><br>';
      echo '<code style="opacity:0.75;font-size:0.9em">' . htmlspecialchars($h['url']) . '</code>';
      echo '</div>';
    }
    foreach ($pulls as $p) {
      if ($p['key'] === 'running') {
        $bg = 'rgba(220,140,40,0.5)';
      } elseif ($p['key'] === 'paused') {
        $bg = 'rgba(140,90,200,0.5)';
      } else {
        $bg = 'rgba(74,144,217,0.4)';
      }
      echo '<div style="margin:0.35em 0;padding:0.35em 0;border-bottom:1px solid rgba(128,128,128,0.25)">';
      echo '<span style="display:inline-block;padding:0.1em 0.45em;border-radius:4px;font-size:0.8em;font-weight:600;background:'
        . $bg . '">' . htmlspecialchars($p['label']) . '</span> ';
      echo '<span style="white-space:nowrap;font-variant-numeric:tabular-nums">';
      if ($p['pct'] !== null) {
        $ph = rtrim(rtrim(number_format((float)$p['pct'], 1, '.', ''), '0'), '.');
        echo '<strong>' . htmlspecialchars($ph) . '%</strong> ';
      } else {
        echo '<strong>—</strong> ';
      }
      if ($p['elapsed'] !== '') {
        echo '<span style="opacity:0.75">' . htmlspecialchars($p['elapsed']) . '</span> ';
      }
      if ($p['eta'] !== '') {
        echo '<span style="opacity:0.75">· ' . htmlspecialchars($p['eta']) . '</span> ';
      }
      echo '<span style="opacity:0.75">· ' . htmlspecialchars($p['size']) . '</span>';
      if ($p['net'] !== '') {
        echo '<span style="opacity:0.75"> · net ' . htmlspecialchars($p['net']) . '</span>';
      }
      if ($p['disk'] !== '') {
        echo '<span style="opacity:0.75"> · disk ' . htmlspecialchars($p['disk']) . '</span>';
      }
      echo '</span><br>';
      echo '<code style="font-size:0.9em" title="' . htmlspecialchars($p['url']) . '">'
        . htmlspecialchars($p['url']) . '</code><br>';
      echo '<code style="opacity:0.75;font-size:0.9em" title="' . htmlspecialchars($p['out']) . '">'
        . htmlspecialchars($p['short']) . '</code>';
      echo '</div>';
    }
    echo '</div>';
  }
  $html = ob_get_clean();
  echo json_encode(['ok' => true, 'summary' => $summary, 'html' => $html], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'summary' => 'Error', 'html' => '<span class="orange-text">Tile error</span>']);
}
