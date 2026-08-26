<?php
/** packaged install 2026.08.15ac */
/**
 * NBD Export — core helpers (no hard require of ThunderboltNet / FabricRouting).
 */

if (!defined('NBDEXPORT_ROOT')) {
  define('NBDEXPORT_ROOT', '/usr/local/emhttp/plugins/NBDExport');
}
if (!defined('NBDEXPORT_CFG_DIR')) {
  define('NBDEXPORT_CFG_DIR', '/boot/config/plugins/NBDExport');
}
if (!defined('NBDEXPORT_RUN')) {
  define('NBDEXPORT_RUN', '/var/run/nbdexport');
}
if (!defined('NBDEXPORT_LOG')) {
  define('NBDEXPORT_LOG', '/var/log/nbdexport');
}

function nbd_cfg_path() {
  return NBDEXPORT_CFG_DIR . '/NBDExport.cfg';
}

function nbd_default_cfg_path() {
  return NBDEXPORT_ROOT . '/default.cfg';
}

/**
 * Load plugin cfg (key="value" lines).
 */
function nbd_load_cfg() {
  $defaults = [
    'enabled' => 'yes',
    'default_read_only' => 'yes',
    'default_port' => '10809',
    'allow_bind_all' => 'no',
    'destructive_mode' => 'no',
    'rehydrate_on_start' => 'no',
    // Optional comma list of private CIDRs to always scan (e.g. "192.168.1.0/24")
    'scan_extra_subnets' => '',
    // Opt-in: small NBD RO/RW badges on Main → Unassigned Devices (DOM overlay; UD owns that page)
    'ud_status_overlay' => 'no',
    // Pull jobs: keep array WebUI responsive (idle IO + nice; limit concurrency)
    'max_concurrent_pulls' => '1',
    'pull_io_class' => 'idle', // idle | best-effort
    'pull_nice' => '10',
    // Allow plugin package upgrade while Host/Pull busy (default No — safer)
    'allow_upgrade_while_busy' => 'no',
  ];
  $path = nbd_cfg_path();
  if (!is_file($path) && is_file(nbd_default_cfg_path())) {
    $path = nbd_default_cfg_path();
  }
  $out = $defaults;
  if (!is_file($path)) {
    return $out;
  }
  $raw = @file_get_contents($path);
  if (!is_string($raw)) {
    return $out;
  }
  foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === ';' || $line[0] === '#') {
      continue;
    }
    if (preg_match('/^([A-Za-z0-9_]+)\s*=\s*"(.*)"\s*$/', $line, $m)) {
      $out[$m[1]] = stripcslashes($m[2]);
    } elseif (preg_match('/^([A-Za-z0-9_]+)\s*=\s*(\S+)\s*$/', $line, $m)) {
      $out[$m[1]] = $m[2];
    }
  }
  return $out;
}

function nbd_write_cfg(array $cfg) {
  if (!is_dir(NBDEXPORT_CFG_DIR)) {
    @mkdir(NBDEXPORT_CFG_DIR, 0755, true);
  }
  $keys = [
    'enabled', 'default_read_only', 'default_port', 'allow_bind_all', 'destructive_mode',
    'rehydrate_on_start', 'scan_extra_subnets', 'ud_status_overlay',
    'max_concurrent_pulls', 'pull_io_class', 'pull_nice', 'allow_upgrade_while_busy',
  ];
  $lines = ['; NBD Export — written by plugin', ''];
  foreach ($keys as $k) {
    $v = isset($cfg[$k]) ? (string)$cfg[$k] : '';
    $v = str_replace(['\\', '"'], ['\\\\', '\\"'], $v);
    $lines[] = $k . '="' . $v . '"';
  }
  return @file_put_contents(nbd_cfg_path(), implode("\n", $lines) . "\n") !== false;
}

function nbd_ensure_runtime_dirs() {
  foreach ([NBDEXPORT_RUN, NBDEXPORT_LOG, NBDEXPORT_CFG_DIR] as $d) {
    if (!is_dir($d)) {
      @mkdir($d, 0755, true);
    }
  }
}

/**
 * Flash memory: last-used host/pull fields + named presets (no secrets).
 * Path: /boot/config/plugins/NBDExport/memory.json
 */
function nbd_memory_path() {
  return NBDEXPORT_CFG_DIR . '/memory.json';
}

function nbd_memory_load() {
  nbd_ensure_runtime_dirs();
  $path = nbd_memory_path();
  $empty = [
    'last_host' => [],
    'last_pull' => [],
    'presets' => [],
  ];
  if (!is_readable($path)) {
    return $empty;
  }
  $j = @json_decode((string)@file_get_contents($path), true);
  if (!is_array($j)) {
    return $empty;
  }
  return array_merge($empty, $j);
}

function nbd_memory_save(array $mem) {
  nbd_ensure_runtime_dirs();
  if (!isset($mem['presets']) || !is_array($mem['presets'])) {
    $mem['presets'] = [];
  }
  // Cap presets
  if (count($mem['presets']) > 40) {
    $mem['presets'] = array_slice($mem['presets'], -40, null, true);
  }
  return @file_put_contents(
    nbd_memory_path(),
    json_encode($mem, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
  ) !== false;
}

/**
 * @param string|string[] $bind Primary bind IP, or list of binds
 * @param string[]|null $binds Optional multi-bind list (preferred when hosting on several networks)
 */
function nbd_memory_remember_host($device, $bind, $port, $read_only, $label, $binds = null) {
  $list = [];
  if (is_array($binds)) {
    foreach ($binds as $b) {
      $b = trim((string)$b);
      if ($b !== '' && !in_array($b, $list, true)) {
        $list[] = $b;
      }
    }
  }
  if (!$list) {
    if (is_array($bind)) {
      foreach ($bind as $b) {
        $b = trim((string)$b);
        if ($b !== '' && !in_array($b, $list, true)) {
          $list[] = $b;
        }
      }
    } else {
      $b = trim((string)$bind);
      if ($b !== '') {
        $list[] = $b;
      }
    }
  }
  $primary = $list ? $list[0] : '';
  $mem = nbd_memory_load();
  $mem['last_host'] = [
    'device' => (string)$device,
    'bind' => $primary,
    'binds' => $list,
    'port' => (int)$port,
    'read_only' => $read_only ? 'yes' : 'no',
    'label' => (string)$label,
    'saved_at' => date('c'),
  ];
  nbd_memory_save($mem);
}

function nbd_memory_remember_pull($url, $output, $format) {
  $mem = nbd_memory_load();
  $mem['last_pull'] = [
    'nbd_url' => (string)$url,
    'output' => (string)$output,
    'format' => (string)$format,
    'saved_at' => date('c'),
  ];
  nbd_memory_save($mem);
}

/**
 * Save a named preset.
 * @param string $name
 * @param string $type host|pull
 * @param array $fields
 */
function nbd_memory_save_preset($name, $type, array $fields) {
  $name = trim(preg_replace('/[^A-Za-z0-9._ -]/', '', $name));
  $name = trim($name);
  if ($name === '' || strlen($name) > 48) {
    return ['ok' => false, 'error' => 'Preset name must be 1–48 safe characters.'];
  }
  if (!in_array($type, ['host', 'pull'], true)) {
    return ['ok' => false, 'error' => 'Invalid preset type.'];
  }
  $mem = nbd_memory_load();
  $mem['presets'][$name] = [
    'type' => $type,
    'fields' => $fields,
    'saved_at' => date('c'),
  ];
  nbd_memory_save($mem);
  return ['ok' => true, 'name' => $name];
}

function nbd_memory_delete_preset($name) {
  $mem = nbd_memory_load();
  if (!isset($mem['presets'][$name])) {
    return ['ok' => false, 'error' => 'Preset not found.'];
  }
  unset($mem['presets'][$name]);
  nbd_memory_save($mem);
  return ['ok' => true];
}

/**
 * Portable backup: settings + last-used + presets (no secrets, no live pids).
 * Safe to keep outside /boot/config/plugins/NBDExport (uninstall wipes that tree).
 */
function nbd_config_export_bundle() {
  $mem = nbd_memory_load();
  return [
    'format' => 'nbdexport-config',
    'format_version' => 1,
    'plugin' => 'NBDExport',
    'exported_at' => date('c'),
    'plugin_version' => nbd_plugin_version(),
    'settings' => nbd_load_cfg(),
    'memory' => [
      'last_host' => is_array($mem['last_host'] ?? null) ? $mem['last_host'] : [],
      'last_pull' => is_array($mem['last_pull'] ?? null) ? $mem['last_pull'] : [],
      'presets' => is_array($mem['presets'] ?? null) ? $mem['presets'] : [],
    ],
  ];
}

/**
 * Write export JSON under /boot/config/ (outside plugin dir so uninstall keeps it).
 * @return array{ok:bool,path?:string,error?:string}
 */
function nbd_config_export_to_flash($basename = '') {
  $bundle = nbd_config_export_bundle();
  $json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    return ['ok' => false, 'error' => 'JSON encode failed'];
  }
  $base = trim((string)$basename);
  if ($base === '') {
    $base = 'nbdexport-config-' . date('Ymd-His') . '.json';
  }
  $base = basename($base);
  if (!preg_match('/\.json$/i', $base)) {
    $base .= '.json';
  }
  if (!preg_match('/^[A-Za-z0-9._-]+$/', $base) || strlen($base) > 80) {
    return ['ok' => false, 'error' => 'Invalid filename (use simple name.json)'];
  }
  $dir = '/boot/config';
  if (!is_dir($dir)) {
    return ['ok' => false, 'error' => '/boot/config not available'];
  }
  $path = $dir . '/' . $base;
  if (@file_put_contents($path, $json . "\n") === false) {
    return ['ok' => false, 'error' => 'Could not write ' . $path];
  }
  return ['ok' => true, 'path' => $path];
}

/**
 * Restore settings and/or memory from export bundle.
 * @param array $bundle
 * @param bool $settings
 * @param bool $memory
 * @return array{ok:bool,error?:string,msg?:string}
 */
function nbd_config_import_bundle(array $bundle, $settings = true, $memory = true) {
  $fmt = $bundle['format'] ?? '';
  if ($fmt !== 'nbdexport-config' && $fmt !== '') {
    // Allow slightly loose imports if memory/settings keys present
    if (!isset($bundle['settings']) && !isset($bundle['memory'])) {
      return ['ok' => false, 'error' => 'Not an NBD Export config file (missing format/settings).'];
    }
  }
  $ver = (int)($bundle['format_version'] ?? 1);
  if ($ver > 1) {
    return ['ok' => false, 'error' => 'Config format version ' . $ver . ' is newer than this plugin supports.'];
  }

  $parts = [];
  if ($settings && isset($bundle['settings']) && is_array($bundle['settings'])) {
    $cfg = nbd_load_cfg();
    foreach ([
      'enabled', 'default_read_only', 'default_port', 'allow_bind_all', 'destructive_mode',
      'rehydrate_on_start', 'scan_extra_subnets', 'ud_status_overlay',
      'max_concurrent_pulls', 'pull_io_class', 'pull_nice',
    ] as $k) {
      if (array_key_exists($k, $bundle['settings'])) {
        $cfg[$k] = (string)$bundle['settings'][$k];
      }
    }
    foreach ([
      'enabled', 'default_read_only', 'allow_bind_all', 'destructive_mode',
      'rehydrate_on_start', 'ud_status_overlay',
    ] as $k) {
      $cfg[$k] = (($cfg[$k] ?? 'no') === 'yes') ? 'yes' : 'no';
    }
    if (!nbd_write_cfg($cfg)) {
      return ['ok' => false, 'error' => 'Failed writing settings'];
    }
    $parts[] = 'settings';
  }

  if ($memory && isset($bundle['memory']) && is_array($bundle['memory'])) {
    $mem = nbd_memory_load();
    if (isset($bundle['memory']['last_host']) && is_array($bundle['memory']['last_host'])) {
      $mem['last_host'] = $bundle['memory']['last_host'];
    }
    if (isset($bundle['memory']['last_pull']) && is_array($bundle['memory']['last_pull'])) {
      $mem['last_pull'] = $bundle['memory']['last_pull'];
    }
    if (isset($bundle['memory']['presets']) && is_array($bundle['memory']['presets'])) {
      $presets = [];
      foreach ($bundle['memory']['presets'] as $name => $pv) {
        if (!is_array($pv)) {
          continue;
        }
        $safe = trim(preg_replace('/[^A-Za-z0-9._ -]/', '', (string)$name));
        if ($safe === '' || strlen($safe) > 48) {
          continue;
        }
        $type = ($pv['type'] ?? '') === 'pull' ? 'pull' : 'host';
        $presets[$safe] = [
          'type' => $type,
          'fields' => is_array($pv['fields'] ?? null) ? $pv['fields'] : [],
          'saved_at' => (string)($pv['saved_at'] ?? date('c')),
        ];
      }
      $mem['presets'] = $presets;
    }
    if (!nbd_memory_save($mem)) {
      return ['ok' => false, 'error' => 'Failed writing memory/presets'];
    }
    $parts[] = 'last-used + presets';
  }

  if (!$parts) {
    return ['ok' => false, 'error' => 'Nothing to import in this file'];
  }
  return ['ok' => true, 'msg' => 'Imported ' . implode(' · ', $parts)];
}

/**
 * Import from a JSON file path. Only /boot/config/** and /mnt/** (not plugin live paths required).
 */
function nbd_config_import_from_path($path) {
  $path = trim((string)$path);
  if ($path === '' || strpos($path, "\0") !== false) {
    return ['ok' => false, 'error' => 'Path required'];
  }
  // Resolve and restrict
  $real = realpath($path);
  if ($real === false || !is_readable($real) || !is_file($real)) {
    // allow non-realpath if under /boot/config still exists
    if (!is_readable($path) || !is_file($path)) {
      return ['ok' => false, 'error' => 'File not found or not readable'];
    }
    $real = $path;
  }
  $ok_prefix = false;
  foreach (['/boot/config/', '/mnt/'] as $p) {
    if (strpos($real, $p) === 0) {
      $ok_prefix = true;
      break;
    }
  }
  if (!$ok_prefix) {
    return ['ok' => false, 'error' => 'Path must be under /boot/config/ or /mnt/'];
  }
  $raw = @file_get_contents($real);
  if (!is_string($raw) || $raw === '') {
    return ['ok' => false, 'error' => 'Empty or unreadable file'];
  }
  $j = json_decode($raw, true);
  if (!is_array($j)) {
    return ['ok' => false, 'error' => 'Invalid JSON'];
  }
  return nbd_config_import_bundle($j, true, true);
}

/**
 * Normalize host export status for UI badges.
 * listening | process_up | stale | down
 */
function nbd_export_ui_status(array $e) {
  $alive = !empty($e['alive']);
  $listen = !empty($e['listening']);
  $stale = !empty($e['stale']);
  if ($alive && $listen) {
    return ['key' => 'listening', 'label' => 'Listening', 'class' => 'nbd-badge-ok', 'hint' => 'qemu-nbd running and port open'];
  }
  if ($alive && !$listen) {
    return ['key' => 'process_up', 'label' => 'Active', 'class' => 'nbd-badge-info', 'hint' => 'Export process is running; port not fully confirmed yet'];
  }
  if ($stale || (!$alive && !$listen)) {
    return ['key' => 'stale', 'label' => 'Stopped / stale', 'class' => 'nbd-badge-stale', 'hint' => 'State file left behind — safe to Stop/clear'];
  }
  return ['key' => 'down', 'label' => 'Down', 'class' => 'nbd-badge-bad', 'hint' => 'Not running'];
}

/**
 * Job status for UI: queued | running | done | failed | idle
 * Colors: Running orange, Paused purple, Queued blue, Done green, Failed red, Idle grey.
 */
function nbd_job_ui_status(array $j) {
  $status = (string)($j['status'] ?? '');
  $alive = !empty($j['alive']);
  $fin = !empty($j['finished']);
  $ok = !empty($j['ok']);
  if ($status === 'queued' && !$alive && !$fin) {
    return [
      'key' => 'queued',
      'label' => 'Queued',
      'class' => 'nbd-badge-info',
      'hint' => (string)($j['queue_hint'] ?? 'Waiting for a free Pull slot — Play to start'),
    ];
  }
  if ($status === 'paused') {
    return [
      'key' => 'paused',
      'label' => 'Paused',
      'class' => 'nbd-badge-paused',
      'hint' => 'SIGSTOP — Resume when ready (e.g. after parity). Slot stays reserved.',
    ];
  }
  if ($alive || $status === 'running') {
    $hint = 'qemu-img convert in progress';
    $label = 'Running';
    if (!empty($j['external'])) {
      $label = 'External';
      $hint = 'qemu-img convert not started by this plugin — Pause/Stop still work';
    }
    if (!empty($j['orphaned'])) {
      $hint = 'Orphaned convert still running (wrapper died) — Stop to kill it';
    }
    return ['key' => 'running', 'label' => $label, 'class' => 'nbd-badge-run', 'hint' => $hint];
  }
  if ($fin && $ok) {
    return ['key' => 'done', 'label' => 'Done', 'class' => 'nbd-badge-ok', 'hint' => 'Finished successfully'];
  }
  if ($fin && !$ok) {
    $why = function_exists('nbd_job_fail_summary') ? nbd_job_fail_summary($j) : '';
    return [
      'key' => 'failed',
      'label' => 'Failed',
      'class' => 'nbd-badge-bad',
      'hint' => $why !== '' ? $why : 'See log tail',
    ];
  }
  // Process gone without a finish marker still means the job ended (usually error).
  if (!$alive && !empty($j['pid']) && $status !== 'queued') {
    $why = function_exists('nbd_job_fail_summary') ? nbd_job_fail_summary($j) : '';
    return [
      'key' => 'failed',
      'label' => 'Failed',
      'class' => 'nbd-badge-bad',
      'hint' => $why !== '' ? $why : 'Process exited — see log tail',
    ];
  }
  return ['key' => 'idle', 'label' => 'Idle', 'class' => 'nbd-badge-stale', 'hint' => 'Not running'];
}

/**
 * Known failure codes / markers → short Status reasons (maintainer table).
 * Keys: NBD_JOB_FAIL tokens, qemu log snippets, or signal exit codes (128+N).
 * Keep reasons one-line and plain — Status shows these without digging the log.
 *
 * @return array<string,string>
 */
function nbd_fail_reason_table() {
  return [
    // Wrapper tokens (NBD_JOB_FAIL …)
    'wait_src' => 'Could not open source (NBD unreachable or device missing)',
    'convert' => 'Convert failed (see log)',
    'cancelled_while_queued' => 'Cancelled while queued',
    'stopped_by_user' => 'Stopped by user',
    'process_exited' => 'Process exited unexpectedly',
    // Signal deaths (wait status → 128+signo) seen in the wild
    '138' => 'Killed by SIGUSR1 (old progress poll bug — update plugin and Retry)',
    '137' => 'Killed (SIGKILL) — OOM or manual kill',
    '143' => 'Terminated (SIGTERM) — Stop or system shutdown',
    '139' => 'Crashed (SIGSEGV)',
    '134' => 'Aborted (SIGABRT)',
    '130' => 'Interrupted (SIGINT / Ctrl-C)',
    '129' => 'Hung up (SIGHUP)',
    '9' => 'Killed (SIGKILL)',
    '15' => 'Terminated (SIGTERM)',
    '10' => 'Killed by SIGUSR1',
    // qemu-img / system message fragments (matched in log; longest needles first in matcher)
    'no route to host' => 'No route to NBD host (network / VPN / firewall)',
    'connection refused' => 'NBD connection refused (export down or wrong port)',
    'connection reset by peer' => 'NBD connection reset by peer (export stopped mid-pull)',
    'connection timed out' => 'NBD connection timed out',
    'network is unreachable' => 'Network unreachable to NBD host',
    'host is down' => 'NBD host is down',
    'name or service not known' => 'Hostname did not resolve',
    'temporary failure in name resolution' => 'DNS lookup failed for NBD host',
    'no space left' => 'No space left on destination',
    'disk quota exceeded' => 'Disk quota exceeded on destination',
    'permission denied' => 'Permission denied on source or output',
    'read-only file system' => 'Destination is read-only',
    'input/output error' => 'I/O error on source or destination',
    'could not open' => 'Could not open source or output',
    'failed to connect' => 'Failed to connect to NBD source',
    'is not a regular file' => 'Output path is not a regular file',
    'no such file or directory' => 'Path missing (source, output dir, or device)',
    'protocol error' => 'NBD protocol error (peer / qemu mismatch)',
    'server rejected' => 'NBD server rejected the request',
    'export not found' => 'NBD export name not found on peer',
    'image format' => 'Image format error (corrupt or wrong type)',
    'invalid argument' => 'Invalid argument to qemu-img / NBD',
    'operation not permitted' => 'Operation not permitted (capabilities / mount flags)',
    'broken pipe' => 'Broken pipe (peer closed mid-transfer)',
  ];
}

/** Support / project URLs (forum + GitHub). */
function nbd_support_links() {
  return [
    'forum' => 'https://forums.unraid.net/topic/200219-plugin-nbd-export-host-disks-over-network-block-device-image-to-qcow2raw/',
    'github' => 'https://github.com/ibigsnet/NBDExport',
    'issues' => 'https://github.com/ibigsnet/NBDExport/issues',
  ];
}

/**
 * Whether a fail code/reason is in our known map (no "Found a bug?" CTA).
 * Vague convert-without-mapped-rc and unknown tokens are not known.
 */
function nbd_fail_reason_is_known($code, $reason = '') {
  $code = trim((string)$code);
  $reason = trim((string)$reason);
  if ($code === '' || $code === 'unknown') {
    return false;
  }
  $table = nbd_fail_reason_table();
  if (preg_match('/rc=(\d+)/', $code, $m) && isset($table[$m[1]])) {
    return true;
  }
  $token = strtolower(trim(preg_replace('/\s+rc=\d+\s*$/i', '', $code)));
  if ($token !== '' && isset($table[$token])) {
    // Bare "convert" / "convert rc=NN" without a mapped signal/rc is not specific enough.
    if ($token === 'convert') {
      return preg_match('/rc=(\d+)/', $code, $m2) && isset($table[$m2[1]]);
    }
    return true;
  }
  if (isset($table[$code])) {
    return true;
  }
  if ($reason !== '') {
    foreach ($table as $k => $msg) {
      if ($k === 'convert') {
        continue;
      }
      if ($msg === $reason) {
        return true;
      }
    }
  }
  return false;
}

/**
 * Parse job log / stored fields into a short fail reason for Status.
 * @return array{code:string,reason:string,known:bool}
 */
function nbd_job_fail_info(array $j) {
  if (!empty($j['fail_reason']) && is_string($j['fail_reason'])) {
    $code = (string)($j['fail_code'] ?? '');
    $reason = (string)$j['fail_reason'];
    $known = array_key_exists('fail_known', $j)
      ? !empty($j['fail_known'])
      : nbd_fail_reason_is_known($code, $reason);
    return ['code' => $code, 'reason' => $reason, 'known' => $known];
  }
  $table = nbd_fail_reason_table();
  $log = (string)($j['log'] ?? '');
  $text = '';
  if ($log !== '' && is_file($log)) {
    $fh = @fopen($log, 'rb');
    if ($fh) {
      $sz = @filesize($log);
      if ($sz > 16384) {
        @fseek($fh, -16384, SEEK_END);
      }
      $text = (string)@stream_get_contents($fh);
      @fclose($fh);
    }
  }
  $code = '';
  $reason = '';
  $known = false;
  // NBD_JOB_FAIL convert rc=138  OR  NBD_JOB_FAIL wait_src
  if (preg_match('/NBD_JOB_FAIL\s+(\S+)(?:\s+rc=(\d+))?/i', $text, $m)) {
    $token = strtolower($m[1]);
    $rc = isset($m[2]) ? $m[2] : '';
    if ($rc !== '' && isset($table[$rc])) {
      $code = ($token === 'convert' || $token === '')
        ? ('convert rc=' . $rc)
        : ($token . ' rc=' . $rc);
      $reason = $table[$rc];
      $known = true;
    } elseif (isset($table[$token])) {
      $code = $token;
      $reason = $table[$token];
      if ($rc !== '') {
        $code .= ' rc=' . $rc;
        if ($token === 'convert' && isset($table[$rc])) {
          $reason = $table[$rc];
          $known = true;
        } elseif ($token === 'convert') {
          $reason = 'Convert failed (exit ' . $rc . ') — open Log or Found a bug?';
          $known = false;
        } else {
          $reason .= ' (exit ' . $rc . ')';
          $known = true;
        }
      } else {
        // Bare convert without rc is not specific enough for a closed-form reason.
        $known = ($token !== 'convert');
      }
    } else {
      $code = $token . ($rc !== '' ? (' rc=' . $rc) : '');
      $reason = 'Job failed: ' . $token . ($rc !== '' ? (' (exit ' . $rc . ')') : '');
      $known = false;
    }
  }
  // Refine vague convert/unknown with log message fragments (ENOSPC, refused, …).
  if ((!$known || $reason === '') && $text !== '') {
    $low = strtolower($text);
    $skip = ['wait_src', 'convert', 'cancelled_while_queued', 'stopped_by_user', 'process_exited'];
    // Prefer longer needles so "connection reset by peer" wins over shorter fragments.
    $needles = [];
    foreach ($table as $needle => $msg) {
      if (ctype_digit((string)$needle) || in_array($needle, $skip, true)) {
        continue;
      }
      $needles[(string)$needle] = $msg;
    }
    uksort($needles, function ($a, $b) {
      return strlen($b) - strlen($a);
    });
    foreach ($needles as $needle => $msg) {
      if (strpos($low, $needle) !== false) {
        $frag_code = $needle;
        // Keep convert rc=… when present; append fragment for support digs.
        if ($code !== '' && preg_match('/rc=\d+/', $code)) {
          $code = $code . ' / ' . $frag_code;
        } else {
          $code = $frag_code;
        }
        $reason = $msg;
        $known = true;
        break;
      }
    }
  }
  if ($reason === '') {
    $code = 'unknown';
    $reason = 'Failed — open Log or use Found a bug?';
    $known = false;
  }
  return ['code' => $code, 'reason' => $reason, 'known' => $known];
}

/** One-line fail summary for badges / cards. */
function nbd_job_fail_summary(array $j) {
  $info = nbd_job_fail_info($j);
  return (string)($info['reason'] ?? '');
}

/**
 * Plain-text plugin diagnostics for a failed job (forum / GitHub paste).
 * No secrets — URLs, paths, exit tokens, log tail only.
 */
function nbd_job_diagnostics_text(array $j) {
  $fail = nbd_job_fail_info($j);
  $unraid = '';
  if (is_readable('/etc/unraid-version')) {
    $ini = @parse_ini_file('/etc/unraid-version');
    $unraid = is_array($ini) && isset($ini['version']) ? (string)$ini['version'] : '';
  }
  $tools = function_exists('nbd_detect_tools') ? nbd_detect_tools() : [];
  $qemu_img = (string)($tools['qemu_img'] ?? '');
  $qemu_ver = '';
  if ($qemu_img !== '') {
    $qemu_ver = trim((string)@shell_exec(escapeshellarg($qemu_img) . ' --version 2>/dev/null | head -n1'));
  }
  $out = [];
  $out[] = '=== NBD Export job diagnostics ===';
  $out[] = 'plugin_version: ' . (function_exists('nbd_plugin_version') ? nbd_plugin_version() : 'unknown');
  $out[] = 'hostname: ' . (gethostname() ?: '');
  $out[] = 'unraid: ' . $unraid;
  $out[] = 'time: ' . date('c');
  $out[] = 'job_id: ' . (string)($j['id'] ?? '');
  $out[] = 'status: ' . (string)($j['status'] ?? '');
  $out[] = 'fail_code: ' . (string)($fail['code'] ?? '');
  $out[] = 'fail_reason: ' . (string)($fail['reason'] ?? '');
  $out[] = 'fail_known: ' . (!empty($fail['known']) ? 'yes' : 'no');
  $out[] = 'source: ' . (string)($j['url'] ?? '');
  $out[] = 'output: ' . (string)($j['output'] ?? '');
  $out[] = 'format: ' . (string)($j['format'] ?? '');
  $out[] = 'started: ' . (string)($j['started'] ?? '');
  $out[] = 'finished: ' . (string)($j['finished'] ?? ($j['ended'] ?? ''));
  $out[] = 'pid: ' . (string)($j['pid'] ?? '');
  $out[] = 'qemu_img: ' . ($qemu_img !== '' ? $qemu_img : '(not found)');
  $out[] = 'qemu_version: ' . ($qemu_ver !== '' ? $qemu_ver : '(unknown)');
  $log = (string)($j['log'] ?? '');
  $out[] = '--- job log (tail) ---';
  if ($log !== '' && is_file($log)) {
    $tail = function_exists('nbd_log_tail_display')
      ? nbd_log_tail_display($log, 40)
      : '';
    if ($tail === '') {
      $raw = (string)@file_get_contents($log);
      if (strlen($raw) > 12000) {
        $raw = substr($raw, -12000);
      }
      $tail = $raw;
    }
    $out[] = rtrim($tail) !== '' ? rtrim($tail) : '(empty)';
  } else {
    $out[] = '(no log file)';
  }
  $out[] = '=== end ===';
  return implode("\n", $out) . "\n";
}

/** True if path is Unraid array-like (parity contention under concurrent writes). */
function nbd_path_is_array_like($path) {
  $path = (string)$path;
  return (bool)preg_match('#^/mnt/(disk\d+|user0?|disks)(/|$)#', $path);
}

/**
 * Parse ISO / epoch / common date strings → unix timestamp or null.
 */
function nbd_parse_when($when) {
  if ($when === null || $when === '' || $when === '—') {
    return null;
  }
  if (is_int($when) || (is_string($when) && ctype_digit($when))) {
    $n = (int)$when;
    return $n > 0 ? $n : null;
  }
  $s = trim((string)$when);
  $ts = @strtotime($s);
  return ($ts !== false && $ts > 0) ? $ts : null;
}

/**
 * Format stored time using Unraid Display → Date and Time prefs (same idea as Thunderbolt Net).
 */
function nbd_format_when($when) {
  $ts = nbd_parse_when($when);
  if ($ts === null) {
    $raw = is_scalar($when) ? trim((string)$when) : '';
    return ($raw === '' || $raw === '—') ? '—' : $raw;
  }
  if (function_exists('my_time')) {
    $out = my_time($ts);
    if (is_string($out) && $out !== '' && strtolower($out) !== 'unknown') {
      return $out;
    }
  }
  $date_fmt = '%c';
  $time_fmt = '%R';
  if (isset($GLOBALS['display']) && is_array($GLOBALS['display'])) {
    $date_fmt = (string)($GLOBALS['display']['date'] ?? $date_fmt);
    $time_fmt = (string)($GLOBALS['display']['time'] ?? $time_fmt);
  } elseif (is_readable('/boot/config/plugins/dynamix/dynamix.cfg')) {
    $ini = @parse_ini_file('/boot/config/plugins/dynamix/dynamix.cfg', true);
    if (is_array($ini) && !empty($ini['display'])) {
      $date_fmt = (string)($ini['display']['date'] ?? $date_fmt);
      $time_fmt = (string)($ini['display']['time'] ?? $time_fmt);
    }
  }
  $legacy = [
    '%c' => 'D j M Y h:i A', '%A' => 'l', '%Y' => 'Y', '%B' => 'F', '%e' => 'j',
    '%d' => 'd', '%m' => 'm', '%I' => 'h', '%H' => 'H', '%M' => 'i', '%S' => 's',
    '%p' => 'A', '%R' => 'H:i', '%F' => 'Y-m-d', '%T' => 'H:i:s',
  ];
  $fmt = ($date_fmt !== '%c') ? ($date_fmt . ', ' . $time_fmt) : $date_fmt;
  return date(strtr($fmt, $legacy), $ts);
}

function nbd_format_when_html($when) {
  $pretty = nbd_format_when($when);
  $raw = is_scalar($when) ? trim((string)$when) : '';
  if ($pretty === '—' || $raw === '') {
    return '—';
  }
  $title = $raw;
  if ($title === '' || $title === $pretty) {
    $ts = nbd_parse_when($when);
    $title = $ts ? date('c', $ts) : $pretty;
  }
  return '<span class="nbd-when" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($pretty, ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * Parse latest (N/100%) or pct=N from a blob of text. Returns null if none.
 */
function nbd_parse_progress_pct_text($text) {
  $text = str_replace("\r", "\n", (string)$text);
  if ($text === '') {
    return null;
  }
  $best = null;
  if (preg_match_all('/pct=(\d+(?:\.\d+)?)/', $text, $mm) && !empty($mm[1])) {
    $best = (float)end($mm[1]);
  }
  if (preg_match_all('/\((\d+(?:\.\d+)?)\/100%\)/', $text, $mm) && !empty($mm[1])) {
    $v = (float)end($mm[1]);
    if ($best === null || $v >= $best) {
      $best = $v;
    }
  }
  return $best;
}

/**
 * Latest Pull progress percent (0–100) from sidecar / PROGRAW / log, or null.
 * Takes the max across sources so a stale pct=0 sidecar cannot hide real progress
 * that landed in the log (some qemu builds emit -p on stdout).
 */
function nbd_job_progress_pct(array $j) {
  $id = (string)($j['id'] ?? '');
  $cands = [];
  if ($id !== '') {
    foreach ([
      NBDEXPORT_RUN . '/' . $id . '.progress',
      NBDEXPORT_RUN . '/' . $id . '.progress.raw',
    ] as $pf) {
      if (!is_file($pf)) {
        continue;
      }
      $raw = (string)@file_get_contents($pf);
      // Prefer tail of raw CR spam
      if (strlen($raw) > 4096) {
        $raw = substr($raw, -4096);
      }
      $v = nbd_parse_progress_pct_text($raw);
      if ($v !== null) {
        $cands[] = $v;
      }
    }
  }
  $log = (string)($j['log'] ?? '');
  if ($log !== '' && is_file($log)) {
    $fh = @fopen($log, 'rb');
    if ($fh) {
      $size = @filesize($log);
      if ($size > 8192) {
        @fseek($fh, -8192, SEEK_END);
      }
      $tail = (string)@stream_get_contents($fh);
      @fclose($fh);
      $v = nbd_parse_progress_pct_text($tail);
      if ($v !== null) {
        $cands[] = $v;
      }
    }
  }
  if (!$cands) {
    return null;
  }
  return max($cands);
}

/**
 * Collect recent (unix_ts, pct) samples for ETA — hist sidecar, else timestamped log lines.
 * @return array<int,array{0:int,1:float}>
 */
function nbd_job_progress_samples(array $j, $max = 40) {
  $samples = [];
  $id = (string)($j['id'] ?? '');
  if ($id !== '') {
    $hf = NBDEXPORT_RUN . '/' . $id . '.progress.hist';
    if (is_file($hf)) {
      $lines = @file($hf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      if (is_array($lines)) {
        foreach ($lines as $line) {
          // "epoch pct" — integer or fractional
          if (preg_match('/^(\d+)\s+(\d+(?:\.\d+)?)\s*$/', trim($line), $m)) {
            $samples[] = [(int)$m[1], (float)$m[2]];
          }
        }
      }
    }
  }
  if (count($samples) < 2) {
    $log = (string)($j['log'] ?? '');
    if ($log !== '' && is_file($log)) {
      $fh = @fopen($log, 'rb');
      if ($fh) {
        $size = @filesize($log);
        if ($size > 32768) {
          @fseek($fh, -32768, SEEK_END);
        }
        $tail = (string)@stream_get_contents($fh);
        @fclose($fh);
        foreach (preg_split('/\r\n|\r|\n/', $tail) as $line) {
          // 2026-08-26T01:00:00-04:00 progress (12/100%)
          if (preg_match('/^(\d{4}-\d{2}-\d{2}T[^\s]+)\s+progress\s+\((\d+(?:\.\d+)?)\/100%\)/', trim($line), $m)) {
            $ts = @strtotime($m[1]);
            if ($ts) {
              $samples[] = [(int)$ts, (float)$m[2]];
            }
          }
        }
      }
    }
  }
  if (count($samples) > $max) {
    $samples = array_slice($samples, -$max);
  }
  return $samples;
}

/**
 * Human ETA from recent progress rate. Empty label until we can estimate
 * (no sticky "ETA…"). Uses last ~15 min of samples when available.
 *
 * @return array{seconds:?int,label:string,rate_pct_per_min:?float}
 */
function nbd_job_progress_eta(array $j) {
  $empty = ['seconds' => null, 'label' => '', 'rate_pct_per_min' => null];
  $st = nbd_job_ui_status($j);
  $key = $st['key'] ?? '';
  if ($key === 'paused') {
    return ['seconds' => null, 'label' => 'paused', 'rate_pct_per_min' => null];
  }
  if ($key !== 'running') {
    return $empty;
  }
  $pct = nbd_job_progress_pct($j);
  if ($pct === null) {
    return $empty;
  }
  if ($pct >= 99.9) {
    return ['seconds' => 0, 'label' => 'finishing…', 'rate_pct_per_min' => null];
  }
  // Still at ~0% — wait for real movement (multi-TiB often sits at 0.00 for a while).
  if ($pct < 0.05) {
    return $empty;
  }
  $samples = nbd_job_progress_samples($j);
  $now = time();
  // Anchor current pct as a sample so one hist point + live pct can estimate.
  if ($pct > 0) {
    $samples[] = [$now, $pct];
  }
  if (count($samples) < 2) {
    return $empty;
  }
  $window = [];
  foreach ($samples as $s) {
    if ($s[0] >= $now - 900) {
      $window[] = $s;
    }
  }
  if (count($window) < 2) {
    $window = $samples;
  }
  $first = $window[0];
  $last = $window[count($window) - 1];
  if ($pct > $last[1]) {
    $last = [$now, $pct];
  }
  $dt = $last[0] - $first[0];
  $dp = $last[1] - $first[1];
  // Allow smaller dp once we have a minute of wall time (large disks move slowly).
  if ($dt < 30 || $dp < 0.05) {
    return $empty;
  }
  $pct_per_sec = $dp / $dt;
  $remain = 100.0 - $pct;
  if ($pct_per_sec <= 0) {
    return $empty;
  }
  $sec = (int)round($remain / $pct_per_sec);
  if ($sec < 0) {
    $sec = 0;
  }
  if ($sec > 14 * 86400) {
    $sec = 14 * 86400;
  }
  return [
    'seconds' => $sec,
    'label' => '~' . nbd_format_duration($sec),
    'rate_pct_per_min' => round($pct_per_sec * 60, 3),
  ];
}

/** Format seconds as 45s / 12m / 2h 15m / 1d 3h */
function nbd_format_duration($seconds) {
  $s = max(0, (int)$seconds);
  if ($s < 60) {
    return $s . 's';
  }
  if ($s < 3600) {
    $m = (int)round($s / 60);
    return $m . 'm';
  }
  if ($s < 86400) {
    $h = intdiv($s, 3600);
    $m = (int)round(($s % 3600) / 60);
    return $m > 0 ? ($h . 'h ' . $m . 'm') : ($h . 'h');
  }
  $d = intdiv($s, 86400);
  $h = intdiv($s % 86400, 3600);
  return $h > 0 ? ($d . 'd ' . $h . 'h') : ($d . 'd');
}

/** Elapsed seconds for a job (running clock), or null. */
function nbd_job_elapsed_seconds(array $j) {
  $raw = (string)($j['started_run'] ?? $j['started'] ?? '');
  $ts = nbd_parse_when($raw);
  if (!$ts) {
    return null;
  }
  $k = nbd_job_ui_status($j)['key'] ?? '';
  if ($k === 'paused') {
    // Freeze display at pause time if recorded
    $pt = nbd_parse_when((string)($j['paused_at'] ?? ''));
    if ($pt && $pt >= $ts) {
      return max(0, $pt - $ts);
    }
  }
  if (!in_array($k, ['running', 'paused'], true)) {
    $fin = nbd_parse_when((string)($j['finished_at'] ?? ''));
    if ($fin && $fin >= $ts) {
      return max(0, $fin - $ts);
    }
  }
  return max(0, time() - $ts);
}

/**
 * Sample net RX (to peer) + output disk write rates for a live job.
 * @return array{net_bps:?int,disk_bps:?int,net_h:string,disk_h:string}
 */
function nbd_job_io_rates(array $j) {
  $empty = ['net_bps' => null, 'disk_bps' => null, 'net_h' => '', 'disk_h' => ''];
  $id = (string)($j['id'] ?? '');
  if ($id === '') {
    return $empty;
  }
  $k = nbd_job_ui_status($j)['key'] ?? '';
  if ($k !== 'running') {
    return $empty;
  }
  $now = microtime(true);
  $net_bytes = null;
  $url = (string)($j['url'] ?? '');
  if (preg_match('#^nbd://([^/:]+)#', $url, $m)) {
    $peer = $m[1];
    $route = (string)@shell_exec('ip -o route get ' . escapeshellarg($peer) . ' 2>/dev/null');
    if (preg_match('/\bdev\s+(\S+)/', $route, $mm)) {
      $iface = $mm[1];
      $rxf = '/sys/class/net/' . $iface . '/statistics/rx_bytes';
      if (is_file($rxf)) {
        $net_bytes = (int)@file_get_contents($rxf);
      }
    }
  }
  $disk_bytes = null;
  $out = (string)($j['output'] ?? '');
  if ($out !== '' && is_file($out)) {
    $disk_bytes = @filesize($out);
    if ($disk_bytes === false) {
      $disk_bytes = null;
    }
  }
  $rf = NBDEXPORT_RUN . '/' . $id . '.rates.json';
  $prev = [];
  if (is_file($rf)) {
    $prev = @json_decode((string)@file_get_contents($rf), true) ?: [];
  }
  $sample = [
    't' => $now,
    'net' => $net_bytes,
    'disk' => $disk_bytes,
  ];
  @file_put_contents($rf, json_encode($sample) . "\n");
  $net_bps = null;
  $disk_bps = null;
  if (!empty($prev['t']) && ($now - (float)$prev['t']) >= 0.8) {
    $dt = $now - (float)$prev['t'];
    if ($net_bytes !== null && isset($prev['net']) && $prev['net'] !== null) {
      $net_bps = (int)max(0, round(($net_bytes - (int)$prev['net']) / $dt));
    }
    if ($disk_bytes !== null && isset($prev['disk']) && $prev['disk'] !== null) {
      $disk_bps = (int)max(0, round(($disk_bytes - (int)$prev['disk']) / $dt));
    }
  }
  return [
    'net_bps' => $net_bps,
    'disk_bps' => $disk_bps,
    'net_h' => nbd_format_net_rate($net_bps),
    'disk_h' => nbd_format_disk_rate($disk_bps),
  ];
}

/**
 * Log tail for UI: keep history, but collapse runs of bare (N/100%) spam.
 */
function nbd_log_tail_display($path, $lines = 12) {
  $raw = nbd_log_tail($path, max(40, $lines * 4));
  if ($raw === '') {
    return '';
  }
  // Normalize CR progress into lines
  $raw = str_replace("\r", "\n", $raw);
  $out = [];
  $last_pct_line = null;
  foreach (preg_split('/\n/', $raw) as $line) {
    $t = trim($line);
    if ($t === '') {
      continue;
    }
    if (preg_match('/^\((\d+(?:\.\d+)?)\/100%\)$/', $t) || preg_match('/^\s*\((\d+(?:\.\d+)?)\/100%\)\s*$/', $t)) {
      $last_pct_line = $t;
      continue;
    }
    // Old spam: many (n%) on one line
    if (substr_count($t, '/100%)') >= 3 && strpos($t, 'progress') === false) {
      if (preg_match_all('/\((\d+(?:\.\d+)?)\/100%\)/', $t, $mm) && !empty($mm[0])) {
        $last_pct_line = end($mm[0]);
      }
      continue;
    }
    $out[] = $line;
  }
  if ($last_pct_line !== null) {
    $out[] = $last_pct_line;
  }
  if (count($out) > $lines) {
    $out = array_slice($out, -$lines);
  }
  return implode("\n", $out);
}

function nbd_plugin_version() {
  $plg = NBDEXPORT_ROOT . '/nbd.plg';
  if (is_file($plg)) {
    $t = @file_get_contents($plg);
    if (is_string($t) && preg_match('/ENTITY version "([^"]+)"/', $t, $m)) {
      return $m[1];
    }
  }
  // Dev tree
  $dev = dirname(__DIR__) . '/nbd.plg';
  if (is_file($dev)) {
    $t = @file_get_contents($dev);
    if (is_string($t) && preg_match('/ENTITY version "([^"]+)"/', $t, $m)) {
      return $m[1];
    }
  }
  return 'dev';
}

function nbd_detect_tools() {
  $find = function ($names) {
    foreach ($names as $n) {
      $p = trim((string)@shell_exec('command -v ' . escapeshellarg($n) . ' 2>/dev/null'));
      if ($p !== '' && is_executable($p)) {
        return $p;
      }
      foreach (['/usr/bin/' . $n, '/usr/local/bin/' . $n, '/bin/' . $n] as $c) {
        if (is_executable($c)) {
          return $c;
        }
      }
    }
    return '';
  };
  return [
    'qemu_nbd' => $find(['qemu-nbd']),
    'qemu_img' => $find(['qemu-img']),
  ];
}

function nbd_is_private_ipv4($ip) {
  if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    return false;
  }
  $long = ip2long($ip);
  if ($long === false) {
    return false;
  }
  // 10/8, 172.16/12, 192.168/16, 127/8, link-local 169.254/16
  $ranges = [
    ['10.0.0.0', '10.255.255.255'],
    ['172.16.0.0', '172.31.255.255'],
    ['192.168.0.0', '192.168.255.255'],
    ['127.0.0.0', '127.255.255.255'],
    ['169.254.0.0', '169.254.255.255'],
  ];
  foreach ($ranges as $r) {
    if ($long >= ip2long($r[0]) && $long <= ip2long($r[1])) {
      return true;
    }
  }
  return false;
}

/**
 * List bind candidates: thunderbolt* first, then other private IPv4s.
 *
 * @return array[] each: ip, iface, label, preferred (bool)
 */
function nbd_list_bind_ips() {
  $rows = [];
  $seen = [];
  $out = [];
  @exec('ip -4 -o addr show 2>/dev/null', $out);
  foreach ($out as $line) {
    // 2: eth0    inet 10.0.0.10/24 ...
    if (!preg_match('/^\d+:\s+(\S+)\s+inet\s+(\d+\.\d+\.\d+\.\d+)/', $line, $m)) {
      continue;
    }
    $if = $m[1];
    // strip @if suffixes
    $if = preg_replace('/@.*$/', '', $if);
    $ip = $m[2];
    // Skip loopback and link-local-only noise
    if ($if === 'lo' || strpos($ip, '127.') === 0) {
      continue;
    }
    if (isset($seen[$ip])) {
      continue;
    }
    $seen[$ip] = true;
    $is_tb = (bool)preg_match('/^thunderbolt\d+$/', $if);
    $is_virt = (bool)preg_match('/^(docker|br-|veth|virbr|wg|tun|tap|vnet)/', $if)
      || $if === 'docker0';
    $priv = nbd_is_private_ipv4($ip);
    // Rank: Thunderbolt private (0), other non-virt private (1), virt private (2), public (3)
    $rank = 3;
    if ($is_tb && $priv) {
      $rank = 0;
    } elseif ($priv && !$is_virt) {
      $rank = 1;
    } elseif ($priv) {
      $rank = 2;
    }
    $rows[] = [
      'ip' => $ip,
      'iface' => $if,
      'preferred' => $rank === 0,
      'private' => $priv,
      'rank' => $rank,
      'label' => $ip . ' (' . $if . ($is_tb ? ', Thunderbolt' : '') . ')',
    ];
  }
  usort($rows, function ($a, $b) {
    if ($a['rank'] !== $b['rank']) {
      return $a['rank'] - $b['rank'];
    }
    return strcmp($a['iface'], $b['iface']);
  });
  return $rows;
}

function nbd_thunderboltnet_present() {
  return is_dir('/usr/local/emhttp/plugins/ThunderboltNet')
    || is_dir('/boot/config/plugins/ThunderboltNet');
}

function nbd_fabricrouting_present() {
  return is_dir('/usr/local/emhttp/plugins/FabricRouting')
    || is_dir('/boot/config/plugins/FabricRouting')
    || is_dir('/usr/local/emhttp/plugins/UnraidFRR')
    || is_dir('/boot/config/plugins/UnraidFRR');
}

/**
 * List exportable block devices (disks + partitions optional).
 */
function nbd_list_disks() {
  $json = @shell_exec('lsblk -J -b -o NAME,SIZE,TYPE,MODEL,SERIAL,TRAN,MOUNTPOINT,PKNAME,RM,RO 2>/dev/null');
  $disks = [];
  if (!is_string($json) || $json === '') {
    return $disks;
  }
  $data = @json_decode($json, true);
  if (!is_array($data) || empty($data['blockdevices'])) {
    return $disks;
  }

  $array_devs = nbd_unraid_array_devices();

  $walk = function ($nodes, $parent = null) use (&$walk, &$disks, $array_devs) {
    if (!is_array($nodes)) {
      return;
    }
    foreach ($nodes as $n) {
      if (!is_array($n)) {
        continue;
      }
      $name = $n['name'] ?? '';
      $type = $n['type'] ?? '';
      $path = '/dev/' . $name;
      if ($name === '' || in_array($type, ['loop', 'rom'], true)) {
        if (!empty($n['children'])) {
          $walk($n['children'], $parent);
        }
        continue;
      }
      // Skip zram
      if (strpos($name, 'zram') === 0) {
        continue;
      }

      $size = isset($n['size']) ? (int)$n['size'] : 0;
      $mount = $n['mountpoint'] ?? null;
      if ($mount === null || $mount === false || $mount === '') {
        $mount = '';
      }
      $is_disk = ($type === 'disk');
      $is_part = ($type === 'part');
      if (!$is_disk && !$is_part) {
        if (!empty($n['children'])) {
          $walk($n['children'], $is_disk ? $name : $parent);
        }
        continue;
      }

      $flags = [];
      if (isset($array_devs[$name]) || isset($array_devs[$path])) {
        $flags[] = 'array';
      }
      // md* typically array
      if (preg_match('/^md\d+/', $name)) {
        $flags[] = 'array';
      }
      if ($mount !== '') {
        $flags[] = 'mounted';
      }
      if (!empty($n['ro']) && (string)$n['ro'] === '1') {
        $flags[] = 'ro-hw';
      }

      $model = trim((string)($n['model'] ?? ''));
      $serial = trim((string)($n['serial'] ?? ''));
      $tran = trim((string)($n['tran'] ?? ''));

      $disks[] = [
        'name' => $name,
        'path' => $path,
        'type' => $type,
        'size' => $size,
        'size_h' => nbd_format_bytes($size),
        'model' => $model,
        'serial' => $serial,
        'tran' => $tran,
        'mountpoint' => $mount,
        'flags' => array_values(array_unique($flags)),
        'warn' => in_array('array', $flags, true) || in_array('mounted', $flags, true),
        'label' => $path
          . ($model !== '' ? ' · ' . $model : '')
          . ($serial !== '' ? ' · ' . $serial : '')
          . ' · ' . nbd_format_bytes($size)
          . ($type === 'part' ? ' (partition)' : ''),
      ];

      if (!empty($n['children'])) {
        $walk($n['children'], $name);
      }
    }
  };
  $walk($data['blockdevices']);
  return $disks;
}

function nbd_unraid_array_devices() {
  $map = [];
  $ini = '/var/local/emhttp/disks.ini';
  if (!is_file($ini)) {
    return $map;
  }
  $disks = @parse_ini_file($ini, true);
  if (!is_array($disks)) {
    return $map;
  }
  foreach ($disks as $id => $d) {
    if (!is_array($d)) {
      continue;
    }
    $dev = $d['device'] ?? $d['id'] ?? '';
    if ($dev === '') {
      continue;
    }
    $dev = preg_replace('#^/dev/#', '', $dev);
    $map[$dev] = $id;
    $map['/dev/' . $dev] = $id;
  }
  return $map;
}

/**
 * Unraid-style size (decimal SI: KB/MB/GB/TB), matching stock my_scale(kilo=1000).
 */
function nbd_format_bytes($n) {
  $n = (float)$n;
  if ($n <= 0) {
    return '0 B';
  }
  $u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
  $i = 0;
  while ($n >= 1000 && $i < count($u) - 1) {
    $n /= 1000;
    $i++;
  }
  $decimals = ($i === 0 || $n >= 100) ? 0 : ($n >= 10 ? 1 : 2);
  $fmt = number_format($n, $decimals, '.', '');
  $fmt = rtrim(rtrim($fmt, '0'), '.');
  if ($fmt === '') {
    $fmt = '0';
  }
  return $fmt . ' ' . $u[$i];
}

/** Disk throughput: MB/s (decimal megabytes/sec). */
function nbd_format_disk_rate($bps) {
  if ($bps === null) {
    return '';
  }
  $mb = ((float)$bps) / 1000000.0;
  if ($mb < 0.1) {
    return number_format($mb, 2, '.', '') . ' MB/s';
  }
  if ($mb < 10) {
    return number_format($mb, 1, '.', '') . ' MB/s';
  }
  return number_format($mb, 0, '.', '') . ' MB/s';
}

/** Network throughput: Mb/s, or Gb/s when ≥ 1000 Mb/s (Unraid-style). */
function nbd_format_net_rate($bps) {
  if ($bps === null) {
    return '';
  }
  $mbits = ((float)$bps) * 8.0 / 1000000.0;
  if ($mbits >= 1000.0) {
    $g = $mbits / 1000.0;
    return number_format($g, ($g >= 10 ? 1 : 2), '.', '') . ' Gb/s';
  }
  if ($mbits < 0.1) {
    return number_format($mbits, 2, '.', '') . ' Mb/s';
  }
  if ($mbits < 10) {
    return number_format($mbits, 1, '.', '') . ' Mb/s';
  }
  return number_format($mbits, 0, '.', '') . ' Mb/s';
}

function nbd_exports_state() {
  nbd_ensure_runtime_dirs();
  $list = [];
  foreach (glob(NBDEXPORT_RUN . '/*.json') ?: [] as $f) {
    $raw = @file_get_contents($f);
    $j = is_string($raw) ? @json_decode($raw, true) : null;
    if (!is_array($j) || empty($j['id'])) {
      continue;
    }
    // Pull / external convert state must not appear as Host exports
    $id = (string)$j['id'];
    if (strpos($id, 'job-') === 0 || strpos($id, 'ext-') === 0) {
      continue;
    }
    if (empty($j['device']) && empty($j['port']) && empty($j['bind'])) {
      continue;
    }
    $pid = isset($j['pid']) ? (int)$j['pid'] : 0;
    $alive = $pid > 0 && @file_exists('/proc/' . $pid);
    $j['alive'] = $alive;
    $j['listening'] = false;
    if (!empty($j['bind']) && !empty($j['port'])) {
      $j['listening'] = nbd_port_listening($j['bind'], (int)$j['port']);
    }
    if (!$alive && empty($j['listening'])) {
      // stale state file
      $j['stale'] = true;
    }
    $list[] = $j;
  }
  usort($list, function ($a, $b) {
    return strcmp($a['id'] ?? '', $b['id'] ?? '');
  });
  return $list;
}

function nbd_port_listening($bind, $port) {
  $port = (int)$port;
  if ($port <= 0) {
    return false;
  }
  $out = [];
  @exec('ss -lntp 2>/dev/null | grep -F ' . escapeshellarg(':' . $port) . ' || true', $out);
  if (!$out) {
    return false;
  }
  $blob = implode("\n", $out);
  if ($bind === '0.0.0.0' || $bind === '*') {
    return true;
  }
  return strpos($blob, $bind . ':' . $port) !== false || strpos($blob, '*:' . $port) !== false;
}

function nbd_new_export_id() {
  return 'exp-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
}

/**
 * Risk assessment for a block device path.
 * "array" flag = Unraid inventory (array, parity, cache, pools via disks.ini) or md*.
 * "flash" flag = device that currently backs /boot (USB flash or disk boot).
 *
 * @return array{
 *   path:string,name:string,array:bool,mounted:bool,flash:bool,risky:bool,
 *   flags:string[],summary:string
 * }
 */
function nbd_device_risk($device) {
  $device = trim((string)$device);
  $name = preg_replace('#^/dev/#', '', $device);
  $flags = [];
  $array = false;
  $mounted = false;
  $flash = false;

  // disks.ini: array, parity, cache, pools — all Unraid-assigned storage
  $array_devs = nbd_unraid_array_devices();
  if (isset($array_devs[$name]) || isset($array_devs[$device]) || preg_match('/^md\d+/', $name)) {
    $array = true;
    $flags[] = 'array';
  }

  // Any mount under this device or partitions
  $mp = trim((string)@shell_exec('lsblk -n -o MOUNTPOINT ' . escapeshellarg($device) . ' 2>/dev/null'));
  if ($mp !== '') {
    foreach (preg_split('/\s+/', $mp) as $p) {
      if ($p !== '' && $p !== '-') {
        $mounted = true;
        $flags[] = 'mounted';
        break;
      }
    }
  }
  // Children mounts
  if (!$mounted) {
    $kids = trim((string)@shell_exec('lsblk -n -o MOUNTPOINT -r ' . escapeshellarg($device) . ' 2>/dev/null'));
    if ($kids !== '') {
      foreach (preg_split('/\s+/', $kids) as $p) {
        if ($p !== '' && $p !== '-') {
          $mounted = true;
          $flags[] = 'mounted';
          break;
        }
      }
    }
  }

  // Unraid boot device: whatever currently backs /boot (USB flash or disk/partition)
  $boot_src = trim((string)@shell_exec('findmnt -n -o SOURCE /boot 2>/dev/null'));
  if ($boot_src !== '') {
    $boot_base = preg_replace('#p?\d+$#', '', preg_replace('#^/dev/#', '', $boot_src));
    $dev_base = preg_replace('#p?\d+$#', '', $name);
    if ($boot_base !== '' && ($name === $boot_base || $dev_base === $boot_base || strpos($boot_src, $device) === 0)) {
      $flash = true;
      $flags[] = 'flash';
    }
  }

  $flags = array_values(array_unique($flags));
  $risky = $array || $mounted || $flash;
  $summary = $risky ? implode(', ', $flags) : 'ok';
  return [
    'path' => $device,
    'name' => $name,
    'array' => $array,
    'mounted' => $mounted,
    'flash' => $flash,
    'risky' => $risky,
    'flags' => $flags,
    'summary' => $summary,
  ];
}

/**
 * Whether destructive mode is enabled (writable / array / mounted exports).
 */
function nbd_destructive_mode_on(array $cfg = null) {
  if ($cfg === null) {
    $cfg = nbd_load_cfg();
  }
  return (($cfg['destructive_mode'] ?? 'no') === 'yes');
}

/**
 * Soft-inject opt-in Unassigned Devices overlay hook into Unraid HeadInlineJS.
 * Call only when ud_status_overlay=yes. Marker comments make uninstall reliable.
 * Fixed absolute layout paths only — never walks /mnt or user shares.
 */
function nbd_ud_overlay_inject() {
  $marker = 'NBDExport-inject';
  $legacy = 'NBDExport UD overlay';
  $line = '<?php @include \'/usr/local/emhttp/plugins/NBDExport/include/nbd-ud-head.php\'; /* ' . $marker . ' */ ?>';
  $candidates = [
    '/usr/local/emhttp/webGui/include/DefaultPageLayout/HeadInlineJS.php',
    '/usr/local/emhttp/plugins/dynamix/include/DefaultPageLayout/HeadInlineJS.php',
  ];
  $backup_dir = NBDEXPORT_CFG_DIR . '/stock-backup';
  if (!is_dir($backup_dir)) {
    @mkdir($backup_dir, 0755, true);
  }
  $ok = false;
  foreach ($candidates as $path) {
    if (!is_file($path) || !is_writable($path)) {
      continue;
    }
    $base = basename($path);
    $stock = $backup_dir . '/' . $base . '.stock';
    if (!is_file($stock)) {
      @copy($path, $stock);
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw)) {
      continue;
    }
    if (strpos($raw, $marker) !== false || strpos($raw, $legacy) !== false) {
      $ok = true;
      continue;
    }
    $raw = rtrim($raw) . "\n" . $line . "\n";
    if (@file_put_contents($path, $raw) !== false) {
      $ok = true;
    }
  }
  return $ok;
}

/** Remove UD overlay inject markers from Unraid layout files. */
function nbd_ud_overlay_uninject() {
  $markers = ['NBDExport-inject', 'NBDExport UD overlay'];
  $candidates = [
    '/usr/local/emhttp/webGui/include/DefaultPageLayout/HeadInlineJS.php',
    '/usr/local/emhttp/plugins/dynamix/include/DefaultPageLayout/HeadInlineJS.php',
    '/usr/local/emhttp/webGui/include/HeadInclude.php',
    '/usr/local/emhttp/plugins/dynamix/include/HeadInclude.php',
  ];
  foreach ($candidates as $path) {
    if (!is_file($path) || !is_writable($path)) {
      continue;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw)) {
      continue;
    }
    $hit = false;
    foreach ($markers as $marker) {
      if (strpos($raw, $marker) !== false) {
        $hit = true;
        break;
      }
    }
    if (!$hit) {
      continue;
    }
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $out = [];
    foreach ($lines as $ln) {
      $drop = false;
      foreach ($markers as $marker) {
        if (strpos($ln, $marker) !== false) {
          $drop = true;
          break;
        }
      }
      if (!$drop) {
        $out[] = $ln;
      }
    }
    @file_put_contents($path, implode("\n", $out) . (substr($raw, -1) === "\n" ? "\n" : ''));
  }
  return true;
}

/**
 * Start read-only (or writable) qemu-nbd export.
 *
 * Safety (Unassigned Devices–style):
 * - Default: read-only only; unassigned, unmounted, non-boot disks.
 * - destructive_mode=yes required for writable exports OR array/cache/pool/mounted/boot devices.
 * - confirm must be true for any risky or RW start (UI double-check; server-enforced).
 *
 * @return array{ok:bool,error?:string,id?:string}
 */
function nbd_export_start($device, $bind, $port, $read_only = true, $label = '', $shared = 2, $confirm = false) {
  nbd_ensure_runtime_dirs();
  $cfg = nbd_load_cfg();
  if (($cfg['enabled'] ?? 'yes') !== 'yes') {
    return ['ok' => false, 'error' => 'Plugin is disabled (Enable NBD Export = No).'];
  }
  $tools = nbd_detect_tools();
  if ($tools['qemu_nbd'] === '') {
    return ['ok' => false, 'error' => 'qemu-nbd not found. Install/enable the Unraid VM stack or install qemu-nbd.'];
  }
  $device = trim((string)$device);
  $bind = trim((string)$bind);
  $port = (int)$port;
  $read_only = (bool)$read_only;
  $confirm = (bool)$confirm;
  // Allow common block paths; reject traversal and non-/dev
  if ($device === '' || strpos($device, '..') !== false
      || !preg_match('#^/dev/[A-Za-z0-9][A-Za-z0-9._+-]*$#', $device)) {
    return ['ok' => false, 'error' => 'Invalid device path.'];
  }
  if (!file_exists($device)) {
    return ['ok' => false, 'error' => 'Device not found: ' . $device];
  }
  if ($bind === '') {
    return ['ok' => false, 'error' => 'Bind IP is required (prefer Thunderbolt or private LAN).'];
  }
  if ($bind === '0.0.0.0' && ($cfg['allow_bind_all'] ?? 'no') !== 'yes') {
    return ['ok' => false, 'error' => 'Binding 0.0.0.0 is disabled (allow_bind_all=no). Pick a specific IP.'];
  }
  if ($port < 1024 || $port > 65535) {
    return ['ok' => false, 'error' => 'Port must be 1024–65535.'];
  }

  $risk = nbd_device_risk($device);
  $destructive = nbd_destructive_mode_on($cfg);

  // Writable always requires destructive mode
  if (!$read_only && !$destructive) {
    return [
      'ok' => false,
      'error' => 'Writable export blocked. Enable Destructive mode under Settings (like Unassigned Devices), keep Read-only=Yes, or both.',
    ];
  }
  // Array / mounted / flash require destructive mode even when read-only
  if ($risk['risky'] && !$destructive) {
    return [
      'ok' => false,
      'error' => 'Device is ' . $risk['summary'] . '. Enable Destructive mode under Settings to allow array/cache/pool, mounted, or boot-device hosts (read-only still recommended).',
    ];
  }
  // Never allow writable Unraid boot device (USB flash or disk that holds /boot)
  if (!$read_only && !empty($risk['flash'])) {
    return ['ok' => false, 'error' => 'Refusing writable export of Unraid boot device (/boot).'];
  }
  // Risky or RW requires explicit confirm from UI
  if ((!$read_only || $risk['risky']) && !$confirm) {
    return [
      'ok' => false,
      'error' => 'Confirmation required for this export (writable and/or ' . ($risk['summary'] !== 'ok' ? $risk['summary'] : 'high risk') . '). Confirm in the UI and try again.',
    ];
  }

  // Port conflict
  foreach (nbd_exports_state() as $e) {
    if (!empty($e['alive']) && (int)($e['port'] ?? 0) === $port && ($e['bind'] ?? '') === $bind) {
      return ['ok' => false, 'error' => 'Already exporting on ' . $bind . ':' . $port];
    }
  }

  $id = nbd_new_export_id();
  $pidfile = NBDEXPORT_RUN . '/' . $id . '.pid';
  $statefile = NBDEXPORT_RUN . '/' . $id . '.json';
  $logfile = NBDEXPORT_LOG . '/' . $id . '.log';

  $cmd = [
    $tools['qemu_nbd'],
    '--persistent',
    '--shared=' . max(1, (int)$shared),
    '--bind=' . $bind,
    '--port=' . $port,
    '--format=raw',
  ];
  if ($read_only) {
    $cmd[] = '--read-only';
  }
  $cmd[] = $device;

  $cmd_s = implode(' ', array_map('escapeshellarg', $cmd));
  $full = 'setsid nohup ' . $cmd_s . ' >>' . escapeshellarg($logfile) . ' 2>&1 & echo $! >' . escapeshellarg($pidfile);
  @file_put_contents($logfile, date('c') . " start: $cmd_s\n", FILE_APPEND);
  exec($full);

  usleep(300000);
  $pid = (int)@file_get_contents($pidfile);
  $alive = $pid > 0 && @file_exists('/proc/' . $pid);
  if (!$alive) {
    // maybe qemu-nbd forked and wrote nothing useful
    $listening = nbd_port_listening($bind, $port);
    if (!$listening) {
      return ['ok' => false, 'error' => 'qemu-nbd failed to start — see ' . $logfile];
    }
  }

  $state = [
    'id' => $id,
    'device' => $device,
    'bind' => $bind,
    'port' => $port,
    'read_only' => (bool)$read_only,
    'label' => $label,
    'pid' => $pid,
    'started' => date('c'),
    'url' => 'nbd://' . $bind . ':' . $port,
    'shared' => (int)$shared,
  ];
  @file_put_contents($statefile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
  nbd_write_companion_marker();
  nbd_beacon_ensure();
  return ['ok' => true, 'id' => $id, 'url' => $state['url']];
}

function nbd_export_stop($id) {
  nbd_ensure_runtime_dirs();
  $id = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$id);
  if ($id === '') {
    return ['ok' => false, 'error' => 'Missing export id'];
  }
  $pidfile = NBDEXPORT_RUN . '/' . $id . '.pid';
  $statefile = NBDEXPORT_RUN . '/' . $id . '.json';
  $pid = is_file($pidfile) ? (int)@file_get_contents($pidfile) : 0;
  if ($pid > 0 && @file_exists('/proc/' . $pid)) {
    @posix_kill($pid, 15);
    usleep(200000);
    if (@file_exists('/proc/' . $pid)) {
      @posix_kill($pid, 9);
    }
  }
  // also kill any qemu-nbd matching this export's bind+port (not all binds on same port)
  if (is_file($statefile)) {
    $j = @json_decode((string)@file_get_contents($statefile), true);
    if (is_array($j) && !empty($j['port'])) {
      $p = (int)$j['port'];
      $b = trim((string)($j['bind'] ?? ''));
      if ($b !== '') {
        // cmd order: --bind=IP --port=N
        @exec('pkill -f ' . escapeshellarg('qemu-nbd.*--bind=' . $b . '.*--port=' . $p) . ' 2>/dev/null || true');
      } else {
        @exec('pkill -f ' . escapeshellarg('qemu-nbd.*--port=' . $p) . ' 2>/dev/null || true');
      }
    }
  }
  @unlink($pidfile);
  @unlink($statefile);
  // Drop beacon when nothing left listening
  $still = false;
  foreach (nbd_exports_state() as $e) {
    if (!empty($e['alive']) || !empty($e['listening'])) {
      $still = true;
      break;
    }
  }
  if (!$still) {
    nbd_beacon_stop();
  } else {
    nbd_beacon_ensure();
  }
  return ['ok' => true];
}

function nbd_stop_all_exports() {
  foreach (nbd_exports_state() as $e) {
    if (!empty($e['id'])) {
      nbd_export_stop($e['id']);
    }
  }
  nbd_beacon_stop();
  // sweep leftover pid/json
  foreach (glob(NBDEXPORT_RUN . '/*') ?: [] as $f) {
    @unlink($f);
  }
}

/**
 * Emergency / policy: stop every managed Host that is writable (not read-only).
 * @return array{ok:bool,stopped:string[],count:int}
 */
function nbd_stop_writable_exports() {
  $stopped = [];
  foreach (nbd_exports_state() as $e) {
    if (empty($e['id'])) {
      continue;
    }
    // Default unknown to writable for safety (legacy state without flag)
    $ro = array_key_exists('read_only', $e) ? !empty($e['read_only']) : false;
    if ($ro) {
      continue;
    }
    nbd_export_stop($e['id']);
    $stopped[] = (string)$e['id'];
  }
  return ['ok' => true, 'stopped' => $stopped, 'count' => count($stopped)];
}

/**
 * Stop live Hosts that would be refused if started under current cfg
 * (e.g. Destructive just turned Off while a writable or array host is still up).
 *
 * @return array{ok:bool,stopped:string[],reasons:array<string,string>,count:int}
 */
function nbd_stop_exports_disallowed_by_cfg(array $cfg = null) {
  if ($cfg === null) {
    $cfg = nbd_load_cfg();
  }
  $destructive = nbd_destructive_mode_on($cfg);
  $stopped = [];
  $reasons = [];
  foreach (nbd_exports_state() as $e) {
    if (empty($e['id'])) {
      continue;
    }
    $id = (string)$e['id'];
    $ro = array_key_exists('read_only', $e) ? !empty($e['read_only']) : false;
    $dev = (string)($e['device'] ?? '');
    $why = '';
    if (!$ro) {
      if (!$destructive) {
        $why = 'writable (Destructive mode Off)';
      }
    } elseif ($dev !== '') {
      $risk = nbd_device_risk($dev);
      if (!empty($risk['risky']) && !$destructive) {
        $why = 'in-use/critical source without Destructive mode (' . ($risk['summary'] ?? 'risky') . ')';
      }
    }
    if ($why === '') {
      continue;
    }
    nbd_export_stop($id);
    $stopped[] = $id;
    $reasons[$id] = $why;
  }
  return [
    'ok' => true,
    'stopped' => $stopped,
    'reasons' => $reasons,
    'count' => count($stopped),
  ];
}

/** Count live writable Host exports (for UI emergency control). */
function nbd_count_writable_exports() {
  $n = 0;
  foreach (nbd_exports_state() as $e) {
    if (empty($e['alive']) && empty($e['listening'])) {
      continue;
    }
    $ro = array_key_exists('read_only', $e) ? !empty($e['read_only']) : false;
    if (!$ro) {
      $n++;
    }
  }
  return $n;
}

function nbd_jobs_state() {
  nbd_ensure_runtime_dirs();
  $list = [];
  $finished_now = false;
  foreach (glob(NBDEXPORT_RUN . '/job-*.json') ?: [] as $f) {
    $raw = @file_get_contents($f);
    $j = is_string($raw) ? @json_decode($raw, true) : null;
    if (!is_array($j) || empty($j['id'])) {
      continue;
    }
    $status = (string)($j['status'] ?? '');
    $pid = isset($j['pid']) ? (int)$j['pid'] : 0;
    $wrapper_alive = $pid > 0 && @file_exists('/proc/' . $pid);
    $orphan_pid = 0;
    if (!$wrapper_alive && empty($j['finished']) && $status !== 'queued') {
      $orphan_pid = nbd_find_orphan_qemu_img($j);
    }
    $paused_proc = ($status === 'paused');
    if ($wrapper_alive && nbd_pid_is_stopped($pid)) {
      $paused_proc = true;
    }
    if ($orphan_pid > 0 && nbd_pid_is_stopped($orphan_pid)) {
      $paused_proc = true;
    }
    if ($paused_proc && empty($j['finished'])) {
      $j['alive'] = true; // still occupies a slot
      $j['status'] = 'paused';
      $j['orphaned'] = ($orphan_pid > 0 && !$wrapper_alive);
      if ($orphan_pid > 0) {
        $j['orphan_pid'] = $orphan_pid;
      }
    } elseif ($wrapper_alive) {
      $j['alive'] = true;
      $j['orphaned'] = false;
      $j['status'] = 'running';
    } elseif ($orphan_pid > 0) {
      // Wrapper died (Stop bug / setsid) but convert still running — surface as Running
      $j['alive'] = true;
      $j['orphaned'] = true;
      $j['orphan_pid'] = $orphan_pid;
      $j['status'] = 'running';
    } elseif ($status === 'queued') {
      $j['alive'] = false;
      $j['status'] = 'queued';
    } else {
      $j['alive'] = false;
      if (empty($j['finished'])) {
        $log = $j['log'] ?? '';
        $tail = ($log && is_file($log)) ? (string)@file_get_contents($log) : '';
        $j['finished'] = true;
        if (strpos($tail, 'NBD_JOB_OK') !== false) {
          $j['ok'] = true;
        } else {
          $j['ok'] = false;
          if (strpos($tail, 'NBD_JOB_FAIL') === false && $tail !== '') {
            @file_put_contents($log, "\nNBD_JOB_FAIL process_exited\n", FILE_APPEND);
            $tail .= "\nNBD_JOB_FAIL process_exited\n";
          }
          $fi = nbd_job_fail_info($j + ['log' => $log]);
          $j['fail_code'] = $fi['code'];
          $j['fail_reason'] = $fi['reason'];
          $j['fail_known'] = !empty($fi['known']);
        }
        $j['finished_at'] = date('c');
        $j['status'] = !empty($j['ok']) ? 'done' : 'failed';
        $persist = $j;
        unset($persist['alive'], $persist['output_size'], $persist['output_size_h'], $persist['orphaned'], $persist['orphan_pid']);
        @file_put_contents($f, json_encode($persist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $finished_now = true;
      }
    }
    if (!empty($j['output']) && is_file($j['output'])) {
      $j['output_size'] = filesize($j['output']);
      $j['output_size_h'] = nbd_format_bytes($j['output_size']);
    } elseif (!empty($j['output'])) {
      // Deleted outfile while orphan still held the inode
      $j['output_size_h'] = !empty($j['orphaned']) ? '(deleted, still writing)' : '—';
    }
    if (($j['status'] ?? '') === 'failed' || (!empty($j['finished']) && empty($j['ok']))) {
      if (empty($j['fail_reason'])) {
        $fi = nbd_job_fail_info($j);
        $j['fail_code'] = $fi['code'];
        $j['fail_reason'] = $fi['reason'];
        $j['fail_known'] = !empty($fi['known']);
      } elseif (!array_key_exists('fail_known', $j)) {
        $j['fail_known'] = nbd_fail_reason_is_known((string)($j['fail_code'] ?? ''), (string)$j['fail_reason']);
      }
    }
    $list[] = $j;
  }
  usort($list, function ($a, $b) {
    return strcmp($b['started'] ?? '', $a['started'] ?? '');
  });
  if ($finished_now) {
    nbd_pull_queue_kick();
  }
  return $list;
}

/** True if /proc/PID is in stopped state (T) after SIGSTOP. */
function nbd_pid_is_stopped($pid) {
  $pid = (int)$pid;
  if ($pid <= 1 || !@file_exists('/proc/' . $pid)) {
    return false;
  }
  $stat = @file_get_contents('/proc/' . $pid . '/stat');
  if (!is_string($stat) || $stat === '') {
    return false;
  }
  // comm may contain spaces/parens — state is after the last ')'
  $rparen = strrpos($stat, ')');
  if ($rparen === false) {
    return false;
  }
  $rest = trim(substr($stat, $rparen + 1));
  $state = $rest[0] ?? '';
  return ($state === 'T' || $state === 't');
}

/**
 * Collect PIDs for a Pull job (wrapper, children, orphan convert).
 *
 * @return int[]
 */
function nbd_job_pids(array $j) {
  $pids = [];
  $pid = (int)($j['pid'] ?? 0);
  if ($pid > 1 && @file_exists('/proc/' . $pid)) {
    $pids[] = $pid;
    $kids = (string)@shell_exec('pgrep -P ' . $pid . ' 2>/dev/null || true');
    foreach (preg_split('/\s+/', trim($kids)) as $k) {
      if ($k !== '' && ctype_digit($k)) {
        $pids[] = (int)$k;
      }
    }
  }
  $orphan = nbd_find_orphan_qemu_img($j);
  if ($orphan > 1) {
    $pids[] = $orphan;
  }
  $out = (string)($j['output'] ?? '');
  if ($out !== '') {
    $raw = (string)@shell_exec("ps -eo pid=,cmd= 2>/dev/null | grep '[q]emu-img convert' || true");
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
      if (preg_match('/^(\d+)\s+(.*)$/', trim($line), $m) && strpos($m[2], $out) !== false) {
        $pids[] = (int)$m[1];
      }
    }
  }
  return array_values(array_unique(array_filter($pids, function ($p) {
    return $p > 1;
  })));
}

/**
 * Pause a running Pull (SIGSTOP) — frees disk IO for parity etc. Slot stays reserved.
 */
function nbd_image_pause($id) {
  if (preg_match('/^ext-(\d+)$/', (string)$id, $em)) {
    $pid = (int)$em[1];
    if ($pid > 1 && @file_exists('/proc/' . $pid)) {
      @posix_kill($pid, 19);
      return ['ok' => true, 'id' => $id, 'external' => true, 'pids' => [$pid]];
    }
    return ['ok' => false, 'error' => 'External process not found'];
  }
  $j = nbd_job_load($id);
  if (!$j) {
    return ['ok' => false, 'error' => 'Unknown job'];
  }
  $st = nbd_job_ui_status($j);
  // Refresh from live state
  foreach (nbd_jobs_state() as $live) {
    if (($live['id'] ?? '') === ($j['id'] ?? '')) {
      $j = $live;
      $st = nbd_job_ui_status($j);
      break;
    }
  }
  if (($st['key'] ?? '') === 'paused') {
    return ['ok' => true, 'id' => $id, 'already' => true];
  }
  if (($st['key'] ?? '') !== 'running') {
    return ['ok' => false, 'error' => 'Only a Running job can be paused'];
  }
  $pids = nbd_job_pids($j);
  if (!$pids) {
    return ['ok' => false, 'error' => 'No process to pause'];
  }
  foreach ($pids as $p) {
    @posix_kill($p, 19); // SIGSTOP
  }
  $j['status'] = 'paused';
  $j['paused_at'] = date('c');
  $log = (string)($j['log'] ?? '');
  if ($log !== '') {
    @file_put_contents($log, date('c') . " paused (SIGSTOP)\n", FILE_APPEND);
  }
  nbd_job_persist($j);
  return ['ok' => true, 'id' => $id, 'pids' => $pids];
}

/**
 * Resume a paused Pull (SIGCONT).
 */
function nbd_image_resume($id) {
  if (preg_match('/^ext-(\d+)$/', (string)$id, $em)) {
    $pid = (int)$em[1];
    if ($pid > 1 && @file_exists('/proc/' . $pid)) {
      @posix_kill($pid, 18);
      return ['ok' => true, 'id' => $id, 'external' => true, 'pids' => [$pid]];
    }
    return ['ok' => false, 'error' => 'External process not found'];
  }
  $j = nbd_job_load($id);
  if (!$j) {
    return ['ok' => false, 'error' => 'Unknown job'];
  }
  foreach (nbd_jobs_state() as $live) {
    if (($live['id'] ?? '') === ($j['id'] ?? '')) {
      $j = $live;
      break;
    }
  }
  if (($j['status'] ?? '') !== 'paused' && empty(array_filter(nbd_job_pids($j), 'nbd_pid_is_stopped'))) {
    return ['ok' => false, 'error' => 'Job is not paused'];
  }
  $pids = nbd_job_pids($j);
  if (!$pids) {
    return ['ok' => false, 'error' => 'No process to resume'];
  }
  foreach ($pids as $p) {
    @posix_kill($p, 18); // SIGCONT
  }
  $j['status'] = 'running';
  $j['resumed_at'] = date('c');
  unset($j['paused_at']);
  $log = (string)($j['log'] ?? '');
  if ($log !== '') {
    @file_put_contents($log, date('c') . " resumed (SIGCONT)\n", FILE_APPEND);
  }
  nbd_job_persist($j);
  return ['ok' => true, 'id' => $id, 'pids' => $pids];
}

/**
 * Find qemu-img convert still writing a job output after the wrapper died.
 */
function nbd_find_orphan_qemu_img(array $j) {
  $out = (string)($j['output'] ?? '');
  $id = (string)($j['id'] ?? '');
  if ($out === '' && $id === '') {
    return 0;
  }
  $out_esc = str_replace(["'", '\\'], ["'\\''", '\\\\'], $out);
  $cmd = "ps -eo pid=,cmd= 2>/dev/null | grep '[q]emu-img convert' || true";
  $raw = (string)@shell_exec($cmd);
  foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
    $line = trim($line);
    if ($line === '') {
      continue;
    }
    if (!preg_match('/^(\d+)\s+(.*)$/', $line, $m)) {
      continue;
    }
    $pid = (int)$m[1];
    $cmdline = $m[2];
    if ($out !== '' && strpos($cmdline, $out) !== false) {
      return $pid;
    }
    if ($id !== '' && strpos($cmdline, $id) !== false) {
      return $pid;
    }
  }
  return 0;
}

/**
 * Compact live snapshot for WebUI polling (in-place badge updates).
 * @return array{exports:array,jobs:array,watch:bool,live_exports:int,live_jobs:int,queued_jobs:int}
 */
function nbd_live_snapshot() {
  // Kick queue when a slot is free (poller path)
  nbd_pull_queue_kick();
  $exports = [];
  $watch = false;
  $live_exports = 0;
  foreach (nbd_exports_state() as $e) {
    $st = nbd_export_ui_status($e);
    $key = $st['key'] ?? 'down';
    $alive = !empty($e['alive']) || !empty($e['listening']);
    if ($alive) {
      $live_exports++;
    }
    $exports[] = [
      'id' => (string)($e['id'] ?? ''),
      'key' => $key,
      'label' => (string)($st['label'] ?? $key),
      'class' => (string)($st['class'] ?? 'nbd-badge-stale'),
      'hint' => (string)($st['hint'] ?? ''),
      'alive' => !empty($e['alive']),
      'listening' => !empty($e['listening']),
      'read_only' => !empty($e['read_only']),
      'device' => (string)($e['device'] ?? ''),
      'url' => (string)($e['url'] ?? ''),
    ];
    if ($key === 'listening' || $key === 'process_up') {
      $watch = true;
    }
  }
  $jobs = [];
  $live_jobs = 0;
  $queued_jobs = 0;
  $job_list = function_exists('nbd_jobs_with_external') ? nbd_jobs_with_external() : nbd_jobs_state();
  foreach ($job_list as $j) {
    $st = nbd_job_ui_status($j);
    $key = $st['key'] ?? 'idle';
    if ($key === 'running' || $key === 'paused') {
      $live_jobs++;
      $watch = true;
    }
    if ($key === 'queued') {
      $queued_jobs++;
      $watch = true;
    }
    $log_tail = '';
    if (!empty($j['log']) && is_file($j['log']) && in_array($key, ['failed', 'done', 'running', 'queued', 'paused'], true)) {
      $log_tail = nbd_log_tail_display($j['log'], 8);
    }
    $pct = nbd_job_progress_pct($j);
    $eta = function_exists('nbd_job_progress_eta') ? nbd_job_progress_eta($j) : ['seconds' => null, 'label' => ''];
    $elapsed = function_exists('nbd_job_elapsed_seconds') ? nbd_job_elapsed_seconds($j) : null;
    $rates = ($key === 'running' && function_exists('nbd_job_io_rates')) ? nbd_job_io_rates($j) : ['net_h' => '', 'disk_h' => ''];
    $jobs[] = [
      'id' => (string)($j['id'] ?? ''),
      'key' => $key,
      'label' => (string)($st['label'] ?? $key),
      'class' => (string)($st['class'] ?? 'nbd-badge-stale'),
      'hint' => (string)($st['hint'] ?? ''),
      'alive' => !empty($j['alive']),
      'finished' => !empty($j['finished']),
      'ok' => !empty($j['ok']),
      'orphaned' => !empty($j['orphaned']),
      'array_like' => !empty($j['array_like']),
      'progress_pct' => $pct,
      'eta_seconds' => $eta['seconds'] ?? null,
      'eta_h' => (string)($eta['label'] ?? ''),
      'elapsed_seconds' => $elapsed,
      'elapsed_h' => ($elapsed !== null) ? nbd_format_duration($elapsed) : '',
      'rate_net_h' => (string)($rates['net_h'] ?? ''),
      'rate_disk_h' => (string)($rates['disk_h'] ?? ''),
      'started' => (string)($j['started'] ?? ''),
      'started_h' => nbd_format_when($j['started'] ?? ''),
      'output_size' => isset($j['output_size']) ? (int)$j['output_size'] : 0,
      'output_size_h' => (string)($j['output_size_h'] ?? '—'),
      'log_tail' => $log_tail,
    ];
  }
  return [
    'exports' => $exports,
    'jobs' => $jobs,
    'watch' => $watch,
    'live_exports' => $live_exports,
    'live_jobs' => $live_jobs,
    'queued_jobs' => $queued_jobs,
    'ts' => time(),
  ];
}

/**
 * Count Pull jobs that occupy a concurrency slot (Running or Paused).
 * Paused keeps the slot so Resume does not fight a newly kicked queue job.
 */
function nbd_count_running_pull_jobs() {
  $n = 0;
  foreach (nbd_jobs_state() as $j) {
    $st = nbd_job_ui_status($j);
    $k = $st['key'] ?? '';
    if ($k === 'running' || $k === 'paused') {
      $n++;
    }
  }
  return $n;
}

/** @deprecated use nbd_count_running_pull_jobs */
function nbd_count_alive_pull_jobs() {
  return nbd_count_running_pull_jobs();
}

function nbd_pull_max_concurrent(array $cfg = null) {
  if ($cfg === null) {
    $cfg = nbd_load_cfg();
  }
  $max = (int)($cfg['max_concurrent_pulls'] ?? 1);
  if ($max < 1) {
    $max = 1;
  }
  if ($max > 4) {
    $max = 4;
  }
  return $max;
}

/**
 * Persist job JSON (strip ephemeral keys).
 */
function nbd_job_persist(array $j) {
  $id = (string)($j['id'] ?? '');
  if ($id === '' || strpos($id, 'job-') !== 0) {
    return false;
  }
  $f = NBDEXPORT_RUN . '/' . $id . '.json';
  $persist = $j;
  unset($persist['alive'], $persist['output_size'], $persist['output_size_h'], $persist['orphaned'], $persist['orphan_pid']);
  return @file_put_contents($f, json_encode($persist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") !== false;
}

function nbd_job_load($id) {
  $id = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$id);
  if ($id === '' || strpos($id, 'job-') !== 0) {
    return null;
  }
  $f = NBDEXPORT_RUN . '/' . $id . '.json';
  if (!is_file($f)) {
    return null;
  }
  $j = @json_decode((string)@file_get_contents($f), true);
  return is_array($j) ? $j : null;
}

/**
 * Launch prepared job script (sets status=running).
 */
function nbd_image_launch($id) {
  $j = nbd_job_load($id);
  if (!$j) {
    return ['ok' => false, 'error' => 'Unknown job'];
  }
  if (($j['status'] ?? '') === 'running' && !empty($j['pid']) && @file_exists('/proc/' . (int)$j['pid'])) {
    return ['ok' => true, 'id' => $id, 'already' => true];
  }
  $script = NBDEXPORT_RUN . '/' . $id . '.sh';
  $pidfile = NBDEXPORT_RUN . '/' . $id . '.pid';
  if (!is_file($script)) {
    return ['ok' => false, 'error' => 'Job script missing'];
  }
  $log = (string)($j['log'] ?? (NBDEXPORT_LOG . '/' . $id . '.log'));
  @file_put_contents($log, date('c') . " launch\n", FILE_APPEND);
  $full = 'setsid nohup bash ' . escapeshellarg($script) . ' >/dev/null 2>&1 & echo $! >' . escapeshellarg($pidfile);
  exec($full);
  usleep(200000);
  $pid = (int)@file_get_contents($pidfile);
  $j['pid'] = $pid;
  $j['status'] = 'running';
  $j['started_run'] = date('c');
  unset($j['finished'], $j['finished_at'], $j['ok'], $j['queue_hint']);
  nbd_job_persist($j);
  return ['ok' => true, 'id' => $id, 'pid' => $pid];
}

/**
 * Queued jobs oldest-first, respecting optional queue_seq (lower = sooner).
 * @return array<int,array>
 */
function nbd_queued_jobs_ordered() {
  $queued = [];
  foreach (nbd_jobs_state() as $j) {
    if (($j['status'] ?? '') === 'queued' && empty($j['finished'])) {
      $queued[] = $j;
    }
  }
  usort($queued, function ($a, $b) {
    $sa = isset($a['queue_seq']) ? (int)$a['queue_seq'] : null;
    $sb = isset($b['queue_seq']) ? (int)$b['queue_seq'] : null;
    if ($sa !== null && $sb !== null && $sa !== $sb) {
      return $sa <=> $sb;
    }
    if ($sa !== null && $sb === null) {
      return -1;
    }
    if ($sa === null && $sb !== null) {
      return 1;
    }
    return strcmp($a['queued_at'] ?? $a['started'] ?? '', $b['queued_at'] ?? $b['started'] ?? '');
  });
  return $queued;
}

/** Next queue_seq for a newly queued job (append to end). */
function nbd_queue_next_seq() {
  $max = 0;
  foreach (nbd_jobs_state() as $j) {
    if (($j['status'] ?? '') !== 'queued' || !empty($j['finished'])) {
      continue;
    }
    if (isset($j['queue_seq'])) {
      $max = max($max, (int)$j['queue_seq']);
    }
  }
  return $max + 10;
}

/**
 * Move a queued job up (-1) or down (+1) in the start order.
 */
function nbd_queue_move($id, $dir) {
  $id = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$id);
  $dir = ((int)$dir) < 0 ? -1 : 1;
  $list = nbd_queued_jobs_ordered();
  $idx = -1;
  foreach ($list as $i => $j) {
    if (($j['id'] ?? '') === $id) {
      $idx = $i;
      break;
    }
  }
  if ($idx < 0) {
    return ['ok' => false, 'error' => 'Not a queued job'];
  }
  $swap = $idx + $dir;
  if ($swap < 0 || $swap >= count($list)) {
    return ['ok' => true, 'id' => $id, 'noop' => true];
  }
  // Assign contiguous seq so order is explicit
  $ids = [];
  foreach ($list as $j) {
    $ids[] = (string)$j['id'];
  }
  $tmp = $ids[$idx];
  $ids[$idx] = $ids[$swap];
  $ids[$swap] = $tmp;
  $seq = 10;
  foreach ($ids as $jid) {
    $j = nbd_job_load($jid);
    if (!is_array($j)) {
      continue;
    }
    $j['queue_seq'] = $seq;
    nbd_job_persist($j);
    $seq += 10;
  }
  return ['ok' => true, 'id' => $id];
}

/**
 * Start next queued job(s) while under max concurrent.
 */
function nbd_pull_queue_kick() {
  static $busy = false;
  if ($busy) {
    return ['ok' => true, 'started' => [], 'busy' => true];
  }
  $busy = true;
  $cfg = nbd_load_cfg();
  $max = nbd_pull_max_concurrent($cfg);
  $running = nbd_count_running_pull_jobs();
  if ($running >= $max) {
    $busy = false;
    return ['ok' => true, 'started' => []];
  }
  $queued = nbd_queued_jobs_ordered();
  $started = [];
  foreach ($queued as $j) {
    if ($running >= $max) {
      break;
    }
    $r = nbd_image_launch($j['id']);
    if (!empty($r['ok'])) {
      $started[] = $j['id'];
      $running++;
    }
  }
  $busy = false;
  return ['ok' => true, 'started' => $started];
}

/**
 * Play a queued job. $force=true starts even if at concurrency limit (user override).
 */
function nbd_image_play($id, $force = false) {
  $j = nbd_job_load($id);
  if (!$j) {
    return ['ok' => false, 'error' => 'Unknown job'];
  }
  if (($j['status'] ?? '') !== 'queued') {
    // Allow Play on orphaned/failed-looking jobs that still have qemu-img? No — use Stop.
    if (!empty($j['alive']) || ($j['status'] ?? '') === 'running') {
      return ['ok' => false, 'error' => 'Job is already running'];
    }
    if (!empty($j['finished'])) {
      return ['ok' => false, 'error' => 'Job already finished'];
    }
  }
  $cfg = nbd_load_cfg();
  $max = nbd_pull_max_concurrent($cfg);
  $running = nbd_count_running_pull_jobs();
  if (!$force && $running >= $max) {
    return [
      'ok' => false,
      'error' => 'Another Pull is still running (limit ' . $max
        . '). Wait for it to finish, or Force start (may stall the WebUI on array disks).',
      'need_force' => true,
    ];
  }
  $warn = '';
  if ($force && $running > 0) {
    $warn = 'Forced start while ' . $running . ' job(s) already running.';
    if (!empty($j['array_like'])) {
      $warn .= ' Array/parity writes will compete.';
    }
  }
  $r = nbd_image_launch($id);
  if (!empty($r['ok']) && $warn !== '') {
    $r['warn'] = $warn;
  }
  return $r;
}

function nbd_image_start($url, $output, $format = 'qcow2') {
  nbd_ensure_runtime_dirs();
  $cfg = nbd_load_cfg();
  if (($cfg['enabled'] ?? 'yes') !== 'yes') {
    return ['ok' => false, 'error' => 'Plugin is disabled.'];
  }
  $tools = nbd_detect_tools();
  if ($tools['qemu_img'] === '') {
    return ['ok' => false, 'error' => 'qemu-img not found.'];
  }
  $url = trim((string)$url); // source: nbd://… or /dev/… or /mnt|/tmp file
  $output = trim((string)$output);
  $format = strtolower(trim((string)$format));
  if (!in_array($format, ['qcow2', 'raw'], true)) {
    return ['ok' => false, 'error' => 'Format must be qcow2 or raw.'];
  }
  $source_type = 'nbd';
  if (preg_match('#^nbd://[A-Za-z0-9.:\[\]%-]+#', $url)) {
    $source_type = 'nbd';
  } elseif (preg_match('#^/dev/[A-Za-z0-9/._-]+$#', $url)) {
    $source_type = 'local_device';
    if (!@is_file($url) && !@file_exists($url)) {
      return ['ok' => false, 'error' => 'Local device not found: ' . $url];
    }
    $risk = nbd_device_risk($url);
    if (!empty($risk['risky'])) {
      // Still allow — UI should confirm; server notes in log
    }
  } elseif ((strpos($url, '/mnt/') === 0 || strpos($url, '/tmp/') === 0) && strpos($url, '..') === false) {
    $source_type = 'local_file';
    if (!is_file($url)) {
      return ['ok' => false, 'error' => 'Local file not found: ' . $url];
    }
  } else {
    return ['ok' => false, 'error' => 'Source must be nbd://…, /dev/…, or a file under /mnt or /tmp'];
  }
  if ($output === '' || strpos($output, '..') !== false) {
    return ['ok' => false, 'error' => 'Invalid output path.'];
  }
  if (preg_match('#^/dev/#', $output) || (function_exists('is_block') && @is_block($output))) {
    return ['ok' => false, 'error' => 'Output cannot be a block device (/dev/…). Use a file under /mnt/ (e.g. qcow2 on cache).'];
  }
  if (strpos($output, '/mnt/') !== 0 && strpos($output, '/tmp/') !== 0) {
    return ['ok' => false, 'error' => 'Output must be under /mnt/ (cache/array/share) or /tmp/.'];
  }
  if ($url === $output) {
    return ['ok' => false, 'error' => 'Source and output must be different paths.'];
  }
  $dir = dirname($output);
  if (!is_dir($dir)) {
    if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
      return ['ok' => false, 'error' => 'Cannot create directory: ' . $dir];
    }
  }

  $max = nbd_pull_max_concurrent($cfg);
  $array_like = nbd_path_is_array_like($output);

  // Same nbd:// already running or queued — refuse duplicate
  foreach (nbd_jobs_state() as $j) {
    $st = nbd_job_ui_status($j);
    $k = $st['key'] ?? '';
    if (!in_array($k, ['running', 'queued'], true)) {
      continue;
    }
    if (($j['url'] ?? '') === $url) {
      return [
        'ok' => false,
        'error' => 'A Pull of this NBD URL is already ' . $k . ' (' . ($j['id'] ?? '?')
          . '). Starting another copy of the same disk fights the array and the WebUI.',
      ];
    }
  }

  $id = 'job-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
  $pidfile = NBDEXPORT_RUN . '/' . $id . '.pid';
  $statefile = NBDEXPORT_RUN . '/' . $id . '.json';
  $logfile = NBDEXPORT_LOG . '/' . $id . '.log';
  $script = NBDEXPORT_RUN . '/' . $id . '.sh';

  $io_class = strtolower(trim((string)($cfg['pull_io_class'] ?? 'idle')));
  if (!in_array($io_class, ['idle', 'best-effort'], true)) {
    $io_class = 'idle';
  }
  $nice = (int)($cfg['pull_nice'] ?? 10);
  if ($nice < 0) {
    $nice = 0;
  }
  if ($nice > 19) {
    $nice = 19;
  }
  $ionice_args = ($io_class === 'idle') ? '-c3' : '-c2 -n7';
  $progressfile = NBDEXPORT_RUN . '/' . $id . '.progress';
  $progresshist = NBDEXPORT_RUN . '/' . $id . '.progress.hist';
  // Progress: qemu-img -p writes CR updates to stderr. Do NOT send SIGUSR1 without -p
  // (QEMU 10 on Unraid: USR1 = default terminate → exit 138). Exec so $! is qemu-img
  // (not ionice). Poll PROGRAW for (N/100%) — no process-substitution consumer.
  $sh = "#!/bin/bash\nset -uo pipefail\n"
    . 'LOG=' . escapeshellarg($logfile) . "\n"
    . 'PROG=' . escapeshellarg($progressfile) . "\n"
    . 'PROGHIST=' . escapeshellarg($progresshist) . "\n"
    . 'PROGRAW=' . escapeshellarg($progressfile . '.raw') . "\n"
    . 'SRC=' . escapeshellarg($url) . "\n"
    . 'OUT=' . escapeshellarg($output) . "\n"
    . 'IMG=' . escapeshellarg($tools['qemu_img']) . "\n"
    . 'FMT=' . escapeshellarg($format) . "\n"
    . 'NICE_N=' . (int)$nice . "\n"
    . 'IONICE_ARGS=' . escapeshellarg($ionice_args) . "\n"
    . 'fail() { echo "NBD_JOB_FAIL $*" >>"$LOG"; echo "$(date -Iseconds) job failed: $*" >>"$LOG"; exit 1; }' . "\n"
    . 'if command -v ionice >/dev/null 2>&1; then HAVE_IONICE=1; else HAVE_IONICE=0; fi' . "\n"
    . 'if command -v stdbuf >/dev/null 2>&1; then STDBUF=(stdbuf -o0 -e0); else STDBUF=(); fi' . "\n"
    . 'run_img() {' . "\n"
    . '  if [ "$HAVE_IONICE" = 1 ]; then' . "\n"
    . '    if [ "${#STDBUF[@]}" -gt 0 ]; then ionice $IONICE_ARGS nice -n "$NICE_N" "${STDBUF[@]}" "$@"; else ionice $IONICE_ARGS nice -n "$NICE_N" "$@"; fi' . "\n"
    . '  else' . "\n"
    . '    if [ "${#STDBUF[@]}" -gt 0 ]; then nice -n "$NICE_N" "${STDBUF[@]}" "$@"; else nice -n "$NICE_N" "$@"; fi' . "\n"
    . '  fi' . "\n"
    . '}' . "\n"
    . 'echo "$(date -Iseconds) job start type=' . $source_type . ' progress=-p+poll" >>"$LOG"' . "\n"
    . 'if [[ "$SRC" == nbd://* ]]; then' . "\n"
    . '  for i in $(seq 1 60); do' . "\n"
    . '    if run_img "$IMG" info "$SRC" >>"$LOG" 2>&1; then break; fi' . "\n"
    . '    sleep 5' . "\n"
    . '    if [ "$i" -eq 60 ]; then fail wait_src; fi' . "\n"
    . '  done' . "\n"
    . 'else' . "\n"
    . '  run_img "$IMG" info -f raw "$SRC" >>"$LOG" 2>&1 || run_img "$IMG" info "$SRC" >>"$LOG" 2>&1 || true' . "\n"
    . 'fi' . "\n"
    . 'LAST_PCT_X10=-1' . "\n"
    . ': >"$PROGHIST"' . "\n"
    . ': >"$PROGRAW"' . "\n"
    . 'echo "pct=0" >"$PROG"' . "\n"
    . 'set +e' . "\n"
    . 'SRC_FMT=raw' . "\n"
    . '# Subshell + exec → $! is qemu-img. -p progress → PROGRAW (+ LOG fallback; some builds use stdout).' . "\n"
    . '(' . "\n"
    . '  if [ "$HAVE_IONICE" = 1 ]; then' . "\n"
    . '    if [ "${#STDBUF[@]}" -gt 0 ]; then' . "\n"
    . '      exec ionice $IONICE_ARGS nice -n "$NICE_N" "${STDBUF[@]}" "$IMG" convert -p -f "$SRC_FMT" -O "$FMT" -t writeback -W "$SRC" "$OUT"' . "\n"
    . '    else' . "\n"
    . '      exec ionice $IONICE_ARGS nice -n "$NICE_N" "$IMG" convert -p -f "$SRC_FMT" -O "$FMT" -t writeback -W "$SRC" "$OUT"' . "\n"
    . '    fi' . "\n"
    . '  else' . "\n"
    . '    if [ "${#STDBUF[@]}" -gt 0 ]; then' . "\n"
    . '      exec nice -n "$NICE_N" "${STDBUF[@]}" "$IMG" convert -p -f "$SRC_FMT" -O "$FMT" -t writeback -W "$SRC" "$OUT"' . "\n"
    . '    else' . "\n"
    . '      exec nice -n "$NICE_N" "$IMG" convert -p -f "$SRC_FMT" -O "$FMT" -t writeback -W "$SRC" "$OUT"' . "\n"
    . '    fi' . "\n"
    . '  fi' . "\n"
    . ') >>"$LOG" 2>>"$PROGRAW" &' . "\n"
    . 'CPID=$!' . "\n"
    . 'echo "$(date -Iseconds) convert pid=$CPID" >>"$LOG"' . "\n"
    . 'while kill -0 "$CPID" 2>/dev/null; do' . "\n"
    . '  sleep 3' . "\n"
    . '  line=$(tr "\\r" "\\n" <"$PROGRAW" 2>/dev/null | grep -E "/100%\\)" | tail -1)' . "\n"
    . '  if [ -z "$line" ]; then' . "\n"
    . '    line=$(tr "\\r" "\\n" <"$LOG" 2>/dev/null | grep -E "/100%\\)" | tail -1)' . "\n"
    . '  fi' . "\n"
    . '  [ -n "$line" ] || continue' . "\n"
    . '  pct="${line#*(}"; pct="${pct%%/*}"' . "\n"
    . '  echo "pct=$pct" >"$PROG"' . "\n"
    . '  # Tenths of a percent so hist/ETA move before a full integer tick' . "\n"
    . '  ipct=${pct%%.*}; frac=${pct#*.}; [ "$frac" = "$pct" ] && frac=0; frac=${frac:0:1}; [ -z "$frac" ] && frac=0' . "\n"
    . '  x10=$(( ${ipct:-0} * 10 + ${frac:-0} ))' . "\n"
    . '  if [ "$x10" -ne "$LAST_PCT_X10" ]; then' . "\n"
    . '    echo "$(date -Iseconds) progress ($pct/100%)" >>"$LOG"' . "\n"
    . '    echo "$(date +%s) $pct" >>"$PROGHIST"' . "\n"
    . '    LAST_PCT_X10=$x10' . "\n"
    . '  fi' . "\n"
    . 'done' . "\n"
    . 'wait "$CPID"' . "\n"
    . 'CONV_RC=$?' . "\n"
    . 'set -e' . "\n"
    . 'if [ "${CONV_RC}" -ne 0 ]; then fail convert rc=$CONV_RC; fi' . "\n"
    . 'echo "$(date -Iseconds) progress (100/100%)" >>"$LOG"' . "\n"
    . 'echo "pct=100" >"$PROG"' . "\n"
    . 'echo "$(date +%s) 100" >>"$PROGHIST"' . "\n"
    . 'run_img "$IMG" check "$OUT" >>"$LOG" 2>&1 || true' . "\n"
    . 'run_img "$IMG" info "$OUT" >>"$LOG" 2>&1' . "\n"
    . 'echo NBD_JOB_OK >>"$LOG"' . "\n"
    . 'echo "$(date -Iseconds) job done" >>"$LOG"' . "\n";

  @file_put_contents($script, $sh);
  @chmod($script, 0755);
  @file_put_contents($logfile, date('c') . " prepared\n");

  $running = nbd_count_running_pull_jobs();
  $queue = ($running >= $max);

  // Extra warn when queueing behind an array write, or this job is array-like
  $queue_hint = 'Waiting for a free Pull slot (max ' . $max . '). Open Status → Play to start, or it starts when the running job finishes.';
  if ($array_like) {
    $queue_hint .= ' Output is on an array path (/mnt/disk* or /mnt/user*) — concurrent array Pulls contend for parity and can stall the WebUI. Prefer /mnt/cache for large images.';
  }
  foreach (nbd_jobs_state() as $rj) {
    if ((nbd_job_ui_status($rj)['key'] ?? '') !== 'running') {
      continue;
    }
    if (!empty($rj['array_like']) && $array_like) {
      $queue_hint .= ' Another array Pull is already running — queued to protect parity/WebUI.';
      break;
    }
  }

  $state = [
    'id' => $id,
    'url' => $url,
    'output' => $output,
    'format' => $format,
    'pid' => 0,
    'started' => date('c'),
    'log' => $logfile,
    'io_class' => $io_class,
    'nice' => (string)$nice,
    'array_like' => $array_like,
    'source_type' => $source_type,
    'status' => $queue ? 'queued' : 'running',
  ];
  if ($queue) {
    $state['queued_at'] = date('c');
    $state['queue_seq'] = nbd_queue_next_seq();
    $state['queue_hint'] = $queue_hint;
    @file_put_contents($logfile, date('c') . " queued: " . $queue_hint . "\n", FILE_APPEND);
    nbd_job_persist($state);
    return [
      'ok' => true,
      'id' => $id,
      'queued' => true,
      'warn' => $queue_hint,
    ];
  }

  nbd_job_persist($state);
  $r = nbd_image_launch($id);
  if (empty($r['ok'])) {
    return $r;
  }
  $out = ['ok' => true, 'id' => $id, 'queued' => false];
  if ($array_like) {
    $out['warn'] = 'Writing to an array path — large Pulls can slow Main. Prefer /mnt/cache when possible.';
  }
  return $out;
}

/**
 * Stop a Pull job: kill process group + any orphaned qemu-img for this output.
 */
function nbd_image_stop($id) {
  $id = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$id);
  // External converts: ext-<pid>
  if (preg_match('/^ext-(\d+)$/', $id, $em)) {
    $pid = (int)$em[1];
    if ($pid > 1 && @file_exists('/proc/' . $pid)) {
      @posix_kill($pid, 15);
      usleep(200000);
      if (@file_exists('/proc/' . $pid)) {
        @posix_kill($pid, 9);
      }
    }
    return ['ok' => true, 'external' => true, 'killed' => [$pid]];
  }
  if ($id === '' || strpos($id, 'job-') !== 0) {
    return ['ok' => false, 'error' => 'Invalid job id'];
  }
  $j = nbd_job_load($id);
  $pidfile = NBDEXPORT_RUN . '/' . $id . '.pid';
  $pid = is_file($pidfile) ? (int)@file_get_contents($pidfile) : 0;
  if ($pid <= 0 && is_array($j)) {
    $pid = (int)($j['pid'] ?? 0);
  }

  // Cancel queued job (never launched)
  if (is_array($j) && ($j['status'] ?? '') === 'queued') {
    $j['status'] = 'failed';
    $j['finished'] = true;
    $j['ok'] = false;
    $j['finished_at'] = date('c');
    $log = (string)($j['log'] ?? '');
    if ($log !== '') {
      @file_put_contents($log, "\nNBD_JOB_FAIL cancelled_while_queued\n", FILE_APPEND);
    }
    nbd_job_persist($j);
    nbd_pull_queue_kick();
    return ['ok' => true, 'cancelled_queue' => true];
  }

  $pids = [];
  if ($pid > 0) {
    $pids[] = $pid;
    // Children of wrapper
    $kids = (string)@shell_exec('pgrep -P ' . (int)$pid . ' 2>/dev/null || true');
    foreach (preg_split('/\s+/', trim($kids)) as $k) {
      if ($k !== '' && ctype_digit($k)) {
        $pids[] = (int)$k;
      }
    }
    // Process group (setsid makes wrapper the PGID)
    @exec('kill -TERM -' . (int)$pid . ' 2>/dev/null || true');
  }
  if (is_array($j)) {
    $orphan = nbd_find_orphan_qemu_img($j);
    if ($orphan > 0) {
      $pids[] = $orphan;
    }
  }
  // Also match qemu-img by job script path / output
  $out = is_array($j) ? (string)($j['output'] ?? '') : '';
  if ($out !== '') {
    $raw = (string)@shell_exec("ps -eo pid=,cmd= 2>/dev/null | grep '[q]emu-img convert' || true");
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
      if (preg_match('/^(\d+)\s+(.*)$/', trim($line), $m) && strpos($m[2], $out) !== false) {
        $pids[] = (int)$m[1];
      }
    }
  }
  $pids = array_values(array_unique(array_filter($pids)));
  foreach ($pids as $p) {
    if ($p > 1 && @file_exists('/proc/' . $p)) {
      @posix_kill($p, 15);
    }
  }
  usleep(300000);
  foreach ($pids as $p) {
    if ($p > 1 && @file_exists('/proc/' . $p)) {
      @posix_kill($p, 9);
    }
  }
  // Avoid pkill -f self-match; targeted kills above are enough

  if (is_array($j)) {
    $j['status'] = 'failed';
    $j['finished'] = true;
    $j['ok'] = false;
    $j['finished_at'] = date('c');
    $log = (string)($j['log'] ?? '');
    if ($log !== '') {
      @file_put_contents($log, "\nNBD_JOB_FAIL stopped_by_user\n", FILE_APPEND);
    }
    nbd_job_persist($j);
  }
  nbd_pull_queue_kick();
  return ['ok' => true, 'killed' => $pids];
}

/**
 * True if a job card may be cleared from history (not live work).
 */
function nbd_job_is_clearable(array $j) {
  if (!empty($j['external'])) {
    return false;
  }
  $id = (string)($j['id'] ?? '');
  if ($id === '' || strpos($id, 'job-') !== 0) {
    return false;
  }
  $k = nbd_job_ui_status($j)['key'] ?? '';
  return !in_array($k, ['running', 'paused', 'queued'], true);
}

/**
 * Remove one finished job's run-state from Status History.
 * Keeps /var/log/nbdexport/<id>.log for the Logs tab (Clear log wipes those).
 * Does not delete the output image.
 */
function nbd_job_clear_one($id) {
  $id = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$id);
  if ($id === '' || strpos($id, 'job-') !== 0) {
    return ['ok' => false, 'error' => 'Invalid job id'];
  }
  $j = nbd_job_load($id);
  if (!is_array($j)) {
    // Still scrub orphan sidecars
  } elseif (!nbd_job_is_clearable($j)) {
    return ['ok' => false, 'error' => 'Stop or cancel the job before clearing'];
  }
  foreach ([
    NBDEXPORT_RUN . '/' . $id . '.json',
    NBDEXPORT_RUN . '/' . $id . '.pid',
    NBDEXPORT_RUN . '/' . $id . '.sh',
    NBDEXPORT_RUN . '/' . $id . '.progress',
    NBDEXPORT_RUN . '/' . $id . '.progress.hist',
  ] as $f) {
    if (is_file($f)) {
      @unlink($f);
    }
  }
  // Log file intentionally kept — see Logs tab / nbd_logs_clear().
  return ['ok' => true, 'id' => $id];
}

/**
 * Basename → whether that log is tied to a live Host/Pull (must not Clear).
 * @return array<string,true>
 */
function nbd_logs_in_use_basenames() {
  $live = [];
  foreach (function_exists('nbd_jobs_state') ? nbd_jobs_state() : [] as $j) {
    $st = nbd_job_ui_status($j);
    $k = $st['key'] ?? '';
    if (!in_array($k, ['running', 'paused', 'queued'], true)) {
      continue;
    }
    $log = (string)($j['log'] ?? '');
    if ($log === '') {
      $id = (string)($j['id'] ?? '');
      if ($id !== '') {
        $log = NBDEXPORT_LOG . '/' . $id . '.log';
      }
    }
    if ($log !== '' && is_file($log)) {
      $live[basename($log)] = true;
    }
  }
  foreach (function_exists('nbd_exports_state') ? nbd_exports_state() : [] as $e) {
    $alive = !empty($e['alive']) || !empty($e['listening']);
    if (!$alive) {
      continue;
    }
    $id = (string)($e['id'] ?? '');
    if ($id === '') {
      continue;
    }
    $log = (string)($e['log'] ?? (NBDEXPORT_LOG . '/' . $id . '.log'));
    if ($log !== '' && is_file($log)) {
      $live[basename($log)] = true;
    }
  }
  // Beacon while its process is up
  $beacon_pidfile = function_exists('nbd_beacon_pidfile') ? nbd_beacon_pidfile() : (NBDEXPORT_RUN . '/beacon-http.pid');
  $beacon_log = function_exists('nbd_beacon_logfile') ? nbd_beacon_logfile() : (NBDEXPORT_LOG . '/beacon-http.log');
  $bpid = 0;
  if (is_file($beacon_pidfile)) {
    $bpid = (int)trim((string)@file_get_contents($beacon_pidfile));
  }
  if ($bpid > 0 && @file_exists('/proc/' . $bpid) && is_file($beacon_log)) {
    $live[basename($beacon_log)] = true;
  }
  return $live;
}

/**
 * List plugin log files under /var/log/nbdexport (newest first).
 * @return list<array{name:string,path:string,kind:string,size:int,size_h:string,mtime:int,mtime_h:string,in_use:bool}>
 */
function nbd_list_log_files() {
  nbd_ensure_runtime_dirs();
  $live = nbd_logs_in_use_basenames();
  $out = [];
  foreach (glob(NBDEXPORT_LOG . '/*.log') ?: [] as $path) {
    if (!is_file($path)) {
      continue;
    }
    $name = basename($path);
    $kind = 'other';
    if (strpos($name, 'job-') === 0) {
      $kind = 'job';
    } elseif ($name === 'beacon-http.log') {
      $kind = 'beacon';
    } elseif (preg_match('/^[A-Za-z0-9._-]+\.log$/', $name)) {
      $kind = 'host';
    }
    $size = (int)@filesize($path);
    $mtime = (int)@filemtime($path);
    $out[] = [
      'name' => $name,
      'path' => $path,
      'kind' => $kind,
      'size' => $size,
      'size_h' => function_exists('nbd_format_bytes') ? nbd_format_bytes($size) : ((string)$size . ' B'),
      'mtime' => $mtime,
      'mtime_h' => $mtime > 0 ? date('Y-m-d H:i:s', $mtime) : '—',
      'in_use' => !empty($live[$name]),
    ];
  }
  usort($out, function ($a, $b) {
    return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
  });
  return $out;
}

/**
 * Read log for Logs tab (full file, or last $max_bytes if larger).
 * @return array{text:string,truncated:bool,size:int}
 */
function nbd_log_read_display($path, $max_bytes = 204800) {
  $path = (string)$path;
  $max_bytes = max(4096, (int)$max_bytes);
  if ($path === '' || !is_file($path)) {
    return ['text' => '', 'truncated' => false, 'size' => 0];
  }
  // Only allow files under our log dir
  $real = realpath($path);
  $root = realpath(NBDEXPORT_LOG);
  if ($real === false || $root === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
    return ['text' => '', 'truncated' => false, 'size' => 0];
  }
  $size = (int)@filesize($real);
  if ($size <= $max_bytes) {
    $text = (string)@file_get_contents($real);
    return ['text' => $text, 'truncated' => false, 'size' => $size];
  }
  $fh = @fopen($real, 'rb');
  if (!$fh) {
    return ['text' => '', 'truncated' => true, 'size' => $size];
  }
  @fseek($fh, -$max_bytes, SEEK_END);
  $text = (string)@stream_get_contents($fh);
  @fclose($fh);
  // Drop partial first line after seek
  $nl = strpos($text, "\n");
  if ($nl !== false && $nl < 512) {
    $text = substr($text, $nl + 1);
  }
  return ['text' => $text, 'truncated' => true, 'size' => $size];
}

/**
 * Wipe finished plugin logs (keep live job/host/beacon logs).
 * @return array{ok:bool,deleted:string[],kept:string[],error?:string}
 */
function nbd_logs_clear() {
  nbd_ensure_runtime_dirs();
  $live = nbd_logs_in_use_basenames();
  $deleted = [];
  $kept = [];
  foreach (glob(NBDEXPORT_LOG . '/*.log') ?: [] as $path) {
    if (!is_file($path)) {
      continue;
    }
    $name = basename($path);
    if (!empty($live[$name])) {
      $kept[] = $name;
      continue;
    }
    if (@unlink($path)) {
      $deleted[] = $name;
    }
  }
  return [
    'ok' => true,
    'deleted' => $deleted,
    'kept' => $kept,
  ];
}

/**
 * Clear selected job ids and/or all finished jobs.
 *
 * @param string[] $ids
 * @param bool $all_finished
 * @param bool $delete_outputs Also unlink output images under /mnt|/tmp
 * @return array{ok:bool,cleared:string[],deleted:string[],skipped:string[],error?:string}
 */
function nbd_jobs_clear(array $ids, $all_finished = false, $delete_outputs = false) {
  $cleared = [];
  $deleted = [];
  $skipped = [];
  $want = [];
  foreach ($ids as $id) {
    $id = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$id);
    if ($id !== '' && strpos($id, 'job-') === 0) {
      $want[$id] = true;
    }
  }
  if ($all_finished) {
    foreach (nbd_jobs_state() as $j) {
      if (nbd_job_is_clearable($j) && !empty($j['id'])) {
        $want[(string)$j['id']] = true;
      }
    }
  }
  if (!$want) {
    return ['ok' => false, 'error' => 'Nothing selected to clear', 'cleared' => [], 'deleted' => [], 'skipped' => []];
  }
  foreach (array_keys($want) as $id) {
    if ($delete_outputs) {
      $dr = nbd_job_delete_output($id);
      if (!empty($dr['ok']) && empty($dr['missing']) && !empty($dr['path'])) {
        $deleted[] = (string)$dr['path'];
      }
    }
    $r = nbd_job_clear_one($id);
    if (!empty($r['ok'])) {
      $cleared[] = $id;
    } else {
      $skipped[] = $id . ': ' . ($r['error'] ?? 'skipped');
    }
  }
  return [
    'ok' => count($cleared) > 0,
    'cleared' => $cleared,
    'deleted' => $deleted,
    'skipped' => $skipped,
    'error' => count($cleared) ? null : ('Nothing cleared' . ($skipped ? (' — ' . implode('; ', $skipped)) : '')),
  ];
}

/**
 * Delete a finished job's output image on disk (incomplete/failed pulls cannot resume).
 * Never deletes outside /mnt or /tmp. Does not remove the job card (use Clear for that).
 */
function nbd_job_delete_output($id) {
  $id = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$id);
  if ($id === '' || strpos($id, 'job-') !== 0) {
    return ['ok' => false, 'error' => 'Invalid job id'];
  }
  $j = nbd_job_load($id);
  if (!is_array($j)) {
    return ['ok' => false, 'error' => 'Job not found'];
  }
  if (!nbd_job_is_clearable($j)) {
    return ['ok' => false, 'error' => 'Stop the job before deleting its output file'];
  }
  $out = trim((string)($j['output'] ?? ''));
  if ($out === '' || $out === '(unknown)') {
    return ['ok' => false, 'error' => 'No output path on this job'];
  }
  if (strpos($out, '/mnt/') !== 0 && strpos($out, '/tmp/') !== 0) {
    return ['ok' => false, 'error' => 'Refusing to delete outside /mnt or /tmp'];
  }
  if (is_dir($out)) {
    return ['ok' => false, 'error' => 'Output path is a directory'];
  }
  if (!file_exists($out)) {
    return ['ok' => true, 'id' => $id, 'missing' => true, 'path' => $out];
  }
  if (!is_file($out) && !is_link($out)) {
    return ['ok' => false, 'error' => 'Not a regular file: ' . $out];
  }
  $sz = @filesize($out);
  if (!@unlink($out)) {
    return ['ok' => false, 'error' => 'unlink failed: ' . $out];
  }
  // Best-effort sidecars
  @unlink($out . '.progress');
  return ['ok' => true, 'id' => $id, 'path' => $out, 'bytes' => $sz !== false ? (int)$sz : null];
}

/**
 * Start a new Pull using a prior job's (or edited) source/output/format.
 *
 * - If the output path is unchanged and the file still exists → delete it first
 *   (stopped converts cannot resume).
 * - On success → clear the old History card (list entry + log); new job is Active/Queued.
 *
 * @param array{url?:string,output?:string,format?:string} $overrides
 */
function nbd_image_retry($id, array $overrides = []) {
  $id = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$id);
  if ($id === '' || strpos($id, 'job-') !== 0) {
    return ['ok' => false, 'error' => 'Invalid job id'];
  }
  $j = nbd_job_load($id);
  if (!is_array($j)) {
    return ['ok' => false, 'error' => 'Job not found'];
  }
  $k = nbd_job_ui_status($j)['key'] ?? '';
  if (in_array($k, ['running', 'paused', 'queued'], true)) {
    return ['ok' => false, 'error' => 'Stop or cancel the live job before retrying'];
  }
  $old_out = trim((string)($j['output'] ?? ''));
  $url = trim((string)($overrides['url'] ?? $j['url'] ?? ''));
  $output = trim((string)($overrides['output'] ?? $j['output'] ?? ''));
  $format = trim((string)($overrides['format'] ?? $j['format'] ?? 'qcow2'));
  if ($url === '' || $output === '') {
    return ['ok' => false, 'error' => 'Missing source or output path'];
  }
  $removed = false;
  // Same path (or still pointing at old file): must remove — cannot resume mid-convert
  if ($output === $old_out && $output !== '' && is_file($output)) {
    $dr = nbd_job_delete_output($id);
    if (empty($dr['ok'])) {
      return ['ok' => false, 'error' => 'Could not remove existing output: ' . ($dr['error'] ?? $output)];
    }
    $removed = empty($dr['missing']);
  }
  $r = nbd_image_start($url, $output, $format);
  if (!empty($r['ok'])) {
    $r['retried_from'] = $id;
    $r['removed_output'] = $removed;
    // Drop old History card so the new Active/Queued job is the one that remains
    nbd_job_clear_one($id);
  }
  return $r;
}

function nbd_write_companion_marker() {
  nbd_ensure_runtime_dirs();
  $m = [
    'plugin' => 'NBDExport',
    'provides' => ['nbd-export', 'qemu-nbd', 'disk-imaging'],
    'version' => nbd_plugin_version(),
  ];
  @file_put_contents(NBDEXPORT_CFG_DIR . '/companion.json', json_encode($m, JSON_PRETTY_PRINT) . "\n");
}

// Upgrade reconcile + external convert discovery (after core job/export helpers exist)
require_once __DIR__ . '/nbd-reconcile.php';

function nbd_status() {
  $tools = nbd_detect_tools();
  $cfg = nbd_load_cfg();
  return [
    'plugin_version' => nbd_plugin_version(),
    'cfg' => $cfg,
    'tools' => $tools,
    'tools_ok' => $tools['qemu_nbd'] !== '' && $tools['qemu_img'] !== '',
    'exports' => nbd_exports_state(),
    'jobs' => nbd_jobs_state(),
    'bind_ips' => nbd_list_bind_ips(),
    'thunderboltnet' => nbd_thunderboltnet_present(),
    'fabricrouting' => nbd_fabricrouting_present(),
  ];
}

function nbd_log_tail($path, $lines = 40) {
  if (!is_file($path)) {
    return '';
  }
  $out = [];
  @exec('tail -n ' . (int)$lines . ' ' . escapeshellarg($path) . ' 2>/dev/null', $out);
  return implode("\n", $out);
}

/* ── Discovery / beacon / LAN scan (see docs/discovery.md) ─────────────── */

/** Default TCP port for peer plugin beacon (HTTP JSON). NBD data stays on 10809+. */
function nbd_beacon_port() {
  return 10808;
}

function nbd_beacon_pidfile() {
  return NBDEXPORT_RUN . '/beacon-http.pid';
}

function nbd_beacon_logfile() {
  return NBDEXPORT_LOG . '/beacon-http.log';
}

/**
 * JSON payload for peer scanners (no secrets, no block data).
 */
function nbd_beacon_payload() {
  $exports = [];
  foreach (nbd_exports_state() as $e) {
    if (empty($e['alive']) && empty($e['listening'])) {
      continue;
    }
    $dev = (string)($e['device'] ?? '');
    $exports[] = [
      'id' => (string)($e['id'] ?? ''),
      'url' => (string)($e['url'] ?? ''),
      'bind' => (string)($e['bind'] ?? ''),
      'port' => (int)($e['port'] ?? 0),
      'read_only' => !empty($e['read_only']),
      'label' => (string)($e['label'] ?? ''),
      'device_name' => preg_replace('#^/dev/#', '', $dev),
      'listening' => !empty($e['listening']),
    ];
  }
  $host = trim((string)@file_get_contents('/etc/hostname'));
  if ($host === '') {
    $host = gethostname() ?: 'unraid';
  }
  return [
    'plugin' => 'NBDExport',
    'product' => 'NBD Export',
    'version' => nbd_plugin_version(),
    'hostname' => $host,
    'beacon_port' => nbd_beacon_port(),
    'exports' => $exports,
    'ts' => time(),
  ];
}

/**
 * Ensure lightweight beacon HTTP is running while any managed export is up.
 * Uses php -S (no Unraid login) so peers can discover without root passwords.
 */
function nbd_beacon_ensure() {
  nbd_ensure_runtime_dirs();
  $alive_exports = 0;
  foreach (nbd_exports_state() as $e) {
    if (!empty($e['alive']) || !empty($e['listening'])) {
      $alive_exports++;
    }
  }
  if ($alive_exports < 1) {
    nbd_beacon_stop();
    return ['ok' => true, 'running' => false, 'reason' => 'no active exports'];
  }

  $pidfile = nbd_beacon_pidfile();
  $pid = is_file($pidfile) ? (int)@file_get_contents($pidfile) : 0;
  if ($pid > 0 && @file_exists('/proc/' . $pid)) {
    return ['ok' => true, 'running' => true, 'pid' => $pid];
  }

  $router = NBDEXPORT_ROOT . '/include/nbd-beacon-server.php';
  if (!is_file($router)) {
    $router = dirname(__DIR__) . '/include/nbd-beacon-server.php';
  }
  if (!is_file($router)) {
    return ['ok' => false, 'error' => 'beacon server script missing'];
  }

  $php = trim((string)@shell_exec('command -v php 2>/dev/null'));
  if ($php === '' || !is_executable($php)) {
    $php = '/usr/bin/php';
  }
  if (!is_executable($php)) {
    return ['ok' => false, 'error' => 'php not found for beacon'];
  }

  $port = nbd_beacon_port();
  $log = nbd_beacon_logfile();
  // Bind all interfaces; server script rejects non-private clients.
  $cmd = 'setsid nohup ' . escapeshellarg($php) . ' -S 0.0.0.0:' . (int)$port
    . ' ' . escapeshellarg($router)
    . ' >>' . escapeshellarg($log) . ' 2>&1 & echo $! >' . escapeshellarg($pidfile);
  exec($cmd);
  usleep(200000);
  $pid = is_file($pidfile) ? (int)@file_get_contents($pidfile) : 0;
  return [
    'ok' => $pid > 0 && @file_exists('/proc/' . $pid),
    'running' => $pid > 0 && @file_exists('/proc/' . $pid),
    'pid' => $pid,
    'port' => $port,
  ];
}

function nbd_beacon_stop() {
  $pidfile = nbd_beacon_pidfile();
  $pid = is_file($pidfile) ? (int)@file_get_contents($pidfile) : 0;
  if ($pid > 0 && @file_exists('/proc/' . $pid)) {
    @posix_kill($pid, 15);
    usleep(150000);
    if (@file_exists('/proc/' . $pid)) {
      @posix_kill($pid, 9);
    }
  }
  @unlink($pidfile);
  return ['ok' => true];
}

/**
 * Private /24 networks to scan: local interfaces + private routes (e.g. LAN via gateway).
 * @return string[] list of "a.b.c.0/24"
 */
function nbd_scan_subnets() {
  $nets = [];
  $add = function ($ip, $pref) use (&$nets) {
    if (!nbd_is_private_ipv4($ip)) {
      return;
    }
    $pref = (int)$pref;
    if ($pref < 22 || $pref > 30) {
      $pref = 24;
    }
    $long = ip2long($ip);
    if ($long === false) {
      return;
    }
    $mask = -1 << (32 - min(24, $pref));
    // Always scan as /24 grids (cap how many for wider prefixes)
    $net_long = $long & (-1 << (32 - $pref));
    if ($pref < 24) {
      $count = min(4, 1 << (24 - $pref));
      $base = $net_long;
      for ($i = 0; $i < $count; $i++) {
        $nets[] = long2ip($base + ($i * 256)) . '/24';
      }
    } else {
      $nets[] = long2ip($long & (-1 << 8)) . '/24';
    }
  };

  $out = [];
  @exec('ip -4 -o addr show scope global 2>/dev/null', $out);
  foreach ($out as $line) {
    if (!preg_match('/inet\s+(\d+\.\d+\.\d+\.\d+)\/(\d+)/', $line, $m)) {
      continue;
    }
    $add($m[1], (int)$m[2]);
  }

  // Routes: "192.168.1.0/24 via …" (covers LAN via gateway when not on that iface)
  $routes = [];
  @exec('ip -4 route show 2>/dev/null', $routes);
  foreach ($routes as $line) {
    if (preg_match('/^(\d+\.\d+\.\d+\.\d+)\/(\d+)\s/', $line, $m)) {
      $pref = (int)$m[2];
      if ($pref < 22 || $pref > 24) {
        continue; // skip default and huge aggregates
      }
      $add($m[1], $pref);
    }
  }

  // Optional cfg: scan_extra_subnets="192.168.1.0/24,10.0.0.0/24"
  $cfg = nbd_load_cfg();
  $extra = trim((string)($cfg['scan_extra_subnets'] ?? ''));
  if ($extra !== '') {
    foreach (preg_split('/[\s,;]+/', $extra) as $cidr) {
      if ($cidr === '') {
        continue;
      }
      if (preg_match('#^(\d+\.\d+\.\d+\.\d+)/(\d+)$#', $cidr, $m)) {
        $add($m[1], (int)$m[2]);
      } elseif (preg_match('#^(\d+\.\d+\.\d+\.\d+)$#', $cidr, $m)) {
        $add($m[1], 24);
      }
    }
  }

  $nets = array_values(array_unique($nets));
  sort($nets);
  return $nets;
}

/**
 * Last successful scan peer IPs (always re-probed even if subnet list changed).
 * @return string[]
 */
function nbd_scan_known_peers() {
  $path = NBDEXPORT_CFG_DIR . '/scan-peers.json';
  if (!is_file($path)) {
    return [];
  }
  $j = @json_decode((string)@file_get_contents($path), true);
  if (!is_array($j) || empty($j['ips']) || !is_array($j['ips'])) {
    return [];
  }
  $out = [];
  foreach ($j['ips'] as $ip) {
    $ip = trim((string)$ip);
    if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $ip) && nbd_is_private_ipv4($ip)) {
      $out[] = $ip;
    }
  }
  return array_values(array_unique($out));
}

/**
 * Remember peer IPs that answered Scan (cap 32).
 */
function nbd_scan_remember_peers(array $ips) {
  nbd_ensure_runtime_dirs();
  $prev = nbd_scan_known_peers();
  $merged = [];
  foreach (array_merge($ips, $prev) as $ip) {
    $ip = trim((string)$ip);
    if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $ip) && nbd_is_private_ipv4($ip)) {
      $merged[$ip] = true;
    }
  }
  $list = array_slice(array_keys($merged), 0, 32);
  $path = NBDEXPORT_CFG_DIR . '/scan-peers.json';
  @file_put_contents($path, json_encode([
    'updated' => gmdate('c'),
    'ips' => $list,
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

/**
 * TCP connect probe.
 */
function nbd_tcp_open($ip, $port, $timeout_s = 0.2) {
  $errno = 0;
  $errstr = '';
  $fp = @fsockopen($ip, (int)$port, $errno, $errstr, (float)$timeout_s);
  if (is_resource($fp)) {
    fclose($fp);
    return true;
  }
  return false;
}

/**
 * Fetch peer beacon JSON if present.
 */
function nbd_fetch_beacon($ip, $port = null, $timeout_s = 0.35) {
  $port = $port === null ? nbd_beacon_port() : (int)$port;
  $url = 'http://' . $ip . ':' . $port . '/';
  $ctx = stream_context_create([
    'http' => [
      'method' => 'GET',
      'timeout' => $timeout_s,
      'header' => "Accept: application/json\r\nUser-Agent: NBDExport-scan\r\n",
      'ignore_errors' => true,
    ],
  ]);
  $raw = @file_get_contents($url, false, $ctx);
  if (!is_string($raw) || $raw === '') {
    return null;
  }
  $j = @json_decode($raw, true);
  if (!is_array($j) || ($j['plugin'] ?? '') !== 'NBDExport') {
    return null;
  }
  return $j;
}

/**
 * Optional qemu-img info for an NBD URL (size/format).
 */
function nbd_probe_nbd_info($url) {
  $tools = nbd_detect_tools();
  $img = $tools['qemu_img'] ?? '';
  if ($img === '') {
    return null;
  }
  $cmd = 'timeout 2 ' . escapeshellarg($img) . ' info --output=json '
    . escapeshellarg($url) . ' 2>/dev/null';
  $raw = @shell_exec($cmd);
  if (!is_string($raw) || $raw === '') {
    return null;
  }
  $j = @json_decode($raw, true);
  if (!is_array($j)) {
    return null;
  }
  return [
    'format' => (string)($j['format'] ?? ''),
    'virtual_size' => isset($j['virtual-size']) ? (int)$j['virtual-size'] : 0,
    'virtual_size_h' => isset($j['virtual-size']) ? nbd_format_bytes((int)$j['virtual-size']) : '',
  ];
}

/**
 * Parallel TCP probes via bash /dev/tcp (much faster than serial fsockopen).
 * @return string[] "ip:port" open endpoints
 */
function nbd_scan_tcp_parallel(array $ips, array $ports, $timeout_s = 0.15, $jobs = 64) {
  if (!$ips || !$ports) {
    return [];
  }
  $timeout_s = max(0.05, min(1.0, (float)$timeout_s));
  $jobs = max(8, min(128, (int)$jobs));
  $list = '';
  foreach ($ips as $ip) {
    foreach ($ports as $port) {
      $list .= $ip . ' ' . (int)$port . "\n";
    }
  }
  $tmp = NBDEXPORT_RUN . '/scan-targets.' . getmypid() . '.txt';
  @file_put_contents($tmp, $list);
  $script = 'while read -r ip port; do '
    . '(timeout ' . escapeshellarg((string)$timeout_s) . ' bash -c "echo >/dev/tcp/$ip/$port" 2>/dev/null '
    . '&& echo "$ip:$port") & '
    . 'while [ "$(jobs -r | wc -l)" -ge ' . (int)$jobs . ' ]; do sleep 0.02; done; '
    . 'done < ' . escapeshellarg($tmp) . '; wait';
  $out = [];
  @exec('bash -c ' . escapeshellarg($script) . ' 2>/dev/null', $out);
  @unlink($tmp);
  $open = [];
  foreach ($out as $line) {
    $line = trim($line);
    if (preg_match('/^(\d+\.\d+\.\d+\.\d+):(\d+)$/', $line, $m)) {
      $open[] = $m[1] . ':' . $m[2];
    }
  }
  return array_values(array_unique($open));
}

/**
 * Scan private LANs for NBD ports + optional plugin beacons.
 *
 * @param int[]|null $nbd_ports
 * @return array{ok:bool,subnets:string[],hits:array,seconds:float,error?:string}
 */
function nbd_scan_network(array $nbd_ports = null, $probe_info = true) {
  $t0 = microtime(true);
  nbd_ensure_runtime_dirs();
  if ($nbd_ports === null || !$nbd_ports) {
    $cfg = nbd_load_cfg();
    $base = (int)($cfg['default_port'] ?? 10809);
    if ($base < 1024 || $base > 65000) {
      $base = 10809;
    }
    $nbd_ports = [$base];
    for ($p = $base + 1; $p <= $base + 3 && $p < 65535; $p++) {
      $nbd_ports[] = $p;
    }
  }
  $nbd_ports = array_values(array_unique(array_map('intval', $nbd_ports)));
  $beacon_port = nbd_beacon_port();
  $probe_ports = array_values(array_unique(array_merge($nbd_ports, [$beacon_port])));

  $subnets = nbd_scan_subnets();
  if (!$subnets) {
    return ['ok' => false, 'error' => 'No private IPv4 subnets to scan', 'subnets' => [], 'hits' => [], 'seconds' => 0];
  }

  $self_ips = [];
  foreach (nbd_list_bind_ips() as $row) {
    if (!empty($row['ip'])) {
      $self_ips[$row['ip']] = true;
    }
  }

  $ips = [];
  foreach ($subnets as $cidr) {
    if (!preg_match('#^(\d+\.\d+\.\d+)\.0/24$#', $cidr, $m)) {
      continue;
    }
    $prefix = $m[1];
    for ($i = 1; $i <= 254; $i++) {
      $ip = $prefix . '.' . $i;
      if (isset($self_ips[$ip])) {
        continue;
      }
      $ips[] = $ip;
    }
  }
  // Always re-probe remembered peers (even if not on a scanned /24)
  foreach (nbd_scan_known_peers() as $ip) {
    if (!isset($self_ips[$ip])) {
      $ips[] = $ip;
    }
  }
  $ips = array_values(array_unique($ips));

  $open = nbd_scan_tcp_parallel($ips, $probe_ports, 0.15, 80);
  $by_ip = [];
  foreach ($open as $ep) {
    list($ip, $port) = explode(':', $ep, 2);
    $port = (int)$port;
    if (!isset($by_ip[$ip])) {
      $by_ip[$ip] = ['nbd' => [], 'beacon' => false];
    }
    if ($port === $beacon_port) {
      $by_ip[$ip]['beacon'] = true;
    } elseif (in_array($port, $nbd_ports, true)) {
      $by_ip[$ip]['nbd'][] = $port;
    }
  }

  $hits = [];
  foreach ($by_ip as $ip => $info) {
    $beacon = null;
    if (!empty($info['beacon'])) {
      $beacon = nbd_fetch_beacon($ip, $beacon_port, 0.4);
    }
    // Also try beacon if only NBD open (server might listen beacon on same host)
    if (!$beacon && !empty($info['nbd'])) {
      $beacon = nbd_fetch_beacon($ip, $beacon_port, 0.25);
    }

    $exports = [];
    if (is_array($beacon) && !empty($beacon['exports']) && is_array($beacon['exports'])) {
      foreach ($beacon['exports'] as $ex) {
        $url = (string)($ex['url'] ?? '');
        if ($url === '' && !empty($ex['port'])) {
          $bind = (string)($ex['bind'] ?? $ip);
          $url = 'nbd://' . $bind . ':' . (int)$ex['port'];
        }
        $inf = ($probe_info && $url !== '') ? nbd_probe_nbd_info($url) : null;
        $exports[] = [
          'url' => $url,
          'port' => (int)($ex['port'] ?? 0),
          'read_only' => array_key_exists('read_only', $ex) ? !empty($ex['read_only']) : null,
          'label' => (string)($ex['label'] ?? ''),
          'device_name' => (string)($ex['device_name'] ?? ''),
          'info' => $inf,
        ];
      }
    } else {
      foreach ($info['nbd'] as $port) {
        $url = 'nbd://' . $ip . ':' . $port;
        $inf = $probe_info ? nbd_probe_nbd_info($url) : null;
        $exports[] = [
          'url' => $url,
          'port' => $port,
          'read_only' => null,
          'label' => '',
          'device_name' => '',
          'info' => $inf,
        ];
      }
    }

    if (!$exports && !is_array($beacon)) {
      continue;
    }

    $hits[] = [
      'ip' => $ip,
      'kind' => is_array($beacon) ? 'peer' : 'nbd_open',
      'hostname' => is_array($beacon) ? (string)($beacon['hostname'] ?? '') : '',
      'version' => is_array($beacon) ? (string)($beacon['version'] ?? '') : '',
      'plugin' => is_array($beacon) ? (string)($beacon['plugin'] ?? '') : '',
      'beacon' => is_array($beacon),
      'exports' => $exports,
    ];
  }

  usort($hits, function ($a, $b) {
    if (($a['kind'] === 'peer') !== ($b['kind'] === 'peer')) {
      return ($a['kind'] === 'peer') ? -1 : 1;
    }
    return strcmp($a['ip'], $b['ip']);
  });

  if ($hits) {
    nbd_scan_remember_peers(array_map(function ($h) {
      return $h['ip'] ?? '';
    }, $hits));
  }

  return [
    'ok' => true,
    'subnets' => $subnets,
    'nbd_ports' => $nbd_ports,
    'beacon_port' => $beacon_port,
    'hits' => $hits,
    'seconds' => round(microtime(true) - $t0, 2),
  ];
}
