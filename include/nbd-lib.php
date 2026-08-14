<?php
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

function nbd_memory_remember_host($device, $bind, $port, $read_only, $label) {
  $mem = nbd_memory_load();
  $mem['last_host'] = [
    'device' => (string)$device,
    'bind' => (string)$bind,
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
 * Job status for UI: running | done | failed | idle
 */
function nbd_job_ui_status(array $j) {
  $alive = !empty($j['alive']);
  $fin = !empty($j['finished']);
  $ok = !empty($j['ok']);
  if ($alive) {
    return ['key' => 'running', 'label' => 'Running', 'class' => 'nbd-badge-ok', 'hint' => 'qemu-img convert in progress'];
  }
  if ($fin && $ok) {
    return ['key' => 'done', 'label' => 'Done', 'class' => 'nbd-badge-ok', 'hint' => 'Finished successfully'];
  }
  if ($fin && !$ok) {
    return ['key' => 'failed', 'label' => 'Failed', 'class' => 'nbd-badge-bad', 'hint' => 'See log tail'];
  }
  // Process gone without a finish marker still means the job ended (usually error).
  // Prefer Failed over Idle so the UI does not look like "never started".
  if (!$alive && !empty($j['pid'])) {
    return ['key' => 'failed', 'label' => 'Failed', 'class' => 'nbd-badge-bad', 'hint' => 'Process exited — see log tail'];
  }
  return ['key' => 'idle', 'label' => 'Idle', 'class' => 'nbd-badge-stale', 'hint' => 'Not running'];
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
 * The include is a no-op unless ud_status_overlay=yes and the user is on UD.
 * Marker comments make uninstall reliable (same idea as Storage Guard inject).
 */
function nbd_ud_overlay_inject() {
  $marker = 'NBDExport UD overlay';
  $line = '<?php @include \'/usr/local/emhttp/plugins/NBDExport/include/nbd-ud-head.php\'; /* ' . $marker . ' */ ?>';
  $candidates = [
    '/usr/local/emhttp/webGui/include/DefaultPageLayout/HeadInlineJS.php',
    '/usr/local/emhttp/plugins/dynamix/include/DefaultPageLayout/HeadInlineJS.php',
  ];
  $ok = false;
  foreach ($candidates as $path) {
    if (!is_file($path) || !is_writable($path)) {
      continue;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw)) {
      continue;
    }
    if (strpos($raw, $marker) !== false) {
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
  $marker = 'NBDExport UD overlay';
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
    if (!is_string($raw) || strpos($raw, $marker) === false) {
      continue;
    }
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $out = [];
    foreach ($lines as $ln) {
      if (strpos($ln, $marker) !== false) {
        continue;
      }
      $out[] = $ln;
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
  // also kill any qemu-nbd matching state bind/port
  if (is_file($statefile)) {
    $j = @json_decode((string)@file_get_contents($statefile), true);
    if (is_array($j) && !empty($j['port'])) {
      @exec('pkill -f ' . escapeshellarg('qemu-nbd.*--port=' . (int)$j['port']) . ' 2>/dev/null || true');
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
  foreach (glob(NBDEXPORT_RUN . '/job-*.json') ?: [] as $f) {
    $raw = @file_get_contents($f);
    $j = is_string($raw) ? @json_decode($raw, true) : null;
    if (!is_array($j) || empty($j['id'])) {
      continue;
    }
    $pid = isset($j['pid']) ? (int)$j['pid'] : 0;
    $j['alive'] = $pid > 0 && @file_exists('/proc/' . $pid);
    if (!$j['alive'] && empty($j['finished'])) {
      // Process exited: parse log, or treat as failed if no success marker
      // (qemu-img convert under set -e often dies without writing NBD_JOB_FAIL).
      $log = $j['log'] ?? '';
      $tail = ($log && is_file($log)) ? (string)@file_get_contents($log) : '';
      $j['finished'] = true;
      if (strpos($tail, 'NBD_JOB_OK') !== false) {
        $j['ok'] = true;
      } else {
        $j['ok'] = false;
        if (strpos($tail, 'NBD_JOB_FAIL') === false && $tail !== '') {
          // Leave breadcrumb for log UI
          @file_put_contents($log, "\nNBD_JOB_FAIL process_exited\n", FILE_APPEND);
        }
      }
      $j['finished_at'] = date('c');
      // Persist so UI/poller see a stable terminal state
      $persist = $j;
      unset($persist['alive'], $persist['output_size'], $persist['output_size_h']);
      @file_put_contents($f, json_encode($persist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
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
 * Compact live snapshot for WebUI polling (in-place badge updates).
 * @return array{exports:array,jobs:array,watch:bool,live_exports:int,live_jobs:int}
 */
function nbd_live_snapshot() {
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
  foreach (nbd_jobs_state() as $j) {
    $st = nbd_job_ui_status($j);
    $key = $st['key'] ?? 'idle';
    if ($key === 'running') {
      $live_jobs++;
      $watch = true;
    }
    $log_tail = '';
    if (!empty($j['log']) && is_file($j['log']) && ($key === 'failed' || $key === 'done' || $key === 'running')) {
      $log_tail = nbd_log_tail($j['log'], 6);
    }
    $jobs[] = [
      'id' => (string)($j['id'] ?? ''),
      'key' => $key,
      'label' => (string)($st['label'] ?? $key),
      'class' => (string)($st['class'] ?? 'nbd-badge-stale'),
      'hint' => (string)($st['hint'] ?? ''),
      'alive' => !empty($j['alive']),
      'finished' => !empty($j['finished']),
      'ok' => !empty($j['ok']),
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
    'ts' => time(),
  ];
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
  // Never write image jobs onto block devices (wipe risk)
  if (preg_match('#^/dev/#', $output) || (function_exists('is_block') && @is_block($output))) {
    return ['ok' => false, 'error' => 'Output cannot be a block device (/dev/…). Use a file under /mnt/ (e.g. qcow2 on cache).'];
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

  $sh = "#!/bin/bash\nset -uo pipefail\n"
    . 'LOG=' . escapeshellarg($logfile) . "\n"
    . 'SRC=' . escapeshellarg($url) . "\n"
    . 'OUT=' . escapeshellarg($output) . "\n"
    . 'IMG=' . escapeshellarg($tools['qemu_img']) . "\n"
    . 'FMT=' . escapeshellarg($format) . "\n"
    . 'fail() { echo "NBD_JOB_FAIL $*" >>"$LOG"; echo "$(date -Iseconds) job failed: $*" >>"$LOG"; exit 1; }' . "\n"
    . 'echo "$(date -Iseconds) job start" >>"$LOG"' . "\n"
    . 'for i in $(seq 1 60); do' . "\n"
    . '  if "$IMG" info "$SRC" >>"$LOG" 2>&1; then break; fi' . "\n"
    . '  sleep 5' . "\n"
    . '  if [ "$i" -eq 60 ]; then fail wait_src; fi' . "\n"
    . 'done' . "\n"
    . 'if ! "$IMG" convert -p -f raw -O "$FMT" -t writeback -W "$SRC" "$OUT" >>"$LOG" 2>&1; then' . "\n"
    . '  fail convert' . "\n"
    . 'fi' . "\n"
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
    'plugin' => 'NBDExport',
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
