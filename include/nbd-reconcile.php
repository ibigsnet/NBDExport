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
 * Scan process list for qemu-img convert jobs.
 * @return array<int,array{pid:int,cmd:string,src:string,out:string,managed:bool,job_id:?string}>
 */
function nbd_discover_qemu_img_converts() {
  $managed_outs = [];
  foreach (glob(NBDEXPORT_RUN . '/job-*.json') ?: [] as $f) {
    $j = @json_decode((string)@file_get_contents($f), true);
    if (!is_array($j) || empty($j['id'])) {
      continue;
    }
    $out = (string)($j['output'] ?? '');
    if ($out !== '') {
      $managed_outs[$out] = (string)$j['id'];
    }
  }
  $raw = (string)@shell_exec("ps -eo pid=,cmd= 2>/dev/null | grep '[q]emu-img convert' || true");
  $list = [];
  foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
    $line = trim($line);
    if ($line === '' || !preg_match('/^(\d+)\s+(.*)$/', $line, $m)) {
      continue;
    }
    $pid = (int)$m[1];
    $cmd = $m[2];
    $src = '';
    $out = '';
    if (preg_match('#(nbd://\S+|/dev/\S+|/mnt/\S+|/tmp/\S+)\s+(/mnt/\S+|/tmp/\S+)\s*$#', $cmd, $mm)) {
      $src = $mm[1];
      $out = $mm[2];
    }
    $jid = ($out !== '' && isset($managed_outs[$out])) ? $managed_outs[$out] : null;
    $list[] = [
      'pid' => $pid,
      'cmd' => $cmd,
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
  $beacon = nbd_beacon_ensure();
  $kicked = nbd_pull_queue_kick();
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
