<?php
/**
 * NBD Export — core helpers (no hard require of ThunderboltNet / UnraidFRR).
 */

if (!defined('NBDEXPORT_ROOT')) {
  define('NBDEXPORT_ROOT', '/usr/local/emhttp/plugins/NbdExport');
}
if (!defined('NBDEXPORT_CFG_DIR')) {
  define('NBDEXPORT_CFG_DIR', '/boot/config/plugins/NbdExport');
}
if (!defined('NBDEXPORT_RUN')) {
  define('NBDEXPORT_RUN', '/var/run/nbdexport');
}
if (!defined('NBDEXPORT_LOG')) {
  define('NBDEXPORT_LOG', '/var/log/nbdexport');
}

function nbd_cfg_path() {
  return NBDEXPORT_CFG_DIR . '/NbdExport.cfg';
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
    'rehydrate_on_start' => 'no',
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
  $keys = ['enabled', 'default_read_only', 'default_port', 'allow_bind_all', 'rehydrate_on_start'];
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

function nbd_plugin_version() {
  $plg = NBDEXPORT_ROOT . '/nbdexport.plg';
  if (is_file($plg)) {
    $t = @file_get_contents($plg);
    if (is_string($t) && preg_match('/ENTITY version "([^"]+)"/', $t, $m)) {
      return $m[1];
    }
  }
  // Dev tree
  $dev = dirname(__DIR__) . '/nbdexport.plg';
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
  @exec('ip -4 -o addr show scope global 2>/dev/null', $out);
  foreach ($out as $line) {
    // 2: eth0    inet 192.168.1.3/24 ...
    if (!preg_match('/^\d+:\s+(\S+)\s+inet\s+(\d+\.\d+\.\d+\.\d+)/', $line, $m)) {
      continue;
    }
    $if = $m[1];
    // strip @if suffixes
    $if = preg_replace('/@.*$/', '', $if);
    $ip = $m[2];
    if (isset($seen[$ip])) {
      continue;
    }
    $seen[$ip] = true;
    $is_tb = (bool)preg_match('/^thunderbolt\d+$/', $if);
    $priv = nbd_is_private_ipv4($ip);
    $rows[] = [
      'ip' => $ip,
      'iface' => $if,
      'preferred' => $is_tb && $priv,
      'private' => $priv,
      'label' => $ip . ' (' . $if . ($is_tb ? ', Thunderbolt' : '') . ')',
    ];
  }
  usort($rows, function ($a, $b) {
    if ($a['preferred'] !== $b['preferred']) {
      return $a['preferred'] ? -1 : 1;
    }
    if ($a['private'] !== $b['private']) {
      return $a['private'] ? -1 : 1;
    }
    return strcmp($a['iface'], $b['iface']);
  });
  return $rows;
}

function nbd_thunderboltnet_present() {
  return is_dir('/usr/local/emhttp/plugins/ThunderboltNet')
    || is_dir('/boot/config/plugins/ThunderboltNet');
}

function nbd_unraidfrr_present() {
  return is_dir('/usr/local/emhttp/plugins/UnraidFRR')
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
 * Start RO (or RW) qemu-nbd export.
 *
 * @return array{ok:bool,error?:string,id?:string}
 */
function nbd_export_start($device, $bind, $port, $read_only = true, $label = '', $shared = 2) {
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
  // also kill any qemu-nbd matching state bind/port
  if (is_file($statefile)) {
    $j = @json_decode((string)@file_get_contents($statefile), true);
    if (is_array($j) && !empty($j['port'])) {
      @exec('pkill -f ' . escapeshellarg('qemu-nbd.*--port=' . (int)$j['port']) . ' 2>/dev/null || true');
    }
  }
  @unlink($pidfile);
  @unlink($statefile);
  return ['ok' => true];
}

function nbd_stop_all_exports() {
  foreach (nbd_exports_state() as $e) {
    if (!empty($e['id'])) {
      nbd_export_stop($e['id']);
    }
  }
  // sweep leftover pid/json
  foreach (glob(NBDEXPORT_RUN . '/*') ?: [] as $f) {
    @unlink($f);
  }
}

function nbd_jobs_state() {
  nbd_ensure_runtime_dirs();
  $list = [];
  foreach (glob(NBDEXPORT_RUN . '/job-*.json') ?: [] as $f) {
    $raw = @file_get_contents($f);
    $j = is_string($raw) ? @json_decode($raw, true) : null;
    if (!is_array($j) || empty($j['id'])) {
      continue;
    }
    $pid = isset($j['pid']) ? (int)$j['pid'] : 0;
    $j['alive'] = $pid > 0 && @file_exists('/proc/' . $pid);
    if (!$j['alive'] && empty($j['finished'])) {
      // try to parse log for completion
      $log = $j['log'] ?? '';
      if ($log && is_file($log)) {
        $tail = @file_get_contents($log);
        if (is_string($tail) && (strpos($tail, 'NBD_JOB_OK') !== false || strpos($tail, 'NBD_JOB_FAIL') !== false)) {
          $j['finished'] = true;
          $j['ok'] = strpos($tail, 'NBD_JOB_OK') !== false;
        }
      }
    }
    // progress: dest size if present
    if (!empty($j['output']) && is_file($j['output'])) {
      $j['output_size'] = filesize($j['output']);
      $j['output_size_h'] = nbd_format_bytes($j['output_size']);
    }
    $list[] = $j;
  }
  usort($list, function ($a, $b) {
    return strcmp($b['started'] ?? '', $a['started'] ?? '');
  });
  return $list;
}

/**
 * Background qemu-img convert from nbd:// to local path.
 */
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
  $url = trim((string)$url);
  $output = trim((string)$output);
  $format = strtolower(trim((string)$format));
  if (!in_array($format, ['qcow2', 'raw'], true)) {
    return ['ok' => false, 'error' => 'Format must be qcow2 or raw.'];
  }
  if (!preg_match('#^nbd://[A-Za-z0-9.:\[\]%-]+#', $url)) {
    return ['ok' => false, 'error' => 'URL must start with nbd://'];
  }
  if ($output === '' || strpos($output, '..') !== false) {
    return ['ok' => false, 'error' => 'Invalid output path.'];
  }
  // Prefer under /mnt/
  if (strpos($output, '/mnt/') !== 0 && strpos($output, '/tmp/') !== 0) {
    return ['ok' => false, 'error' => 'Output must be under /mnt/ (cache/array/share) or /tmp/.'];
  }
  $dir = dirname($output);
  if (!is_dir($dir)) {
    if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
      return ['ok' => false, 'error' => 'Cannot create directory: ' . $dir];
    }
  }

  $id = 'job-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
  $pidfile = NBDEXPORT_RUN . '/' . $id . '.pid';
  $statefile = NBDEXPORT_RUN . '/' . $id . '.json';
  $logfile = NBDEXPORT_LOG . '/' . $id . '.log';
  $script = NBDEXPORT_RUN . '/' . $id . '.sh';

  $sh = "#!/bin/bash\nset -euo pipefail\n"
    . 'LOG=' . escapeshellarg($logfile) . "\n"
    . 'SRC=' . escapeshellarg($url) . "\n"
    . 'OUT=' . escapeshellarg($output) . "\n"
    . 'IMG=' . escapeshellarg($tools['qemu_img']) . "\n"
    . 'FMT=' . escapeshellarg($format) . "\n"
    . 'echo "$(date -Iseconds) job start" >>"$LOG"' . "\n"
    . 'for i in $(seq 1 60); do' . "\n"
    . '  if "$IMG" info "$SRC" >>"$LOG" 2>&1; then break; fi' . "\n"
    . '  sleep 5' . "\n"
    . '  if [ "$i" -eq 60 ]; then echo NBD_JOB_FAIL wait_src >>"$LOG"; exit 1; fi' . "\n"
    . 'done' . "\n"
    . '"$IMG" convert -p -f raw -O "$FMT" -t writeback -W "$SRC" "$OUT" >>"$LOG" 2>&1' . "\n"
    . '"$IMG" check "$OUT" >>"$LOG" 2>&1 || true' . "\n"
    . '"$IMG" info "$OUT" >>"$LOG" 2>&1' . "\n"
    . 'echo NBD_JOB_OK >>"$LOG"' . "\n"
    . 'echo "$(date -Iseconds) job done" >>"$LOG"' . "\n";

  @file_put_contents($script, $sh);
  @chmod($script, 0755);
  @file_put_contents($logfile, date('c') . " prepared\n");

  $full = 'setsid nohup bash ' . escapeshellarg($script) . ' >/dev/null 2>&1 & echo $! >' . escapeshellarg($pidfile);
  exec($full);
  usleep(200000);
  $pid = (int)@file_get_contents($pidfile);

  $state = [
    'id' => $id,
    'url' => $url,
    'output' => $output,
    'format' => $format,
    'pid' => $pid,
    'started' => date('c'),
    'log' => $logfile,
  ];
  @file_put_contents($statefile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
  return ['ok' => true, 'id' => $id];
}

function nbd_image_stop($id) {
  $id = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$id);
  if ($id === '' || strpos($id, 'job-') !== 0) {
    return ['ok' => false, 'error' => 'Invalid job id'];
  }
  $pidfile = NBDEXPORT_RUN . '/' . $id . '.pid';
  $pid = is_file($pidfile) ? (int)@file_get_contents($pidfile) : 0;
  if ($pid > 0 && @file_exists('/proc/' . $pid)) {
    // kill process group if possible
    @posix_kill($pid, 15);
    usleep(200000);
    if (@file_exists('/proc/' . $pid)) {
      @posix_kill($pid, 9);
    }
  }
  @exec('pkill -f ' . escapeshellarg($id . '.sh') . ' 2>/dev/null || true');
  return ['ok' => true];
}

function nbd_write_companion_marker() {
  nbd_ensure_runtime_dirs();
  $m = [
    'plugin' => 'NbdExport',
    'provides' => ['nbd-export', 'qemu-nbd'],
    'version' => nbd_plugin_version(),
  ];
  @file_put_contents(NBDEXPORT_CFG_DIR . '/companion.json', json_encode($m, JSON_PRETTY_PRINT) . "\n");
}

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
    'unraidfrr' => nbd_unraidfrr_present(),
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
