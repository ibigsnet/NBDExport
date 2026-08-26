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
 * Colors: Running orange, Done green, Failed red, Idle grey, Queued blue.
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
      'class' => 'nbd-badge-rw',
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
    return ['key' => 'failed', 'label' => 'Failed', 'class' => 'nbd-badge-bad', 'hint' => 'See log tail'];
  }
  // Process gone without a finish marker still means the job ended (usually error).
  if (!$alive && !empty($j['pid']) && $status !== 'queued') {
    return ['key' => 'failed', 'label' => 'Failed', 'class' => 'nbd-badge-bad', 'hint' => 'Process exited — see log tail'];
  }
  return ['key' => 'idle', 'label' => 'Idle', 'class' => 'nbd-badge-stale', 'hint' => 'Not running'];
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
 * Latest Pull progress percent (0–100) from .progress sidecar or log, or null.
 */
function nbd_job_progress_pct(array $j) {
  $id = (string)($j['id'] ?? '');
  if ($id !== '') {
    $pf = NBDEXPORT_RUN . '/' . $id . '.progress';
    if (is_file($pf)) {
      $raw = trim((string)@file_get_contents($pf));
      if (preg_match('/(\d+(?:\.\d+)?)\s*\/\s*100/', $raw, $m) || preg_match('/^(\d+(?:\.\d+)?)$/', $raw, $m)) {
        return (float)$m[1];
      }
    }
  }
  $log = (string)($j['log'] ?? '');
  if ($log === '' || !is_file($log)) {
    return null;
  }
  // Read tail only — full logs can be huge with old percent spam
  $fh = @fopen($log, 'rb');
  if (!$fh) {
    return null;
  }
  $size = @filesize($log);
  if ($size > 8192) {
    @fseek($fh, -8192, SEEK_END);
  }
  $tail = (string)@stream_get_contents($fh);
  @fclose($fh);
  if (!preg_match_all('/\((\d+(?:\.\d+)?)\/100%\)/', $tail, $mm) || empty($mm[1])) {
    return null;
  }
  return (float)end($mm[1]);
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

function nbd_format_bytes($n) {
  $n = (float)$n;
  if ($n <= 0) {
    return '0 B';
  }
  $u = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
  $i = 0;
  while ($n >= 1024 && $i < count($u) - 1) {
    $n /= 1024;
    $i++;
  }
  $fmt = $i === 0 ? (string)(int)$n : rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
  return $fmt . ' ' . $u[$i];
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
          }
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
  $queued = [];
  foreach (nbd_jobs_state() as $j) {
    if (($j['status'] ?? '') === 'queued' && empty($j['finished'])) {
      $queued[] = $j;
    }
  }
  // Oldest first
  usort($queued, function ($a, $b) {
    return strcmp($a['queued_at'] ?? $a['started'] ?? '', $b['queued_at'] ?? $b['started'] ?? '');
  });
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
  // Progress: qemu-img -p uses CR updates. We turn CR→NL, log only when integer %
  // changes (timestamped history), and keep .progress as the latest field for UI.
  $sh = "#!/bin/bash\nset -uo pipefail\n"
    . 'LOG=' . escapeshellarg($logfile) . "\n"
    . 'PROG=' . escapeshellarg($progressfile) . "\n"
    . 'SRC=' . escapeshellarg($url) . "\n"
    . 'OUT=' . escapeshellarg($output) . "\n"
    . 'IMG=' . escapeshellarg($tools['qemu_img']) . "\n"
    . 'FMT=' . escapeshellarg($format) . "\n"
    . 'NICE_N=' . (int)$nice . "\n"
    . 'IONICE_ARGS=' . escapeshellarg($ionice_args) . "\n"
    . 'fail() { echo "NBD_JOB_FAIL $*" >>"$LOG"; echo "$(date -Iseconds) job failed: $*" >>"$LOG"; exit 1; }' . "\n"
    . 'if command -v ionice >/dev/null 2>&1; then WRAP=(ionice $IONICE_ARGS nice -n "$NICE_N"); else WRAP=(nice -n "$NICE_N"); fi' . "\n"
    . 'run_img() { "${WRAP[@]}" "$@"; }' . "\n"
    . 'echo "$(date -Iseconds) job start type=' . $source_type . ' wrap=${WRAP[*]}" >>"$LOG"' . "\n"
    . 'if [[ "$SRC" == nbd://* ]]; then' . "\n"
    . '  for i in $(seq 1 60); do' . "\n"
    . '    if run_img "$IMG" info "$SRC" >>"$LOG" 2>&1; then break; fi' . "\n"
    . '    sleep 5' . "\n"
    . '    if [ "$i" -eq 60 ]; then fail wait_src; fi' . "\n"
    . '  done' . "\n"
    . 'else' . "\n"
    . '  run_img "$IMG" info -f raw "$SRC" >>"$LOG" 2>&1 || run_img "$IMG" info "$SRC" >>"$LOG" 2>&1 || true' . "\n"
    . 'fi' . "\n"
    . 'LAST_PCT=-1' . "\n"
    . 'set +e' . "\n"
    . 'if [[ "$SRC" == nbd://* ]]; then SRC_FMT=raw; else SRC_FMT=raw; fi' . "\n"
    . 'run_img "$IMG" convert -p -f "$SRC_FMT" -O "$FMT" -t writeback -W "$SRC" "$OUT" 2> >(tr "\\r" "\\n" | while IFS= read -r line; do' . "\n"
    . '  case "$line" in' . "\n"
    . '    *"/100%)"*)' . "\n"
    . '      pct="${line#*(}"; pct="${pct%%/*}"' . "\n"
    . '      ipct=${pct%%.*}' . "\n"
    . '      echo "$line" >"$PROG"' . "\n"
    . '      if [ "$ipct" != "$LAST_PCT" ]; then' . "\n"
    . '        echo "$(date -Iseconds) progress ($ipct/100%)" >>"$LOG"' . "\n"
    . '        LAST_PCT=$ipct' . "\n"
    . '      fi' . "\n"
    . '      ;;' . "\n"
    . '    "") ;;' . "\n"
    . '    *) echo "$(date -Iseconds) $line" >>"$LOG" ;;' . "\n"
    . '  esac' . "\n"
    . 'done)' . "\n"
    . 'CONV_RC=$?' . "\n"
    . 'set -e' . "\n"
    . 'if [ "${CONV_RC}" -ne 0 ]; then fail convert; fi' . "\n"
    . 'echo "$(date -Iseconds) progress (100/100%)" >>"$LOG"' . "\n"
    . 'echo "(100.00/100%)" >"$PROG"' . "\n"
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
