<?php
/**
 * Upgrade reconcile, busy checks, and external qemu-img discovery.
 * Loaded from nbd-lib.php.
 */

/**
 * True if any Host or Pull occupies the plugin (upgrade should wait).
 * @return array{busy:bool,summary:string,hosts:int,pulls:int}
 */
function nbd_busy_snapshot() {
  $hosts = 0;
  foreach (nbd_exports_state() as $e) {
    if (!empty($e['alive']) || !empty($e['listening'])) {
      $hosts++;
    }
  }
  $pulls = 0;
  foreach (nbd_jobs_state() as $j) {
    $k = nbd_job_ui_status($j)['key'] ?? '';
    if (in_array($k, ['running', 'paused', 'queued'], true)) {
      $pulls++;
    }
  }
  foreach (nbd_discover_qemu_img_converts() as $c) {
    if (empty($c['managed'])) {
      $pulls++;
    }
  }
  $busy = ($hosts + $pulls) > 0;
  $parts = [];
  if ($hosts) {
    $parts[] = $hosts . ' host(s)';
  }
  if ($pulls) {
    $parts[] = $pulls . ' convert/pull(s)';
  }
  return [
    'busy' => $busy,
    'hosts' => $hosts,
    'pulls' => $pulls,
    'summary' => $busy ? implode(', ', $parts) : 'idle',
  ];
}

/**
 * qemu-img convert jobs from /proc (argv, not a ps|grep text match).
 * src/out are the last two argv entries; managed if out is a job's output or partial.
 * @return array<int,array{pid:int,cmd:string,src:string,out:string,managed:bool,job_id:?string}>
 */
function nbd_discover_qemu_img_converts() {
  $managed_outs = [];
  foreach (glob(NBDEXPORT_RUN . '/job-*.json') ?: [] as $f) {
    $j = @json_decode((string)@file_get_contents($f), true);
    if (!is_array($j) || empty($j['id'])) {
      continue;
    }
    foreach (['output', 'partial'] as $k) {
      $o = (string)($j[$k] ?? '');
      if ($o !== '') {
        $managed_outs[$o] = (string)$j['id'];
      }
    }
  }
  $list = [];
  foreach (nbd_qemu_img_converts() as $pid => $argv) {
    $n = count($argv);
    $src = $n >= 3 ? (string)$argv[$n - 2] : '';
    $out = $n >= 2 ? (string)$argv[$n - 1] : '';
    if ($out !== '' && strpos($out, '/') !== 0) {
      $out = '';
    }
    $jid = ($out !== '' && isset($managed_outs[$out])) ? $managed_outs[$out] : null;
    $list[] = [
      'pid' => (int)$pid,
      'cmd' => implode(' ', $argv),
      'src' => $src,
      'out' => $out,
      'managed' => $jid !== null,
      'job_id' => $jid,
    ];
  }
  return $list;
}

/**
 * Merge unmanaged qemu-img converts into the jobs list (Status / Dashboard).
 */
function nbd_jobs_with_external() {
  $jobs = nbd_jobs_state();
  $seen_out = [];
  foreach ($jobs as $j) {
    $o = (string)($j['output'] ?? '');
    if ($o !== '') {
      $seen_out[$o] = true;
    }
  }
  foreach (nbd_discover_qemu_img_converts() as $c) {
    if (!empty($c['managed'])) {
      continue;
    }
    $out = (string)($c['out'] ?? '');
    if ($out !== '' && isset($seen_out[$out])) {
      continue;
    }
    $pid = (int)$c['pid'];
    $src = (string)($c['src'] ?? '');
    $paused = function_exists('nbd_pid_is_stopped') && nbd_pid_is_stopped($pid);
    $stype = 'local_file';
    if (strpos($src, 'nbd://') === 0) {
      $stype = 'nbd';
    } elseif (strpos($src, '/dev/') === 0) {
      $stype = 'local_device';
    }
    $jobs[] = [
      'id' => 'ext-' . $pid,
      'url' => $src !== '' ? $src : '(external convert)',
      'output' => $out !== '' ? $out : '(unknown)',
      'format' => '',
      'pid' => $pid,
      'alive' => true,
      'status' => $paused ? 'paused' : 'running',
      'external' => true,
      'started' => '',
      'log' => '',
      'array_like' => $out !== '' && nbd_path_is_array_like($out),
      'source_type' => $stype,
    ];
    if ($out !== '') {
      $seen_out[$out] = true;
    }
  }
  usort($jobs, function ($a, $b) {
    return strcmp($b['started'] ?? '', $a['started'] ?? '');
  });
  return $jobs;
}

/**
 * After plugin install/upgrade: reattach live work, restart beacon, kick queue.
 */
function nbd_reconcile_live() {
  nbd_ensure_runtime_dirs();
  $ext = 0;
  foreach (nbd_discover_qemu_img_converts() as $c) {
    if (empty($c['managed'])) {
      $ext++;
    }
  }
  $hosts = 0;
  foreach (nbd_exports_state() as $e) {
    if (!empty($e['alive']) || !empty($e['listening'])) {
      $hosts++;
    }
  }
  $r = nbd_locked(function () {
    return ['beacon' => nbd_beacon_ensure(), 'jobs' => nbd_jobs_reconcile()];
  });
  $beacon = $r['beacon'];
  $kicked = $r['jobs'];
  return [
    'ok' => true,
    'hosts' => $hosts,
    'pulls' => nbd_count_running_pull_jobs(),
    'external' => $ext,
    'beacon' => $beacon,
    'kicked' => $kicked['started'] ?? [],
    'busy' => nbd_busy_snapshot(),
  ];
}
