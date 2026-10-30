<?php
/** NBD Export 2026.10.30aa */
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
if (!defined('NBDEXPORT_DISKS_INI')) {
  define('NBDEXPORT_DISKS_INI', '/var/local/emhttp/disks.ini');
}
if (!defined('NBDEXPORT_SYS_BLOCK')) {
  define('NBDEXPORT_SYS_BLOCK', '/sys/class/block');
}
if (!defined('NBDEXPORT_ROOTFS')) {
  define('NBDEXPORT_ROOTFS', '/');
}
if (!defined('NBDEXPORT_MNT')) {
  define('NBDEXPORT_MNT', '/mnt'); // Unraid disks, pools and shares (tests point this elsewhere)
}
if (!defined('NBDEXPORT_HOOK')) {
  define('NBDEXPORT_HOOK', NBDEXPORT_ROOT . '/include/nbd-hook.php');
}

function nbd_cfg_path() {
  return NBDEXPORT_CFG_DIR . '/NBDExport.cfg';
}

function nbd_default_cfg_path() {
  return NBDEXPORT_ROOT . '/default.cfg';
}

/** Keys persisted to NBDExport.cfg (write + import use the same list). */
function nbd_cfg_keys() {
  return [
    'enabled', 'default_read_only', 'default_port', 'allow_bind_all', 'allow_public_bind',
    'destructive_mode', 'scan_extra_subnets', 'ud_status_overlay',
    'max_concurrent_pulls', 'pull_io_class', 'pull_nice', 'allow_upgrade_while_busy',
    'notify_pull_done', 'notify_pull_failed', 'notify_host_down',
  ];
}

function nbd_cfg_defaults() {
  return [
    'enabled' => 'yes',
    'default_read_only' => 'yes',
    'default_port' => '10809',
    'allow_bind_all' => 'no',
    // Public (non-private) bind IPs need this opt-in
    'allow_public_bind' => 'no',
    'destructive_mode' => 'no',
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
    'notify_pull_done' => 'off',
    'notify_pull_failed' => 'warning',
    'notify_host_down' => 'off',
  ];
}

/**
 * Normalize cfg values (yes/no switches, port, pull limits, notify levels).
 */
function nbd_cfg_normalize(array $cfg) {
  foreach (['enabled', 'default_read_only', 'allow_bind_all', 'allow_public_bind', 'destructive_mode',
    'ud_status_overlay', 'allow_upgrade_while_busy'] as $k) {
    if (isset($cfg[$k])) {
      $cfg[$k] = ($cfg[$k] === 'yes') ? 'yes' : 'no';
    }
  }
  $dp = (int)($cfg['default_port'] ?? 0);
  $cfg['default_port'] = ($dp < 1024 || $dp > 65535) ? '10809' : (string)$dp;
  $cfg['max_concurrent_pulls'] = (string)max(1, min(4, (int)($cfg['max_concurrent_pulls'] ?? 1)));
  $cfg['pull_io_class'] = (strtolower((string)($cfg['pull_io_class'] ?? 'idle')) === 'best-effort') ? 'best-effort' : 'idle';
  $cfg['pull_nice'] = (string)max(0, min(19, (int)($cfg['pull_nice'] ?? 10)));
  $npd = strtolower((string)($cfg['notify_pull_done'] ?? 'off'));
  $cfg['notify_pull_done'] = ($npd === 'normal') ? 'normal' : 'off';
  $npf = strtolower((string)($cfg['notify_pull_failed'] ?? 'warning'));
  $cfg['notify_pull_failed'] = in_array($npf, ['warning', 'alert'], true) ? $npf : 'off';
  $nhd = strtolower((string)($cfg['notify_host_down'] ?? 'off'));
  $cfg['notify_host_down'] = in_array($nhd, ['warning', 'alert'], true) ? $nhd : 'off';
  if (isset($cfg['scan_extra_subnets'])) {
    $cfg['scan_extra_subnets'] = preg_replace('/[^0-9.\/,; ]/', '', (string)$cfg['scan_extra_subnets']);
  }
  return $cfg;
}

/**
 * Load plugin cfg (key="value" lines).
 */
function nbd_load_cfg() {
  $defaults = nbd_cfg_defaults();
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

/**
 * Write a file via temp + rename. With $only_if_changed, skip when content is identical
 * (keeps USB flash writes down).
 *
 * @return bool true when the file holds $data afterwards
 */
function nbd_atomic_write($path, $data, $only_if_changed = false) {
  $data = (string)$data;
  if ($only_if_changed && is_file($path) && !is_link($path)) {
    $cur = @file_get_contents($path);
    if (is_string($cur) && $cur === $data) {
      return true;
    }
  }
  $dir = dirname($path);
  if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
  }
  $tmp = $dir . '/.' . basename($path) . '.' . getmypid() . '.' . bin2hex(random_bytes(3)) . '.tmp';
  if (@file_put_contents($tmp, $data) === false) {
    @unlink($tmp);
    return false;
  }
  @chmod($tmp, 0644);
  if (!@rename($tmp, $path)) {
    @unlink($tmp);
    return false;
  }
  return true;
}

function nbd_write_cfg(array $cfg) {
  $cfg = nbd_cfg_normalize($cfg);
  $lines = ['; NBD Export — written by plugin', ''];
  foreach (nbd_cfg_keys() as $k) {
    $v = isset($cfg[$k]) ? (string)$cfg[$k] : (string)(nbd_cfg_defaults()[$k] ?? '');
    $v = str_replace(['\\', '"'], ['\\\\', '\\"'], $v);
    $lines[] = $k . '="' . $v . '"';
  }
  return nbd_atomic_write(nbd_cfg_path(), implode("\n", $lines) . "\n", true);
}

/** RAM runtime dirs only (flash dir is created by writers that need it). */
function nbd_ensure_runtime_dirs() {
  foreach ([NBDEXPORT_RUN, NBDEXPORT_LOG] as $d) {
    if (!is_dir($d)) {
      @mkdir($d, 0755, true);
    }
  }
}

/* ── Commands, locking, processes ──────────────────────────────────────── */

/**
 * Run a read-only helper command (lsblk, ip, findmnt, ss…) and return stdout.
 * Tests replace it via $GLOBALS['NBD_TEST_RUN'].
 */
function nbd_run($cmd) {
  if (isset($GLOBALS['NBD_TEST_RUN']) && is_callable($GLOBALS['NBD_TEST_RUN'])) {
    return (string)call_user_func($GLOBALS['NBD_TEST_RUN'], (string)$cmd);
  }
  return (string)@shell_exec((string)$cmd);
}

/** @return string[] non-empty output lines of nbd_run() */
function nbd_run_lines($cmd) {
  $out = [];
  foreach (preg_split('/\r\n|\r|\n/', nbd_run($cmd)) as $l) {
    if (trim($l) !== '') {
      $out[] = $l;
    }
  }
  return $out;
}

function nbd_lock_path() {
  return NBDEXPORT_RUN . '/state.lock';
}

/**
 * Run $fn while holding the plugin state lock (flock on RUN/state.lock).
 * Re-entrant inside one PHP process. Every Host/Pull state transition goes through here.
 */
function nbd_locked(callable $fn) {
  static $depth = 0;
  static $fh = null;
  if ($depth > 0) {
    $depth++;
    try {
      return $fn();
    } finally {
      $depth--;
    }
  }
  nbd_ensure_runtime_dirs();
  $fh = @fopen(nbd_lock_path(), 'c');
  if ($fh) {
    @flock($fh, LOCK_EX);
  }
  $depth = 1;
  try {
    return $fn();
  } finally {
    $depth = 0;
    if ($fh) {
      @flock($fh, LOCK_UN);
      @fclose($fh);
    }
    $fh = null;
  }
}

/**
 * Shell prefix that closes any inherited fd on the lock file, so a background
 * child (qemu-nbd, Pull wrapper, beacon) never keeps the state lock held.
 */
function nbd_spawn_prefix() {
  $p = '';
  $lock = nbd_lock_path();
  foreach (glob('/proc/self/fd/*') ?: [] as $l) {
    $n = basename($l);
    if (ctype_digit($n) && (int)$n > 2 && @readlink($l) === $lock) {
      $p .= 'exec ' . $n . '>&- ; ';
    }
  }
  return $p;
}

/** Start a detached background command (setsid nohup … &), lock fd closed first. */
function nbd_spawn($cmd) {
  exec(nbd_spawn_prefix() . $cmd);
}

/** @return string[] argv of a live process ([] if gone / unreadable) */
function nbd_proc_cmdline($pid) {
  $pid = (int)$pid;
  if ($pid <= 1) {
    return [];
  }
  $raw = @file_get_contents('/proc/' . $pid . '/cmdline');
  if (!is_string($raw) || $raw === '') {
    return [];
  }
  return explode("\0", rtrim($raw, "\0"));
}

/** Send a signal (posix_kill when available, else kill(1)). Negative PID = process group. */
function nbd_signal($pid, $sig) {
  $pid = (int)$pid;
  $sig = (int)$sig;
  if ($pid === 0 || $pid === 1 || $pid === -1) {
    return false;
  }
  if (function_exists('posix_kill')) {
    return @posix_kill($pid, $sig);
  }
  $rc = 1;
  $o = [];
  @exec('kill -' . $sig . ' -- ' . $pid . ' 2>/dev/null', $o, $rc);
  return $rc === 0;
}

function nbd_pid_alive($pid) {
  $pid = (int)$pid;
  return $pid > 1 && @file_exists('/proc/' . $pid);
}

/**
 * qemu-nbd process check; with $bind/$port, also requires those exact args.
 */
function nbd_pid_is_qemu_nbd($pid, $bind = null, $port = null) {
  $a = nbd_proc_cmdline($pid);
  if (!$a || basename($a[0]) !== 'qemu-nbd') {
    return false;
  }
  if ($bind !== null && !in_array('--bind=' . $bind, $a, true)) {
    return false;
  }
  if ($port !== null && !in_array('--port=' . (int)$port, $a, true)) {
    return false;
  }
  return true;
}

function nbd_pid_is_qemu_img_convert($pid) {
  $a = nbd_proc_cmdline($pid);
  return $a && basename($a[0]) === 'qemu-img' && in_array('convert', $a, true);
}

/** Our bash wrapper running $script (Pull job or Host wrapper). */
function nbd_pid_is_wrapper($pid, $script) {
  $a = nbd_proc_cmdline($pid);
  return count($a) >= 2 && basename($a[0]) === 'bash' && $a[1] === $script;
}

/** php -S beacon started by this plugin. */
function nbd_pid_is_beacon($pid) {
  $a = nbd_proc_cmdline($pid);
  if (!$a || strpos(basename($a[0]), 'php') !== 0) {
    return false;
  }
  foreach ($a as $arg) {
    if (substr($arg, -strlen('/nbd-beacon-server.php')) === '/nbd-beacon-server.php') {
      return true;
    }
  }
  return false;
}

/**
 * TERM then KILL one PID, only if $check($pid) passes (recorded PID, cmdline verified).
 */
function nbd_kill_verified($pid, callable $check, $wait_us = 300000) {
  $pid = (int)$pid;
  if ($pid <= 1 || !nbd_pid_alive($pid) || !$check($pid)) {
    return false;
  }
  nbd_signal($pid, 15);
  $step = 50000;
  for ($t = 0; $t < $wait_us && nbd_pid_alive($pid); $t += $step) {
    usleep($step);
  }
  if (nbd_pid_alive($pid) && $check($pid)) {
    nbd_signal($pid, 9);
  }
  return true;
}

/**
 * Live qemu-img convert processes from /proc (no ps|grep).
 * @return array<int,string[]> pid => argv
 */
function nbd_qemu_img_converts() {
  $out = [];
  foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $d) {
    $pid = (int)basename($d);
    if ($pid <= 1) {
      continue;
    }
    $a = nbd_proc_cmdline($pid);
    if ($a && basename($a[0]) === 'qemu-img' && in_array('convert', $a, true)) {
      $out[$pid] = $a;
    }
  }
  return $out;
}

/* ── Validation (ids, binds, devices, Pull paths) ─────────────────────── */

/** Cap a string length (UTF-8 aware when mbstring is present). */
function nbd_str_cap($s, $n) {
  $s = (string)$s;
  return function_exists('mb_substr') ? mb_substr($s, 0, (int)$n) : substr($s, 0, (int)$n);
}

/** Export / job id: exp-… or job-… with a safe charset. */
function nbd_valid_id($id, $kind) {
  $id = (string)$id;
  $prefix = ($kind === 'job') ? 'job-' : 'exp-';
  return (bool)preg_match('/^' . preg_quote($prefix, '/') . '[A-Za-z0-9._-]{1,64}$/', $id)
    && strpos($id, '..') === false;
}

/**
 * Normalize an IP: IPv4-mapped IPv6 (::ffff:a.b.c.d) → IPv4. Returns '' if not an IP.
 */
function nbd_ip_normalize($ip) {
  $ip = trim((string)$ip);
  if (strlen($ip) > 2 && $ip[0] === '[' && substr($ip, -1) === ']') {
    $ip = substr($ip, 1, -1);
  }
  if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
    return '';
  }
  $bin = @inet_pton($ip);
  if ($bin === false) {
    return '';
  }
  if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
    return inet_ntop(substr($bin, 12));
  }
  return inet_ntop($bin);
}

/** 0.0.0.0, ::, ::ffff:0.0.0.0 and friends (listen on every interface). */
function nbd_ip_is_unspecified($ip) {
  $n = nbd_ip_normalize($ip);
  if ($n === '') {
    return false;
  }
  $bin = @inet_pton($n);
  return $bin !== false && trim($bin, "\0") === '';
}

/**
 * Validate a Host bind address against this server's own addresses.
 *
 * - Any unspecified address (0.0.0.0, ::, ::ffff:0.0.0.0) = all interfaces → allow_bind_all.
 * - Otherwise the IP must be one of nbd_list_bind_ips().
 * - A public (non-private) IP needs allow_public_bind=yes.
 *
 * @return array{ok:bool,bind?:string,all?:bool,public?:bool,error?:string}
 */
function nbd_validate_bind($bind, ?array $cfg = null, ?array $bind_rows = null) {
  if ($cfg === null) {
    $cfg = nbd_load_cfg();
  }
  $raw = trim((string)$bind);
  if ($raw === '') {
    return ['ok' => false, 'error' => 'Bind IP is required (prefer Thunderbolt or private LAN).'];
  }
  $ip = nbd_ip_normalize($raw);
  if ($ip === '') {
    return ['ok' => false, 'error' => 'Bind must be an IP address of this server.'];
  }
  if (nbd_ip_is_unspecified($ip)) {
    if (($cfg['allow_bind_all'] ?? 'no') !== 'yes') {
      return ['ok' => false, 'error' => 'Binding all interfaces (' . $raw . ') is disabled (Allow bind 0.0.0.0 = No). Pick a specific IP.'];
    }
    return ['ok' => true, 'bind' => '0.0.0.0', 'all' => true, 'public' => true];
  }
  if ($bind_rows === null) {
    $bind_rows = nbd_list_bind_ips();
  }
  $row = null;
  foreach ($bind_rows as $r) {
    if ((string)($r['ip'] ?? '') === $ip) {
      $row = $r;
      break;
    }
  }
  if ($row === null) {
    return ['ok' => false, 'error' => 'Bind ' . $ip . ' is not an address on this server.'];
  }
  $public = empty($row['private']);
  if ($public && ($cfg['allow_public_bind'] ?? 'no') !== 'yes') {
    return ['ok' => false, 'error' => 'Bind ' . $ip . ' is a public address. NBD has no authentication — set Allow public bind IPs = Yes under Settings only if you really mean it.'];
  }
  return ['ok' => true, 'bind' => $ip, 'all' => false, 'public' => $public];
}

/** Host device path: /dev/<name>, no traversal. */
function nbd_validate_device($device) {
  $device = trim((string)$device);
  if ($device === '' || strpos($device, '..') !== false
      || !preg_match('#^/dev/[A-Za-z0-9][A-Za-z0-9._+-]*(/[A-Za-z0-9][A-Za-z0-9._+:-]*)*$#', $device)) {
    return ['ok' => false, 'error' => 'Invalid device path.'];
  }
  if (!file_exists($device)) {
    return ['ok' => false, 'error' => 'Device not found: ' . $device];
  }
  return ['ok' => true, 'device' => $device];
}

/** True if any path segment is "." or ".." (or the path has a NUL). */
function nbd_path_has_dots($path) {
  $path = (string)$path;
  if (strpos($path, "\0") !== false) {
    return true;
  }
  foreach (explode('/', $path) as $seg) {
    if ($seg === '.' || $seg === '..') {
      return true;
    }
  }
  return false;
}

/**
 * Unraid share-relative key for /mnt/<user|user0|diskN|cache|pool>/<rest>, so
 * /mnt/user/a/b and /mnt/cache/a/b are recognised as the same file.
 */
function nbd_share_key($path) {
  $root = preg_quote(NBDEXPORT_MNT, '#');
  if (preg_match('#^' . $root . '/([^/]+)/(.+)$#', (string)$path, $m)) {
    if (in_array($m[1], ['disks', 'remotes', 'addons', 'rootshare'], true)) {
      return '';
    }
    return $m[2];
  }
  return '';
}

/**
 * Same file? dev+inode when both exist, else the Unraid share-relative key.
 */
function nbd_paths_same_file($a, $b) {
  $a = (string)$a;
  $b = (string)$b;
  if ($a === '' || $b === '') {
    return false;
  }
  if ($a === $b) {
    return true;
  }
  $sa = @stat($a);
  $sb = @stat($b);
  if (is_array($sa) && is_array($sb) && $sa['dev'] === $sb['dev'] && $sa['ino'] === $sb['ino']) {
    return true;
  }
  $ka = nbd_share_key($a);
  return $ka !== '' && $ka === nbd_share_key($b);
}

/**
 * Validate a Pull source.
 * @return array{ok:bool,type?:string,src?:string,error?:string}
 */
function nbd_validate_pull_source($url) {
  $url = trim((string)$url);
  if ($url === '' || nbd_path_has_dots($url)) {
    return ['ok' => false, 'error' => 'Invalid source.'];
  }
  if (preg_match('#^nbd://(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)(:\d{1,5})?(/[A-Za-z0-9._-]{0,64})?$#', $url)) {
    return ['ok' => true, 'type' => 'nbd', 'src' => $url];
  }
  if (preg_match('#^/dev/[A-Za-z0-9][A-Za-z0-9/._+:-]*$#', $url)) {
    if (!file_exists($url)) {
      return ['ok' => false, 'error' => 'Local device not found: ' . $url];
    }
    return ['ok' => true, 'type' => 'local_device', 'src' => $url];
  }
  $mnt = NBDEXPORT_MNT . '/';
  if (strpos($url, $mnt) === 0 || strpos($url, '/tmp/') === 0) {
    $real = realpath($url);
    if ($real === false || !is_file($real)
        || (strpos($real, $mnt) !== 0 && strpos($real, '/tmp/') !== 0)) {
      return ['ok' => false, 'error' => 'Local file not found under /mnt or /tmp: ' . $url];
    }
    return ['ok' => true, 'type' => 'local_file', 'src' => $url];
  }
  return ['ok' => false, 'error' => 'Source must be nbd://host:port, /dev/…, or a file under /mnt or /tmp'];
}

/**
 * Validate a Pull output path.
 *
 * - File under /mnt/ (after realpath of the parent), never /dev, never "..".
 * - Parent must already exist on a mounted filesystem (not the RAM root fs).
 * - Target (and OUT.partial) must be absent or a regular file — never a symlink/device.
 * - Existing output needs $overwrite.
 * - Not the same file as the source, not an output of another live job.
 *
 * @param string[] $busy_outputs outputs of live (running/paused/queued) jobs
 * @return array{ok:bool,output?:string,partial?:string,exists?:bool,error?:string}
 */
function nbd_validate_output($output, $source = '', $overwrite = false, array $busy_outputs = []) {
  $output = trim((string)$output);
  if ($output === '' || $output[0] !== '/' || nbd_path_has_dots($output) || substr($output, -1) === '/') {
    return ['ok' => false, 'error' => 'Invalid output path (absolute file path, no . or .. segments).'];
  }
  if (strpos($output, '/dev/') === 0) {
    return ['ok' => false, 'error' => 'Output cannot be a block device (/dev/…). Use a file under /mnt/.'];
  }
  $mnt = NBDEXPORT_MNT . '/';
  if (strpos($output, $mnt) !== 0) {
    return ['ok' => false, 'error' => 'Output must be a file under /mnt/ (cache, pool, disk or share).'];
  }
  $base = basename($output);
  if ($base === '' || $base === '.' || $base === '..') {
    return ['ok' => false, 'error' => 'Output needs a file name.'];
  }
  $parent = dirname($output);
  if (!is_dir($parent)) {
    return ['ok' => false, 'error' => 'Folder does not exist: ' . $parent . ' (create it first; the plugin does not create folders).'];
  }
  $rparent = realpath($parent);
  if ($rparent === false || strpos($rparent . '/', $mnt) !== 0 || $rparent === NBDEXPORT_MNT) {
    return ['ok' => false, 'error' => 'Output folder resolves outside /mnt/: ' . $parent];
  }
  $sp = @stat($rparent);
  $sr = @stat(NBDEXPORT_ROOTFS);
  if (!is_array($sp) || !is_array($sr) || $sp['dev'] === $sr['dev']) {
    return ['ok' => false, 'error' => 'Output folder is not on a mounted disk or pool (RAM root filesystem): ' . $parent . '. Is the array/pool started?'];
  }
  $final = $rparent . '/' . $base;
  $partial = $final . '.partial';
  foreach ([$final, $partial] as $p) {
    if (is_link($p)) {
      return ['ok' => false, 'error' => 'Refusing a symlink as output: ' . $p];
    }
    if (file_exists($p) && filetype($p) !== 'file') {
      return ['ok' => false, 'error' => 'Output exists and is not a regular file: ' . $p];
    }
  }
  $exists = is_file($final);
  if ($exists && !$overwrite) {
    return ['ok' => false, 'error' => 'Output already exists: ' . $final . '. Choose another name or set Overwrite = Yes.'];
  }
  $source = (string)$source;
  if ($source !== '' && strpos($source, 'nbd://') !== 0) {
    if (nbd_paths_same_file($source, $final) || nbd_paths_same_file($source, $output)) {
      return ['ok' => false, 'error' => 'Source and output are the same file.'];
    }
  }
  foreach ($busy_outputs as $bo) {
    $bo = (string)$bo;
    if ($bo !== '' && (nbd_paths_same_file($bo, $final) || nbd_paths_same_file($bo, $output))) {
      return ['ok' => false, 'error' => 'Another Pull is already writing ' . $bo . '.'];
    }
  }
  return ['ok' => true, 'output' => $final, 'partial' => $partial, 'exists' => $exists];
}

/**
 * Flash memory: last-used host/pull fields + named presets (no secrets).
 * Path: /boot/config/plugins/NBDExport/memory.json
 */
function nbd_memory_path() {
  return NBDEXPORT_CFG_DIR . '/memory.json';
}

function nbd_memory_load() {
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

/** Write memory.json only when its content changes (flash wear). */
function nbd_memory_save(array $mem) {
  if (!isset($mem['presets']) || !is_array($mem['presets'])) {
    $mem['presets'] = [];
  }
  // Cap presets
  if (count($mem['presets']) > 40) {
    $mem['presets'] = array_slice($mem['presets'], -40, null, true);
  }
  return nbd_atomic_write(
    nbd_memory_path(),
    json_encode($mem, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    true
  );
}

/** Compare two memory entries ignoring saved_at. */
function nbd_memory_same(array $a, array $b) {
  unset($a['saved_at'], $b['saved_at']);
  return $a == $b;
}

/**
 * @param string|string[] $bind Primary bind IP, or list of binds
 * @param string[]|null $binds Optional multi-bind list (preferred when hosting on several networks)
 */
function nbd_memory_remember_host($device, $bind, $port, $read_only, $label, $binds = null) {
  $list = [];
  foreach ((array)(is_array($binds) && $binds ? $binds : $bind) as $b) {
    $b = trim((string)$b);
    if ($b !== '' && !in_array($b, $list, true)) {
      $list[] = $b;
    }
  }
  $mem = nbd_memory_load();
  $entry = [
    'device' => (string)$device,
    'bind' => $list ? $list[0] : '',
    'binds' => $list,
    'port' => (int)$port,
    'read_only' => $read_only ? 'yes' : 'no',
    'label' => (string)$label,
  ];
  if (is_array($mem['last_host'] ?? null) && nbd_memory_same($mem['last_host'], $entry)) {
    return;
  }
  $mem['last_host'] = $entry + ['saved_at' => date('c')];
  nbd_memory_save($mem);
}

function nbd_memory_remember_pull($url, $output, $format) {
  $mem = nbd_memory_load();
  $entry = [
    'nbd_url' => (string)$url,
    'output' => (string)$output,
    'format' => (string)$format,
  ];
  if (is_array($mem['last_pull'] ?? null) && nbd_memory_same($mem['last_pull'], $entry)) {
    return;
  }
  $mem['last_pull'] = $entry + ['saved_at' => date('c')];
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
  if (!nbd_atomic_write($path, $json . "\n")) {
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
    foreach (nbd_cfg_keys() as $k) {
      if (array_key_exists($k, $bundle['settings']) && is_scalar($bundle['settings'][$k])) {
        $cfg[$k] = (string)$bundle['settings'][$k];
      }
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
    static $iface_cache = [];
    $iface = $iface_cache[$peer] ?? '';
    if ($iface === '') {
      $route = (string)@shell_exec('ip -o route get ' . escapeshellarg($peer) . ' 2>/dev/null');
      if (preg_match('/\bdev\s+(\S+)/', $route, $mm)) {
        $iface = $mm[1];
        $iface_cache[$peer] = $iface;
      } else {
        $iface_cache[$peer] = '-';
      }
    }
    if ($iface !== '' && $iface !== '-') {
      $rxf = '/sys/class/net/' . $iface . '/statistics/rx_bytes';
      if (is_file($rxf)) {
        $net_bytes = (int)@file_get_contents($rxf);
      }
    }
  }
  $disk_bytes = null;
  $out = nbd_job_write_path($j);
  if ($out === '' || !is_file($out)) {
    $out = (string)($j['output'] ?? '');
  }
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

/**
 * Unraid notification (Storage Guard–style).
 * $priority: normal | warning | alert
 */
function nbd_notify($subject, $desc, $priority = 'warning', $link = '/Settings/NBDExport') {
  $script = '/usr/local/emhttp/webGui/scripts/notify';
  if (!is_executable($script)) {
    return false;
  }
  $priority = strtolower(trim((string)$priority));
  if (!in_array($priority, ['normal', 'warning', 'alert'], true)) {
    $priority = 'warning';
  }
  $cmd = $script
    . ' -e ' . escapeshellarg('NBD Export')
    . ' -s ' . escapeshellarg((string)$subject)
    . ' -d ' . escapeshellarg((string)$desc)
    . ' -i ' . escapeshellarg($priority)
    . ' -l ' . escapeshellarg((string)$link);
  @shell_exec(nbd_spawn_prefix() . $cmd . ' >/dev/null 2>&1 &');
  return true;
}

/** Normalize notify_* cfg values. */
function nbd_notify_cfg_get($key, $default = 'off') {
  $cfg = function_exists('nbd_load_cfg') ? nbd_load_cfg() : [];
  $v = strtolower(trim((string)($cfg[$key] ?? $default)));
  if ($key === 'notify_pull_done') {
    return ($v === 'normal') ? 'normal' : 'off';
  }
  if (in_array($key, ['notify_pull_failed', 'notify_host_down'], true)) {
    if ($v === 'warning' || $v === 'alert') {
      return $v;
    }
    return 'off';
  }
  return $default;
}

/**
 * Fire one-shot Pull finish notifications. Sets notified on the job array (caller persists).
 */
function nbd_maybe_notify_job_finished(array &$j) {
  if (!empty($j['notified'])) {
    return;
  }
  $ok = !empty($j['ok']);
  $code = strtolower((string)($j['fail_code'] ?? ''));
  $reason = (string)($j['fail_reason'] ?? '');
  // User Stop / Cancel — never treat as failure alert
  if (!$ok && (strpos($code, 'stopped_by_user') !== false
      || strpos($code, 'cancelled_while_queued') !== false
      || stripos($reason, 'Stopped by user') !== false
      || stripos($reason, 'Cancelled while queued') !== false)) {
    $j['notified'] = true;
    return;
  }
  $src = (string)($j['url'] ?? '');
  $out = (string)($j['output'] ?? '');
  $id = (string)($j['id'] ?? '');
  if ($ok) {
    $mode = nbd_notify_cfg_get('notify_pull_done', 'off');
    if ($mode === 'normal') {
      $desc = 'Pull finished successfully.';
      if ($src !== '') {
        $desc .= "\nSource: " . $src;
      }
      if ($out !== '') {
        $desc .= "\nOutput: " . $out;
      }
      nbd_notify('Pull finished', $desc, 'normal', '/Settings/NBDExport');
    }
  } else {
    $mode = nbd_notify_cfg_get('notify_pull_failed', 'warning');
    if ($mode === 'warning' || $mode === 'alert') {
      $why = $reason !== '' ? $reason : 'See Status → History / Logs';
      $desc = 'Pull failed: ' . $why;
      if ($src !== '') {
        $desc .= "\nSource: " . $src;
      }
      if ($out !== '') {
        $desc .= "\nOutput: " . $out;
      }
      if ($id !== '') {
        $desc .= "\nJob: " . $id;
      }
      nbd_notify('Pull failed', $desc, $mode === 'alert' ? 'alert' : 'warning', '/Settings/NBDExport');
    }
  }
  $j['notified'] = true;
}

/**
 * One-shot Host-down notify when a managed export goes stale unexpectedly.
 */
function nbd_maybe_notify_host_down(array &$e, $statefile) {
  if (empty($e['stale']) || !empty($e['notified_down'])) {
    return;
  }
  if (($e['stop_reason'] ?? '') === 'user') {
    $e['notified_down'] = true;
    return;
  }
  $mode = nbd_notify_cfg_get('notify_host_down', 'off');
  if ($mode === 'warning' || $mode === 'alert') {
    $dev = (string)($e['device'] ?? '');
    $url = (string)($e['url'] ?? '');
    $desc = 'Host export stopped unexpectedly (process gone / not listening).';
    if ($dev !== '') {
      $desc .= "\nDevice: " . $dev;
    }
    if ($url !== '') {
      $desc .= "\nURL: " . $url;
    }
    nbd_notify('Host stopped unexpectedly', $desc, $mode === 'alert' ? 'alert' : 'warning', '/Settings/NBDExport');
  }
  $e['notified_down'] = true;
  if ($statefile !== '' && is_file($statefile)) {
    $persist = $e;
    unset($persist['alive'], $persist['listening'], $persist['stale']);
    nbd_atomic_write($statefile, json_encode($persist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
  }
}

function nbd_detect_tools() {
  $find = function ($names) {
    foreach ($names as $n) {
      $p = trim(nbd_run('command -v ' . escapeshellarg($n) . ' 2>/dev/null'));
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
  $out = nbd_run_lines('ip -4 -o addr show 2>/dev/null');
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
      'public' => !$priv,
      'virtual' => $is_virt,
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
 * Flags: array (self or parent disk in Unraid inventory), mounted (self or any
 * child), holder (claimed by dm/md/etc.), ro-hw.
 */
function nbd_list_disks() {
  $json = nbd_run('lsblk -J -b -o NAME,SIZE,TYPE,MODEL,SERIAL,TRAN,MOUNTPOINT,PKNAME,RM,RO 2>/dev/null');
  $disks = [];
  if ($json === '') {
    return $disks;
  }
  $data = @json_decode($json, true);
  if (!is_array($data) || empty($data['blockdevices'])) {
    return $disks;
  }

  $array_devs = nbd_unraid_array_devices();
  $any_mounted = function ($n) use (&$any_mounted) {
    $mp = $n['mountpoint'] ?? '';
    if (is_string($mp) && $mp !== '') {
      return true;
    }
    foreach ((array)($n['children'] ?? []) as $c) {
      if (is_array($c) && $any_mounted($c)) {
        return true;
      }
    }
    return false;
  };

  $walk = function ($nodes, array $ancestors = []) use (&$walk, &$disks, $array_devs, $any_mounted) {
    if (!is_array($nodes)) {
      return;
    }
    foreach ($nodes as $n) {
      if (!is_array($n)) {
        continue;
      }
      $name = (string)($n['name'] ?? '');
      $type = (string)($n['type'] ?? '');
      $path = '/dev/' . $name;
      if ($name === '' || in_array($type, ['loop', 'rom'], true) || strpos($name, 'zram') === 0) {
        continue;
      }
      $is_disk = ($type === 'disk');
      $is_part = ($type === 'part');
      if (!$is_disk && !$is_part) {
        continue;
      }

      $size = isset($n['size']) ? (int)$n['size'] : 0;
      $mount = $n['mountpoint'] ?? '';
      if (!is_string($mount)) {
        $mount = '';
      }

      $flags = [];
      foreach (array_merge([$name], $ancestors) as $nm) {
        if (isset($array_devs[$nm]) || preg_match('/^md\d+/', $nm)) {
          $flags[] = 'array';
          break;
        }
      }
      if ($any_mounted($n)) {
        $flags[] = 'mounted';
      }
      if (nbd_block_holders($name)) {
        $flags[] = 'holder';
      }
      if (!empty($n['ro']) && (string)$n['ro'] === '1') {
        $flags[] = 'ro-hw';
      }
      $flags = array_values(array_unique($flags));

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
        'flags' => $flags,
        'warn' => (bool)array_intersect($flags, ['array', 'mounted', 'holder']),
        'label' => $path
          . ($model !== '' ? ' · ' . $model : '')
          . ($serial !== '' ? ' · ' . $serial : '')
          . ' · ' . nbd_format_bytes($size)
          . ($type === 'part' ? ' (partition)' : ''),
      ];

      if (!empty($n['children']) && $is_disk) {
        $walk($n['children'], array_merge([$name], $ancestors));
      }
    }
  };
  $walk($data['blockdevices']);
  return $disks;
}

/** Kernel holders of a block device (dm-*, md*, bcache…) from /sys/class/block/<name>/holders. */
function nbd_block_holders($name) {
  $name = basename((string)$name);
  if ($name === '' || $name === '.' || $name === '..') {
    return [];
  }
  $out = [];
  foreach (glob(NBDEXPORT_SYS_BLOCK . '/' . $name . '/holders/*') ?: [] as $h) {
    $out[] = basename($h);
  }
  return $out;
}

/** Parent chain of a block device via lsblk PKNAME (closest first). */
function nbd_block_ancestors($name) {
  $out = [];
  $cur = basename((string)$name);
  for ($i = 0; $i < 4 && $cur !== ''; $i++) {
    $p = trim(nbd_run('lsblk -dn -o PKNAME ' . escapeshellarg('/dev/' . $cur) . ' 2>/dev/null'));
    $p = preg_replace('/\s.*$/s', '', $p);
    if ($p === '' || $p === $cur || in_array($p, $out, true)) {
      break;
    }
    $out[] = $p;
    $cur = $p;
  }
  return $out;
}

function nbd_unraid_array_devices() {
  $map = [];
  $ini = NBDEXPORT_DISKS_INI;
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
    $dev = (string)($d['device'] ?? '');
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

/**
 * Managed Host exports (read-only view: no writes, no notifications).
 * Unexpected exits are recorded by the Host wrapper hook (include/nbd-hook.php).
 */
function nbd_exports_state() {
  $list = [];
  foreach (glob(NBDEXPORT_RUN . '/exp-*.json') ?: [] as $f) {
    $raw = @file_get_contents($f);
    $j = is_string($raw) ? @json_decode($raw, true) : null;
    if (!is_array($j) || empty($j['id']) || !nbd_valid_id((string)$j['id'], 'exp')) {
      continue;
    }
    if (empty($j['device']) && empty($j['port']) && empty($j['bind'])) {
      continue;
    }
    $pid = isset($j['pid']) ? (int)$j['pid'] : 0;
    $alive = $pid > 0 && nbd_pid_is_qemu_nbd($pid);
    $j['alive'] = $alive;
    $j['listening'] = false;
    if ($alive) {
      // Process up ⇒ treat as listening (skip ss under load).
      $j['listening'] = true;
    } elseif (!empty($j['bind']) && !empty($j['port'])) {
      $j['listening'] = nbd_port_listening($j['bind'], (int)$j['port']);
    }
    if (!$alive && empty($j['listening'])) {
      $j['stale'] = true;
    }
    $list[] = $j;
  }
  usort($list, function ($a, $b) {
    return strcmp($a['id'] ?? '', $b['id'] ?? '');
  });
  return $list;
}

/**
 * Cached listening TCP ports from `ss -lnt` (no -p — cheaper under load).
 * @return list<string> raw ss lines
 */
function nbd_ss_lnt_lines() {
  static $ts = 0.0;
  static $lines = null;
  $now = microtime(true);
  if (is_array($lines) && ($now - $ts) < 2.5) {
    return $lines;
  }
  $lines = nbd_run_lines('ss -lnt 2>/dev/null');
  $ts = $now;
  return $lines;
}

/**
 * Exact match of a listening socket: port equal, and address equal to $bind or a
 * wildcard (0.0.0.0, *, [::]).
 */
function nbd_port_listening($bind, $port) {
  $port = (int)$port;
  if ($port <= 0) {
    return false;
  }
  $bind = nbd_ip_normalize($bind);
  foreach (nbd_ss_lnt_lines() as $line) {
    $cols = preg_split('/\s+/', trim($line));
    if (count($cols) < 4 || $cols[0] !== 'LISTEN') {
      continue;
    }
    $local = $cols[3];
    $pos = strrpos($local, ':');
    if ($pos === false || (int)substr($local, $pos + 1) !== $port || substr($local, $pos + 1) !== (string)$port) {
      continue;
    }
    $addr = substr($local, 0, $pos);
    $addr = preg_replace('/%.*$/', '', trim($addr, '[]'));
    if ($bind === '' || $addr === '*' || nbd_ip_is_unspecified($addr) || nbd_ip_normalize($addr) === $bind) {
      return true;
    }
  }
  return false;
}

function nbd_new_export_id() {
  return 'exp-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
}

/**
 * Risk assessment for a block device path.
 * "array" flag = Unraid inventory (array, parity, cache, pools via disks.ini) or md*,
 *   checked for the device AND its parent disk(s) (lsblk PKNAME), so /dev/sdb1 of an
 *   array disk counts as array.
 * "mounted" = the device or any child has a mountpoint.
 * "holder" = claimed by another kernel device (dm, md, bcache…).
 * "flash" flag = device that currently backs /boot (USB flash or disk boot).
 *
 * @return array{
 *   path:string,name:string,array:bool,mounted:bool,flash:bool,holder:bool,risky:bool,
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
  $holder = false;

  $ancestors = nbd_block_ancestors(basename($name));
  $chain = array_merge([$name, basename($name)], $ancestors);

  // disks.ini: array, parity, cache, pools — all Unraid-assigned storage
  $array_devs = nbd_unraid_array_devices();
  foreach ($chain as $nm) {
    if (isset($array_devs[$nm]) || isset($array_devs['/dev/' . $nm]) || preg_match('/^md\d+/', $nm)) {
      $array = true;
      $flags[] = 'array';
      break;
    }
  }

  // Any mount on this device or its children
  $mp = nbd_run('lsblk -n -r -o MOUNTPOINT ' . escapeshellarg($device) . ' 2>/dev/null');
  foreach (preg_split('/\s+/', trim($mp)) as $p) {
    if ($p !== '' && $p !== '-') {
      $mounted = true;
      $flags[] = 'mounted';
      break;
    }
  }

  if (nbd_block_holders(basename($name))) {
    $holder = true;
    $flags[] = 'holder';
  }

  // Unraid boot device: whatever currently backs /boot (USB flash or disk/partition)
  $boot_src = trim(nbd_run('findmnt -n -o SOURCE /boot 2>/dev/null'));
  if ($boot_src !== '') {
    $boot_name = preg_replace('#^/dev/#', '', $boot_src);
    $boot_anc = nbd_block_ancestors($boot_name);
    // Same physical disk as /boot: the boot partition, its disk, or a sibling partition
    $dev_disk = $ancestors ? end($ancestors) : basename($name);
    $boot_disk = $boot_anc ? end($boot_anc) : $boot_name;
    if ($dev_disk === $boot_disk) {
      $flash = true;
      $flags[] = 'flash';
    }
  }

  $flags = array_values(array_unique($flags));
  $risky = $array || $mounted || $flash || $holder;
  $summary = $risky ? implode(', ', $flags) : 'ok';
  return [
    'path' => $device,
    'name' => $name,
    'array' => $array,
    'mounted' => $mounted,
    'flash' => $flash,
    'holder' => $holder,
    'risky' => $risky,
    'flags' => $flags,
    'summary' => $summary,
  ];
}

/**
 * Whether destructive mode is enabled (writable / array / mounted exports).
 */
function nbd_destructive_mode_on(?array $cfg = null) {
  if ($cfg === null) {
    $cfg = nbd_load_cfg();
  }
  return (($cfg['destructive_mode'] ?? 'no') === 'yes');
}

/**
 * Remove the old (≤ 2026.09.28ab) UD overlay line from Unraid layout files.
 * The overlay now loads from NBDUDOverlay.page (Menu="Buttons"); core files are never patched.
 * Also drops the stock-file backup older releases kept on flash.
 */
function nbd_ud_overlay_uninject() {
  $stock = NBDEXPORT_CFG_DIR . '/stock-backup';
  if (is_dir($stock) && !is_link($stock)) {
    foreach (glob($stock . '/*.stock') ?: [] as $f) {
      @unlink($f);
    }
    @rmdir($stock);
  }
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
 * - confirm must be true for any risky, RW or public-bind start (UI double-check; server-enforced).
 * - Bind must be one of this server's addresses; bind-all (0.0.0.0, ::) needs allow_bind_all;
 *   a public address needs allow_public_bind.
 * - Writable: one listener per device, one client (--shared=1).
 *
 * qemu-nbd runs under a small bash wrapper that calls include/nbd-hook.php when it exits,
 * so an unexpected stop is recorded (and notified) without any GET poller writing state.
 *
 * @return array{ok:bool,error?:string,id?:string,url?:string}
 */
function nbd_export_start($device, $bind, $port, $read_only = true, $label = '', $shared = 2, $confirm = false) {
  return nbd_locked(function () use ($device, $bind, $port, $read_only, $label, $shared, $confirm) {
    nbd_ensure_runtime_dirs();
    $cfg = nbd_load_cfg();
    if (($cfg['enabled'] ?? 'yes') !== 'yes') {
      return ['ok' => false, 'error' => 'Plugin is disabled (Enable NBD Export = No).'];
    }
    $tools = nbd_detect_tools();
    if ($tools['qemu_nbd'] === '') {
      return ['ok' => false, 'error' => 'qemu-nbd not found. Install/enable the Unraid VM stack or install qemu-nbd.'];
    }
    $port = (int)$port;
    $read_only = (bool)$read_only;
    $confirm = (bool)$confirm;
    $label = nbd_str_cap(trim((string)$label), 80);
    $dv = nbd_validate_device($device);
    if (empty($dv['ok'])) {
      return $dv;
    }
    $device = $dv['device'];
    $bv = nbd_validate_bind($bind, $cfg);
    if (empty($bv['ok'])) {
      return $bv;
    }
    $bind = $bv['bind'];
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
    // Array / mounted / flash / holder require destructive mode even when read-only
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
    // Risky, RW or public/all-interfaces bind requires explicit confirm from UI
    if ((!$read_only || $risk['risky'] || !empty($bv['public'])) && !$confirm) {
      $why = [];
      if (!$read_only) {
        $why[] = 'writable';
      }
      if ($risk['risky']) {
        $why[] = $risk['summary'];
      }
      if (!empty($bv['public'])) {
        $why[] = !empty($bv['all']) ? 'all interfaces' : 'public address';
      }
      return [
        'ok' => false,
        'error' => 'Confirmation required for this export (' . implode(', ', $why) . '). Confirm in the UI and try again.',
      ];
    }

    // One writer: a writable device gets exactly one listener, and nothing else may host it
    foreach (nbd_exports_state() as $e) {
      if (empty($e['alive']) && empty($e['listening'])) {
        continue;
      }
      if ((int)($e['port'] ?? 0) === $port && (($e['bind'] ?? '') === $bind || ($e['bind'] ?? '') === '0.0.0.0' || $bind === '0.0.0.0')) {
        return ['ok' => false, 'error' => 'Already exporting on ' . ($e['bind'] ?? '') . ':' . $port];
      }
      if (($e['device'] ?? '') === $device) {
        $e_ro = array_key_exists('read_only', $e) ? !empty($e['read_only']) : false;
        if (!$read_only) {
          return ['ok' => false, 'error' => 'Writable host needs a single listener: ' . $device . ' is already hosted (' . ($e['url'] ?? '') . '). Stop it first.'];
        }
        if (!$e_ro) {
          return ['ok' => false, 'error' => $device . ' is already hosted writable (' . ($e['url'] ?? '') . '). Stop it first.'];
        }
      }
    }
    $shared = $read_only ? max(1, min(8, (int)$shared)) : 1;

    $id = nbd_new_export_id();
    $pidfile = NBDEXPORT_RUN . '/' . $id . '.pid';
    $wrapper_pidfile = NBDEXPORT_RUN . '/' . $id . '.wrapper.pid';
    $statefile = NBDEXPORT_RUN . '/' . $id . '.json';
    $script = NBDEXPORT_RUN . '/' . $id . '.sh';
    $logfile = NBDEXPORT_LOG . '/' . $id . '.log';

    $cmd = [
      $tools['qemu_nbd'],
      '--persistent',
      '--shared=' . $shared,
      '--bind=' . $bind,
      '--port=' . $port,
      '--format=raw',
    ];
    if ($read_only) {
      $cmd[] = '--read-only';
    }
    $cmd[] = $device;
    $cmd_s = implode(' ', array_map('escapeshellarg', $cmd));

    $sh = "#!/bin/bash\n"
      . '# NBD Export Host wrapper (' . $id . ")\n"
      . 'LOG=' . escapeshellarg($logfile) . "\n"
      . 'PF=' . escapeshellarg($pidfile) . "\n"
      . 'HOOK=' . escapeshellarg(NBDEXPORT_HOOK) . "\n"
      . 'ID=' . escapeshellarg($id) . "\n"
      . $cmd_s . ' >>"$LOG" 2>&1 &' . "\n"
      . 'QPID=$!' . "\n"
      . 'echo "$QPID" >"$PF"' . "\n"
      . 'wait "$QPID"' . "\n"
      . 'RC=$?' . "\n"
      . 'echo "$(date -Iseconds) qemu-nbd exited rc=$RC" >>"$LOG"' . "\n"
      . 'PHPBIN=$(command -v php || echo /usr/bin/php)' . "\n"
      . 'if [ -f "$HOOK" ]; then "$PHPBIN" "$HOOK" export "$ID" "$RC" >/dev/null 2>&1; fi' . "\n"
      . "exit 0\n";
    if (!nbd_atomic_write($script, $sh)) {
      return ['ok' => false, 'error' => 'Cannot write ' . $script];
    }
    @chmod($script, 0700);
    @file_put_contents($logfile, date('c') . " start: $cmd_s\n", FILE_APPEND);
    @unlink($pidfile);
    nbd_spawn('setsid nohup bash ' . escapeshellarg($script) . ' >/dev/null 2>&1 & echo $! >' . escapeshellarg($wrapper_pidfile));

    $pid = 0;
    for ($i = 0; $i < 20; $i++) {
      usleep(50000);
      $pid = (int)@file_get_contents($pidfile);
      if ($pid > 0) {
        break;
      }
    }
    usleep(250000);
    if ($pid <= 0 || !nbd_pid_is_qemu_nbd($pid, $bind, $port)) {
      @unlink($script);
      @unlink($pidfile);
      @unlink($wrapper_pidfile);
      return ['ok' => false, 'error' => 'qemu-nbd failed to start — see ' . $logfile];
    }

    $url_host = (strpos($bind, ':') !== false) ? '[' . $bind . ']' : $bind;
    $state = [
      'id' => $id,
      'device' => $device,
      'bind' => $bind,
      'port' => $port,
      'read_only' => (bool)$read_only,
      'label' => $label,
      'pid' => $pid,
      'wrapper_pid' => (int)@file_get_contents($wrapper_pidfile),
      'started' => date('c'),
      'url' => 'nbd://' . $url_host . ':' . $port,
      'shared' => $shared,
      'public' => !empty($bv['public']),
    ];
    nbd_atomic_write($statefile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    nbd_beacon_ensure();
    return ['ok' => true, 'id' => $id, 'url' => $state['url']];
  });
}

/**
 * Stop one managed Host. Kills only the recorded qemu-nbd PID, after checking its
 * cmdline (qemu-nbd with this export's --bind/--port). Never pkill.
 */
function nbd_export_stop($id) {
  return nbd_locked(function () use ($id) {
    $id = (string)$id;
    if (!nbd_valid_id($id, 'exp')) {
      return ['ok' => false, 'error' => 'Invalid export id'];
    }
    $pidfile = NBDEXPORT_RUN . '/' . $id . '.pid';
    $statefile = NBDEXPORT_RUN . '/' . $id . '.json';
    $j = is_file($statefile) ? @json_decode((string)@file_get_contents($statefile), true) : null;
    $j = is_array($j) ? $j : [];
    $bind = trim((string)($j['bind'] ?? ''));
    $port = (int)($j['port'] ?? 0);
    $pids = [];
    if (is_file($pidfile)) {
      $pids[] = (int)@file_get_contents($pidfile);
    }
    if (!empty($j['pid'])) {
      $pids[] = (int)$j['pid'];
    }
    $killed = [];
    foreach (array_unique($pids) as $pid) {
      $ok = nbd_kill_verified($pid, function ($p) use ($bind, $port) {
        return nbd_pid_is_qemu_nbd($p, $bind !== '' ? $bind : null, $port > 0 ? $port : null);
      });
      if ($ok) {
        $killed[] = $pid;
      }
    }
    foreach (['.pid', '.json', '.sh', '.wrapper.pid', '.hostdown'] as $sfx) {
      @unlink(NBDEXPORT_RUN . '/' . $id . $sfx);
    }
    // Drop beacon when nothing left listening
    nbd_beacon_ensure();
    return ['ok' => true, 'killed' => $killed];
  });
}

/**
 * Stop every managed Host (Pull jobs are not touched) and remove only Host/beacon
 * runtime files (exp-*, beacon-*). Pull job state under RUN stays.
 */
function nbd_stop_all_exports() {
  return nbd_locked(function () {
    $n = 0;
    foreach (nbd_exports_state() as $e) {
      if (!empty($e['id'])) {
        nbd_export_stop($e['id']);
        $n++;
      }
    }
    nbd_beacon_stop();
    nbd_sweep_host_files();
    return ['ok' => true, 'count' => $n];
  });
}

/** Remove leftover Host + beacon runtime files only (exp-*, beacon-*). */
function nbd_sweep_host_files() {
  $n = 0;
  foreach (array_merge(glob(NBDEXPORT_RUN . '/exp-*') ?: [], glob(NBDEXPORT_RUN . '/beacon-*') ?: []) as $f) {
    if (is_file($f) && @unlink($f)) {
      $n++;
    }
  }
  return $n;
}

/**
 * Emergency / policy: stop every managed Host that is writable (not read-only).
 * @return array{ok:bool,stopped:string[],count:int}
 */
function nbd_stop_writable_exports() {
  return nbd_locked('nbd_stop_writable_exports_locked');
}

function nbd_stop_writable_exports_locked() {
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
function nbd_stop_exports_disallowed_by_cfg(?array $cfg = null) {
  return nbd_locked(function () use ($cfg) {
    return nbd_stop_exports_disallowed_by_cfg_locked($cfg);
  });
}

function nbd_stop_exports_disallowed_by_cfg_locked(?array $cfg = null) {
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

/** Pull job wrapper script path for an id. */
function nbd_job_script($id) {
  return NBDEXPORT_RUN . '/' . $id . '.sh';
}

/** Is the recorded wrapper PID still our bash running this job's script? */
function nbd_job_wrapper_alive(array $j) {
  $pid = (int)($j['pid'] ?? 0);
  $id = (string)($j['id'] ?? '');
  return $pid > 1 && $id !== '' && nbd_pid_is_wrapper($pid, nbd_job_script($id));
}

/** Path the convert writes while running (OUT.partial; legacy jobs wrote OUT directly). */
function nbd_job_write_path(array $j) {
  $p = (string)($j['partial'] ?? '');
  return $p !== '' ? $p : (string)($j['output'] ?? '');
}

/**
 * Derive live status for one persisted job (pure read: no writes, no notifications).
 * A job whose processes are gone without a persisted finish is reported as finished
 * with pending_finalize=true; nbd_jobs_reconcile() persists it under the lock.
 */
function nbd_job_derive(array $j) {
  $status = (string)($j['status'] ?? '');
  if (!empty($j['finished'])) {
    $j['alive'] = false;
    $j['orphaned'] = false;
  } elseif ($status === 'queued') {
    $j['alive'] = false;
  } else {
    $wrapper_alive = nbd_job_wrapper_alive($j);
    $orphan_pid = $wrapper_alive ? 0 : nbd_find_orphan_qemu_img($j);
    $paused = ($status === 'paused')
      || ($wrapper_alive && nbd_pid_is_stopped((int)$j['pid']))
      || ($orphan_pid > 0 && nbd_pid_is_stopped($orphan_pid));
    if ($wrapper_alive || $orphan_pid > 0) {
      $j['alive'] = true; // occupies a slot (running or paused)
      $j['orphaned'] = ($orphan_pid > 0);
      if ($orphan_pid > 0) {
        $j['orphan_pid'] = $orphan_pid;
      }
      $j['status'] = $paused ? 'paused' : 'running';
    } else {
      $j['alive'] = false;
      $log = (string)($j['log'] ?? '');
      $tail = ($log !== '' && is_file($log)) ? (string)@file_get_contents($log) : '';
      $j['finished'] = true;
      $j['pending_finalize'] = true;
      $j['ok'] = (strpos($tail, 'NBD_JOB_OK') !== false);
      $j['status'] = $j['ok'] ? 'done' : 'failed';
      if (!$j['ok']) {
        if (strpos($tail, 'NBD_JOB_FAIL') === false) {
          $j['fail_code'] = 'process_exited';
        }
        $fi = nbd_job_fail_info($j + ['log' => $log]);
        $j['fail_code'] = $fi['code'];
        $j['fail_reason'] = $fi['reason'];
        $j['fail_known'] = !empty($fi['known']);
      }
    }
  }
  $wp = nbd_job_write_path($j);
  $size_path = (!empty($j['alive']) && $wp !== '' && is_file($wp)) ? $wp : (string)($j['output'] ?? '');
  if ($size_path !== '' && is_file($size_path)) {
    $j['output_size'] = (int)@filesize($size_path);
    $j['output_size_h'] = nbd_format_bytes($j['output_size']);
  } elseif (!empty($j['output'])) {
    $j['output_size_h'] = '—';
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
  return $j;
}

/**
 * All managed Pull jobs with derived status. Read-only (safe for GET pollers).
 */
function nbd_jobs_state() {
  $list = [];
  foreach (glob(NBDEXPORT_RUN . '/job-*.json') ?: [] as $f) {
    $raw = @file_get_contents($f);
    $j = is_string($raw) ? @json_decode($raw, true) : null;
    if (!is_array($j) || empty($j['id']) || !nbd_valid_id((string)$j['id'], 'job')) {
      continue;
    }
    $list[] = nbd_job_derive($j);
  }
  usort($list, function ($a, $b) {
    return strcmp($b['started'] ?? '', $a['started'] ?? '');
  });
  return $list;
}

/**
 * Persist the finish of one job (log marker, notification, JSON). Caller holds the lock.
 */
function nbd_job_finalize(array $j, $ok) {
  $log = (string)($j['log'] ?? '');
  $j['finished'] = true;
  $j['ok'] = (bool)$ok;
  $j['status'] = $ok ? 'done' : 'failed';
  $j['finished_at'] = $j['finished_at'] ?? date('c');
  if (!$ok) {
    $tail = ($log !== '' && is_file($log)) ? (string)@file_get_contents($log) : '';
    if (strpos($tail, 'NBD_JOB_FAIL') === false && $log !== '') {
      @file_put_contents($log, "\nNBD_JOB_FAIL process_exited\n", FILE_APPEND);
    }
    if (empty($j['fail_code']) || empty($j['fail_reason'])) {
      $fi = nbd_job_fail_info($j + ['log' => $log]);
      $j['fail_code'] = $fi['code'];
      $j['fail_reason'] = $fi['reason'];
      $j['fail_known'] = !empty($fi['known']);
    }
    // A failed job never leaves a partial image behind
    $part = (string)($j['partial'] ?? '');
    if ($part !== '' && is_file($part) && !is_link($part)) {
      @unlink($part);
    }
  }
  unset($j['pending_finalize']);
  nbd_maybe_notify_job_finished($j);
  nbd_job_persist($j);
  return $j;
}

/**
 * Persist finished jobs whose processes are gone, then start queued jobs.
 * Called from POST handlers, the job wrapper exit hook, and plugin install.
 */
function nbd_jobs_reconcile() {
  return nbd_locked(function () {
    $n = 0;
    foreach (nbd_jobs_state() as $j) {
      if (!empty($j['pending_finalize'])) {
        nbd_job_finalize($j, !empty($j['ok']));
        $n++;
      }
    }
    $k = nbd_pull_queue_kick();
    return ['finalized' => $n, 'started' => $k['started'] ?? []];
  });
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

/** Direct children of a PID (from /proc/<pid>/task/<pid>/children). */
function nbd_pid_children($pid) {
  $pid = (int)$pid;
  $raw = @file_get_contents('/proc/' . $pid . '/task/' . $pid . '/children');
  $out = [];
  foreach (preg_split('/\s+/', trim((string)$raw)) as $k) {
    if ($k !== '' && ctype_digit($k)) {
      $out[] = (int)$k;
    }
  }
  return $out;
}

/**
 * Collect PIDs for a Pull job: the verified wrapper, its direct children, and any
 * qemu-img convert whose output argument is this job's partial/output.
 *
 * @return int[]
 */
function nbd_job_pids(array $j) {
  $pids = [];
  if (nbd_job_wrapper_alive($j)) {
    $pid = (int)$j['pid'];
    $pids[] = $pid;
    foreach (nbd_pid_children($pid) as $k) {
      $pids[] = $k;
    }
  }
  $orphan = nbd_find_orphan_qemu_img($j);
  if ($orphan > 1) {
    $pids[] = $orphan;
  }
  return array_values(array_unique(array_filter($pids, function ($p) {
    return $p > 1;
  })));
}

/** ext-<pid>: an unmanaged qemu-img convert. Returns the PID or 0. */
function nbd_external_convert_pid($id) {
  if (!preg_match('/^ext-(\d{1,10})$/', (string)$id, $m)) {
    return 0;
  }
  $pid = (int)$m[1];
  return nbd_pid_is_qemu_img_convert($pid) ? $pid : 0;
}

/**
 * Pause a running Pull (SIGSTOP) — frees disk IO for parity etc. Slot stays reserved.
 */
function nbd_image_pause($id) {
  return nbd_locked(function () use ($id) {
    if (strpos((string)$id, 'ext-') === 0) {
      $pid = nbd_external_convert_pid($id);
      if ($pid > 0) {
        nbd_signal($pid, 19);
        return ['ok' => true, 'id' => $id, 'external' => true, 'pids' => [$pid]];
      }
      return ['ok' => false, 'error' => 'External qemu-img convert not found'];
    }
    $j = nbd_job_load($id);
    if (!$j) {
      return ['ok' => false, 'error' => 'Unknown job'];
    }
    $j = nbd_job_derive($j);
    $st = nbd_job_ui_status($j);
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
      nbd_signal($p, 19); // SIGSTOP
    }
    $j['status'] = 'paused';
    $j['paused_at'] = date('c');
    $log = (string)($j['log'] ?? '');
    if ($log !== '') {
      @file_put_contents($log, date('c') . " paused (SIGSTOP)\n", FILE_APPEND);
    }
    nbd_job_persist($j);
    return ['ok' => true, 'id' => $id, 'pids' => $pids];
  });
}

/**
 * Resume a paused Pull (SIGCONT).
 */
function nbd_image_resume($id) {
  return nbd_locked(function () use ($id) {
    if (strpos((string)$id, 'ext-') === 0) {
      $pid = nbd_external_convert_pid($id);
      if ($pid > 0) {
        nbd_signal($pid, 18);
        return ['ok' => true, 'id' => $id, 'external' => true, 'pids' => [$pid]];
      }
      return ['ok' => false, 'error' => 'External qemu-img convert not found'];
    }
    $j = nbd_job_load($id);
    if (!$j) {
      return ['ok' => false, 'error' => 'Unknown job'];
    }
    $j = nbd_job_derive($j);
    $pids = nbd_job_pids($j);
    if (($j['status'] ?? '') !== 'paused' && empty(array_filter($pids, 'nbd_pid_is_stopped'))) {
      return ['ok' => false, 'error' => 'Job is not paused'];
    }
    if (!$pids) {
      return ['ok' => false, 'error' => 'No process to resume'];
    }
    foreach ($pids as $p) {
      nbd_signal($p, 18); // SIGCONT
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
  });
}

/**
 * Find a qemu-img convert still writing this job's file after the wrapper died.
 * Matches the exact output argument (last argv) — never a substring.
 */
function nbd_find_orphan_qemu_img(array $j) {
  $want = array_filter([(string)($j['partial'] ?? ''), (string)($j['output'] ?? '')]);
  if (!$want) {
    return 0;
  }
  foreach (nbd_qemu_img_converts() as $pid => $argv) {
    $last = (string)end($argv);
    if (in_array($last, $want, true)) {
      return (int)$pid;
    }
  }
  return 0;
}

/**
 * Compact live snapshot for WebUI polling (in-place badge updates).
 * Read-only: no queue kicks, no state writes (GET pollers call this).
 *
 * @return array{exports:array,jobs:array,watch:bool,live_exports:int,live_jobs:int,queued_jobs:int,ts:int}
 */
function nbd_live_snapshot() {
  $exports = [];
  $watch = false;
  $live_exports = 0;
  $queued_jobs = 0;
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
  // Managed jobs only — Status page still merges External when rendered.
  foreach (nbd_jobs_state() as $j) {
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
    // Skip log tails on the hot poll path (Status cards already have them on paint).
    $log_tail = '';
    if (!empty($j['log']) && is_file($j['log']) && in_array($key, ['failed', 'done'], true)) {
      $log_tail = nbd_log_tail_display($j['log'], 6);
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

function nbd_pull_max_concurrent(?array $cfg = null) {
  if ($cfg === null) {
    $cfg = nbd_load_cfg();
  }
  return max(1, min(4, (int)($cfg['max_concurrent_pulls'] ?? 1)));
}

/**
 * Persist job JSON (strip ephemeral keys), temp + rename.
 */
function nbd_job_persist(array $j) {
  $id = (string)($j['id'] ?? '');
  if (!nbd_valid_id($id, 'job')) {
    return false;
  }
  $f = NBDEXPORT_RUN . '/' . $id . '.json';
  $persist = $j;
  unset($persist['alive'], $persist['output_size'], $persist['output_size_h'], $persist['orphaned'],
    $persist['orphan_pid'], $persist['pending_finalize']);
  return nbd_atomic_write($f, json_encode($persist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function nbd_job_load($id) {
  $id = (string)$id;
  if (!nbd_valid_id($id, 'job')) {
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
 * Launch prepared job script (sets status=running). Caller holds the lock.
 */
function nbd_image_launch($id) {
  return nbd_locked(function () use ($id) {
    $j = nbd_job_load($id);
    if (!$j) {
      return ['ok' => false, 'error' => 'Unknown job'];
    }
    if (nbd_job_wrapper_alive($j)) {
      return ['ok' => true, 'id' => $id, 'already' => true];
    }
    $script = nbd_job_script($id);
    $pidfile = NBDEXPORT_RUN . '/' . $id . '.pid';
    if (!is_file($script)) {
      return ['ok' => false, 'error' => 'Job script missing'];
    }
    $log = (string)($j['log'] ?? (NBDEXPORT_LOG . '/' . $id . '.log'));
    @file_put_contents($log, date('c') . " launch\n", FILE_APPEND);
    @unlink($pidfile);
    nbd_spawn('setsid nohup bash ' . escapeshellarg($script) . ' >/dev/null 2>&1 & echo $! >' . escapeshellarg($pidfile));
    $pid = 0;
    for ($i = 0; $i < 20 && $pid <= 0; $i++) {
      usleep(25000);
      $pid = (int)@file_get_contents($pidfile);
    }
    $j['pid'] = $pid;
    $j['status'] = 'running';
    $j['started_run'] = date('c');
    unset($j['finished'], $j['finished_at'], $j['ok'], $j['queue_hint'], $j['notified']);
    nbd_job_persist($j);
    return ['ok' => true, 'id' => $id, 'pid' => $pid];
  });
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
  foreach (nbd_queued_jobs_ordered() as $j) {
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
  return nbd_locked(function () use ($id, $dir) {
    $id = (string)$id;
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
  });
}

/**
 * Start next queued job(s) while under max concurrent. Locked; never called from GET.
 */
function nbd_pull_queue_kick() {
  return nbd_locked(function () {
    $cfg = nbd_load_cfg();
    if (($cfg['enabled'] ?? 'yes') !== 'yes' || getenv('NBD_NO_QUEUE_KICK') === '1') {
      return ['ok' => true, 'started' => []];
    }
    $max = nbd_pull_max_concurrent($cfg);
    $running = nbd_count_running_pull_jobs();
    $started = [];
    if ($running >= $max) {
      return ['ok' => true, 'started' => []];
    }
    foreach (nbd_queued_jobs_ordered() as $j) {
      if ($running >= $max) {
        break;
      }
      $r = nbd_image_launch($j['id']);
      if (!empty($r['ok'])) {
        $started[] = $j['id'];
        $running++;
      }
    }
    return ['ok' => true, 'started' => $started];
  });
}

/**
 * Play a queued job. $force=true starts even if at concurrency limit (user override).
 */
function nbd_image_play($id, $force = false) {
  return nbd_locked(function () use ($id, $force) {
    $j = nbd_job_load($id);
    if (!$j) {
      return ['ok' => false, 'error' => 'Unknown job'];
    }
    $j = nbd_job_derive($j);
    if (($j['status'] ?? '') !== 'queued') {
      if (!empty($j['alive'])) {
        return ['ok' => false, 'error' => 'Job is already running'];
      }
      return ['ok' => false, 'error' => 'Job already finished'];
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
  });
}

/** Outputs (final + partial) of live or queued jobs, optionally excluding one id. */
function nbd_busy_outputs($exclude_id = '') {
  $out = [];
  foreach (nbd_jobs_state() as $j) {
    if (($j['id'] ?? '') === $exclude_id) {
      continue;
    }
    $k = nbd_job_ui_status($j)['key'] ?? '';
    if (in_array($k, ['running', 'paused', 'queued'], true)) {
      foreach (['output', 'partial'] as $f) {
        if (!empty($j[$f])) {
          $out[] = (string)$j[$f];
        }
      }
    }
  }
  return $out;
}

/**
 * Create a Pull job (queued or started).
 *
 * The convert writes OUT.partial and renames it to OUT only on success; a failed or
 * stopped job removes the partial. An existing OUT is replaced only with $overwrite.
 */
function nbd_image_start($url, $output, $format = 'qcow2', $overwrite = false) {
  return nbd_locked(function () use ($url, $output, $format, $overwrite) {
    nbd_ensure_runtime_dirs();
    $cfg = nbd_load_cfg();
    if (($cfg['enabled'] ?? 'yes') !== 'yes') {
      return ['ok' => false, 'error' => 'Plugin is disabled.'];
    }
    $tools = nbd_detect_tools();
    if ($tools['qemu_img'] === '') {
      return ['ok' => false, 'error' => 'qemu-img not found.'];
    }
    $format = strtolower(trim((string)$format));
    if (!in_array($format, ['qcow2', 'raw'], true)) {
      return ['ok' => false, 'error' => 'Format must be qcow2 or raw.'];
    }
    $sv = nbd_validate_pull_source($url);
    if (empty($sv['ok'])) {
      return $sv;
    }
    $url = $sv['src'];
    $source_type = $sv['type'];

    // Same source already running or queued — refuse duplicate
    foreach (nbd_jobs_state() as $j) {
      $k = nbd_job_ui_status($j)['key'] ?? '';
      if (in_array($k, ['running', 'queued', 'paused'], true) && ($j['url'] ?? '') === $url) {
        return [
          'ok' => false,
          'error' => 'A Pull of this source is already ' . $k . ' (' . ($j['id'] ?? '?')
            . '). Starting another copy of the same disk fights the array and the WebUI.',
        ];
      }
    }

    $ov = nbd_validate_output($output, $source_type === 'nbd' ? '' : $url, (bool)$overwrite, nbd_busy_outputs());
    if (empty($ov['ok'])) {
      return $ov;
    }
    $output = $ov['output'];
    $partial = $ov['partial'];

    $max = nbd_pull_max_concurrent($cfg);
    $array_like = nbd_path_is_array_like($output);

    $id = 'job-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    $logfile = NBDEXPORT_LOG . '/' . $id . '.log';
    $script = nbd_job_script($id);

    $io_class = ($cfg['pull_io_class'] ?? 'idle') === 'best-effort' ? 'best-effort' : 'idle';
    $nice = max(0, min(19, (int)($cfg['pull_nice'] ?? 10)));
    $ionice_args = ($io_class === 'idle') ? '-c3' : '-c2 -n7';
    $progressfile = NBDEXPORT_RUN . '/' . $id . '.progress';
    $progresshist = NBDEXPORT_RUN . '/' . $id . '.progress.hist';
    // Progress: qemu-img -p writes CR updates to stderr. Do NOT send SIGUSR1 without -p
    // (QEMU 10 on Unraid: USR1 = default terminate → exit 138). Exec so $! is qemu-img
    // (not ionice). Poll PROGRAW for (N/100%) — no process-substitution consumer.
    // Write OUT.partial, rename on success; the EXIT trap removes the partial on failure
    // and calls the plugin hook (persist finish + start the next queued job).
    $sh = "#!/bin/bash\nset -uo pipefail\n"
      . 'LOG=' . escapeshellarg($logfile) . "\n"
      . 'PROG=' . escapeshellarg($progressfile) . "\n"
      . 'PROGHIST=' . escapeshellarg($progresshist) . "\n"
      . 'PROGRAW=' . escapeshellarg($progressfile . '.raw') . "\n"
      . 'SRC=' . escapeshellarg($url) . "\n"
      . 'OUT=' . escapeshellarg($output) . "\n"
      . 'PART=' . escapeshellarg($partial) . "\n"
      . 'IMG=' . escapeshellarg($tools['qemu_img']) . "\n"
      . 'FMT=' . escapeshellarg($format) . "\n"
      . 'NICE_N=' . (int)$nice . "\n"
      . 'IONICE_ARGS=' . escapeshellarg($ionice_args) . "\n"
      . 'HOOK=' . escapeshellarg(NBDEXPORT_HOOK) . "\n"
      . 'JOBID=' . escapeshellarg($id) . "\n"
      . 'DONE=0' . "\n"
      . 'PHPBIN=$(command -v php || echo /usr/bin/php)' . "\n"
      . 'on_exit() { if [ "$DONE" != 1 ]; then rm -f -- "$PART"; fi; if [ -f "$HOOK" ]; then "$PHPBIN" "$HOOK" job "$JOBID" >/dev/null 2>&1; fi; }' . "\n"
      . 'trap on_exit EXIT' . "\n"
      . "trap 'exit 143' TERM INT HUP" . "\n"
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
      . '    if run_img "$IMG" info -f raw "$SRC" >>"$LOG" 2>&1; then break; fi' . "\n"
      . '    sleep 5' . "\n"
      . '    if [ "$i" -eq 60 ]; then fail wait_src; fi' . "\n"
      . '  done' . "\n"
      . 'else' . "\n"
      . '  run_img "$IMG" info -f raw "$SRC" >>"$LOG" 2>&1 || true' . "\n"
      . 'fi' . "\n"
      . 'LAST_PCT_X10=-1' . "\n"
      . ': >"$PROGHIST"' . "\n"
      . ': >"$PROGRAW"' . "\n"
      . 'echo "pct=0" >"$PROG"' . "\n"
      . 'set +e' . "\n"
      . 'SRC_FMT=raw' . "\n"
      . 'rm -f -- "$PART"' . "\n"
      . '[ -e "$PART" ] && fail partial_exists' . "\n"
      . '# Subshell + exec → $! is qemu-img. -p progress → PROGRAW (+ LOG fallback; some builds use stdout).' . "\n"
      . '(' . "\n"
      . '  if [ "$HAVE_IONICE" = 1 ]; then' . "\n"
      . '    if [ "${#STDBUF[@]}" -gt 0 ]; then' . "\n"
      . '      exec ionice $IONICE_ARGS nice -n "$NICE_N" "${STDBUF[@]}" "$IMG" convert -p -f "$SRC_FMT" -O "$FMT" -t writeback -W "$SRC" "$PART"' . "\n"
      . '    else' . "\n"
      . '      exec ionice $IONICE_ARGS nice -n "$NICE_N" "$IMG" convert -p -f "$SRC_FMT" -O "$FMT" -t writeback -W "$SRC" "$PART"' . "\n"
      . '    fi' . "\n"
      . '  else' . "\n"
      . '    if [ "${#STDBUF[@]}" -gt 0 ]; then' . "\n"
      . '      exec nice -n "$NICE_N" "${STDBUF[@]}" "$IMG" convert -p -f "$SRC_FMT" -O "$FMT" -t writeback -W "$SRC" "$PART"' . "\n"
      . '    else' . "\n"
      . '      exec nice -n "$NICE_N" "$IMG" convert -p -f "$SRC_FMT" -O "$FMT" -t writeback -W "$SRC" "$PART"' . "\n"
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
      . 'if [ "${CONV_RC}" -ne 0 ]; then fail convert rc=$CONV_RC; fi' . "\n"
      . '[ -f "$PART" ] && [ ! -L "$PART" ] || fail rename' . "\n"
      . 'mv -f -- "$PART" "$OUT" || fail rename' . "\n"
      . 'DONE=1' . "\n"
      . 'echo "$(date -Iseconds) progress (100/100%)" >>"$LOG"' . "\n"
      . 'echo "pct=100" >"$PROG"' . "\n"
      . 'echo "$(date +%s) 100" >>"$PROGHIST"' . "\n"
      . 'if [ "$FMT" = qcow2 ]; then run_img "$IMG" check "$OUT" >>"$LOG" 2>&1 || true; fi' . "\n"
      . 'run_img "$IMG" info -f "$FMT" "$OUT" >>"$LOG" 2>&1' . "\n"
      . 'echo NBD_JOB_OK >>"$LOG"' . "\n"
      . 'echo "$(date -Iseconds) job done" >>"$LOG"' . "\n";

    if (!nbd_atomic_write($script, $sh)) {
      return ['ok' => false, 'error' => 'Cannot write job script'];
    }
    @chmod($script, 0700);
    @file_put_contents($logfile, date('c') . " prepared\n");

    $running = nbd_count_running_pull_jobs();
    $queue = ($running >= $max);

    // Extra warn when queueing behind an array write, or this job is array-like
    $queue_hint = 'Waiting for a free Pull slot (max ' . $max . '). Open Status → Play to start, or it starts when the running job finishes.';
    if ($array_like) {
      $queue_hint .= ' Output is on an array path (/mnt/disk* or /mnt/user*) — concurrent array Pulls contend for parity and can stall the WebUI. Prefer /mnt/cache for large images.';
    }

    $state = [
      'id' => $id,
      'url' => $url,
      'output' => $output,
      'partial' => $partial,
      'overwrite' => (bool)$overwrite,
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
    if (!empty($ov['exists'])) {
      $out['warn'] = trim(($out['warn'] ?? '') . ' Existing ' . $output . ' is replaced when the Pull finishes.');
    }
    return $out;
  });
}

/**
 * Stop a Pull job: TERM the verified wrapper's process group and any qemu-img convert
 * writing this job's file, then KILL leftovers. Removes the partial output.
 */
function nbd_image_stop($id) {
  return nbd_locked(function () use ($id) {
    $id = (string)$id;
    // External converts: ext-<pid> (only a real qemu-img convert)
    if (strpos($id, 'ext-') === 0) {
      $pid = nbd_external_convert_pid($id);
      if ($pid <= 0) {
        return ['ok' => false, 'error' => 'External qemu-img convert not found'];
      }
      nbd_kill_verified($pid, 'nbd_pid_is_qemu_img_convert');
      nbd_signal($pid, 18);
      return ['ok' => true, 'external' => true, 'killed' => [$pid]];
    }
    if (!nbd_valid_id($id, 'job')) {
      return ['ok' => false, 'error' => 'Invalid job id'];
    }
    $j = nbd_job_load($id);
    if (!is_array($j)) {
      return ['ok' => false, 'error' => 'Unknown job'];
    }

    // Cancel queued job (never launched)
    if (($j['status'] ?? '') === 'queued' && empty($j['finished'])) {
      $j['fail_code'] = 'cancelled_while_queued';
      $j['fail_reason'] = 'Cancelled while queued';
      $log = (string)($j['log'] ?? '');
      if ($log !== '') {
        @file_put_contents($log, "\nNBD_JOB_FAIL cancelled_while_queued\n", FILE_APPEND);
      }
      nbd_job_finalize($j, false);
      nbd_pull_queue_kick();
      return ['ok' => true, 'cancelled_queue' => true];
    }

    $pids = nbd_job_pids($j);
    if (nbd_job_wrapper_alive($j)) {
      // setsid made the wrapper the process-group leader
      nbd_signal(-(int)$j['pid'], 15);
      nbd_signal(-(int)$j['pid'], 18); // wake a paused job so TERM is handled
    }
    foreach ($pids as $p) {
      nbd_signal($p, 15);
      nbd_signal($p, 18);
    }
    usleep(300000);
    foreach ($pids as $p) {
      if (nbd_pid_alive($p) && (nbd_pid_is_qemu_img_convert($p) || nbd_pid_is_wrapper($p, nbd_job_script($id))
          || in_array($p, nbd_pid_children((int)($j['pid'] ?? 0)), true))) {
        nbd_signal($p, 9);
      }
    }

    $j['fail_code'] = 'stopped_by_user';
    $j['fail_reason'] = 'Stopped by user';
    $log = (string)($j['log'] ?? '');
    if (empty($j['finished']) && $log !== '') {
      @file_put_contents($log, "\nNBD_JOB_FAIL stopped_by_user\n", FILE_APPEND);
    }
    if (empty($j['finished'])) {
      nbd_job_finalize($j, false);
    }
    nbd_pull_queue_kick();
    return ['ok' => true, 'killed' => $pids];
  });
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
  return nbd_locked(function () use ($id) {
    $id = (string)$id;
    if (!nbd_valid_id($id, 'job')) {
      return ['ok' => false, 'error' => 'Invalid job id'];
    }
    $j = nbd_job_load($id);
    if (is_array($j) && !nbd_job_is_clearable(nbd_job_derive($j))) {
      return ['ok' => false, 'error' => 'Stop or cancel the job before clearing'];
    }
    foreach (['.json', '.pid', '.sh', '.progress', '.progress.hist', '.progress.raw', '.rates.json'] as $sfx) {
      $f = NBDEXPORT_RUN . '/' . $id . $sfx;
      if (is_file($f)) {
        @unlink($f);
      }
    }
    // Log file intentionally kept — see Logs tab / nbd_logs_clear().
    return ['ok' => true, 'id' => $id];
  });
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
  $beacon_log = nbd_beacon_logfile();
  if (nbd_beacon_running() && is_file($beacon_log)) {
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
  return nbd_locked(function () use ($ids, $all_finished, $delete_outputs) {
    $cleared = [];
    $deleted = [];
    $skipped = [];
    $want = [];
    foreach ($ids as $id) {
      $id = (string)$id;
      if (nbd_valid_id($id, 'job')) {
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
  });
}

/**
 * Delete a finished job's output image (and any leftover OUT.partial) on disk.
 * Only regular files under /mnt (or /tmp for jobs from older releases); never follows
 * a symlink. Does not remove the job card (Remove from list does that).
 */
function nbd_job_delete_output($id) {
  return nbd_locked(function () use ($id) {
    $id = (string)$id;
    if (!nbd_valid_id($id, 'job')) {
      return ['ok' => false, 'error' => 'Invalid job id'];
    }
    $j = nbd_job_load($id);
    if (!is_array($j)) {
      return ['ok' => false, 'error' => 'Job not found'];
    }
    if (!nbd_job_is_clearable(nbd_job_derive($j))) {
      return ['ok' => false, 'error' => 'Stop the job before deleting its output file'];
    }
    $out = trim((string)($j['output'] ?? ''));
    if ($out === '' || $out === '(unknown)' || nbd_path_has_dots($out)) {
      return ['ok' => false, 'error' => 'No output path on this job'];
    }
    if (strpos($out, NBDEXPORT_MNT . '/') !== 0 && strpos($out, '/tmp/') !== 0) {
      return ['ok' => false, 'error' => 'Refusing to delete outside /mnt or /tmp'];
    }
    $part = (string)($j['partial'] ?? '');
    if ($part !== '' && is_file($part) && !is_link($part)) {
      @unlink($part);
    }
    if (is_link($out)) {
      return ['ok' => false, 'error' => 'Output is a symlink — not deleting: ' . $out];
    }
    if (!file_exists($out)) {
      return ['ok' => true, 'id' => $id, 'missing' => true, 'path' => $out];
    }
    if (filetype($out) !== 'file') {
      return ['ok' => false, 'error' => 'Not a regular file: ' . $out];
    }
    $sz = @filesize($out);
    if (!@unlink($out)) {
      return ['ok' => false, 'error' => 'unlink failed: ' . $out];
    }
    return ['ok' => true, 'id' => $id, 'path' => $out, 'bytes' => $sz !== false ? (int)$sz : null];
  });
}

/**
 * Start a new Pull using a prior job's (or edited) source/output/format.
 *
 * - An existing output is replaced only with $overwrite (the new job writes OUT.partial
 *   and renames on success, so the old file stays until then).
 * - Jobs from releases before partial files wrote OUT directly: a failed one left an
 *   incomplete OUT at the same path, which is removed first.
 * - On success → clear the old History card; new job is Active/Queued.
 *
 * @param array{url?:string,output?:string,format?:string} $overrides
 */
function nbd_image_retry($id, array $overrides = [], $overwrite = false) {
  return nbd_locked(function () use ($id, $overrides, $overwrite) {
    $id = (string)$id;
    if (!nbd_valid_id($id, 'job')) {
      return ['ok' => false, 'error' => 'Invalid job id'];
    }
    $j = nbd_job_load($id);
    if (!is_array($j)) {
      return ['ok' => false, 'error' => 'Job not found'];
    }
    $j = nbd_job_derive($j);
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
    $legacy_incomplete = empty($j['partial']) && empty($j['ok']);
    if ($legacy_incomplete && $output === $old_out && is_file($output) && !is_link($output)) {
      $dr = nbd_job_delete_output($id);
      if (empty($dr['ok'])) {
        return ['ok' => false, 'error' => 'Could not remove incomplete output: ' . ($dr['error'] ?? $output)];
      }
      $removed = empty($dr['missing']);
    }
    $r = nbd_image_start($url, $output, $format, $overwrite);
    if (!empty($r['ok'])) {
      $r['retried_from'] = $id;
      $r['removed_output'] = $removed;
      nbd_job_clear_one($id);
    }
    return $r;
  });
}

/** companion.json on flash for sibling plugins; written only when its content changes. */
function nbd_write_companion_marker() {
  $m = [
    'plugin' => 'NBDExport',
    'provides' => ['nbd-export', 'qemu-nbd', 'disk-imaging'],
    'version' => nbd_plugin_version(),
  ];
  return nbd_atomic_write(NBDEXPORT_CFG_DIR . '/companion.json', json_encode($m, JSON_PRETTY_PRINT) . "\n", true);
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

/** True if IPv4 $ip is inside "a.b.c.d/nn". */
function nbd_ip_in_cidr($ip, $cidr) {
  if (!preg_match('#^(\d+\.\d+\.\d+\.\d+)/(\d{1,2})$#', (string)$cidr, $m)) {
    return false;
  }
  $ipl = ip2long((string)$ip);
  $net = ip2long($m[1]);
  $bits = (int)$m[2];
  if ($ipl === false || $net === false || $bits < 0 || $bits > 32) {
    return false;
  }
  $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
  return (($ipl & $mask) === ($net & $mask));
}

/**
 * Docker bridge subnets on this host (docker0, br-<id>), refused by the beacon so
 * containers cannot enumerate Host exports. Not br0 (Unraid's LAN bridge).
 * @return string[] "a.b.c.d/nn"
 */
function nbd_beacon_deny_cidrs() {
  $out = [];
  foreach (nbd_run_lines('ip -4 -o addr show 2>/dev/null') as $line) {
    if (preg_match('/^\d+:\s+(docker\d+|br-[0-9a-f]{6,})\S*\s+inet\s+(\d+\.\d+\.\d+\.\d+)\/(\d+)/', $line, $m)) {
      $long = ip2long($m[2]);
      $bits = (int)$m[3];
      if ($long === false || $bits < 8 || $bits > 32) {
        continue;
      }
      $mask = (~0 << (32 - $bits)) & 0xFFFFFFFF;
      $out[] = long2ip($long & $mask) . '/' . $bits;
    }
  }
  return array_values(array_unique($out));
}

/** Beacon client filter: private IPv4, not a docker bridge subnet. */
function nbd_beacon_client_allowed($ip, array $deny_cidrs = []) {
  $ip = nbd_ip_normalize($ip);
  if ($ip === '' || !nbd_is_private_ipv4($ip)) {
    return false;
  }
  foreach ($deny_cidrs as $c) {
    if (nbd_ip_in_cidr($ip, $c)) {
      return false;
    }
  }
  return true;
}

/**
 * Addresses the beacon listens on: each private, specific Host bind IP.
 * Never 0.0.0.0 — exports bound to all interfaces or a public IP get no beacon.
 * @return string[]
 */
function nbd_beacon_listen_addrs() {
  $binds = [];
  foreach (nbd_exports_state() as $e) {
    if (empty($e['alive']) && empty($e['listening'])) {
      continue;
    }
    $b = nbd_ip_normalize((string)($e['bind'] ?? ''));
    if ($b === '' || nbd_ip_is_unspecified($b) || !nbd_is_private_ipv4($b)) {
      continue;
    }
    $binds[$b] = true;
  }
  ksort($binds);
  return array_keys($binds);
}

/** Beacon pid files: beacon-http-<ip>.pid (+ legacy beacon-http.pid). @return array<string,string> file => addr */
function nbd_beacon_pidfiles() {
  $out = [];
  foreach (glob(NBDEXPORT_RUN . '/beacon-http*.pid') ?: [] as $f) {
    $b = basename($f, '.pid');
    $out[$f] = (strpos($b, 'beacon-http-') === 0) ? substr($b, strlen('beacon-http-')) : '';
  }
  return $out;
}

function nbd_beacon_running() {
  foreach (array_keys(nbd_beacon_pidfiles()) as $f) {
    if (nbd_pid_is_beacon((int)@file_get_contents($f))) {
      return true;
    }
  }
  return false;
}

/**
 * Keep one php -S beacon per private Host bind IP while exports are up; stop the rest.
 * Uses php -S (no Unraid login) so peers can discover without root passwords.
 */
function nbd_beacon_ensure() {
  nbd_ensure_runtime_dirs();
  $want = nbd_beacon_listen_addrs();
  if (!$want) {
    nbd_beacon_stop();
    return ['ok' => true, 'running' => false, 'reason' => 'no private Host bind'];
  }
  $have = [];
  foreach (nbd_beacon_pidfiles() as $f => $addr) {
    $pid = (int)@file_get_contents($f);
    if ($addr !== '' && in_array($addr, $want, true) && nbd_pid_is_beacon($pid)) {
      $have[$addr] = $pid;
      continue;
    }
    nbd_kill_verified($pid, 'nbd_pid_is_beacon', 150000);
    @unlink($f);
  }

  $router = NBDEXPORT_ROOT . '/include/nbd-beacon-server.php';
  if (!is_file($router)) {
    $router = __DIR__ . '/nbd-beacon-server.php';
  }
  if (!is_file($router)) {
    return ['ok' => false, 'error' => 'beacon server script missing'];
  }
  $php = trim(nbd_run('command -v php 2>/dev/null'));
  if ($php === '' || !is_executable($php)) {
    $php = '/usr/bin/php';
  }
  if (!is_executable($php)) {
    return ['ok' => false, 'error' => 'php not found for beacon'];
  }
  $port = nbd_beacon_port();
  $log = nbd_beacon_logfile();
  $deny = implode(',', nbd_beacon_deny_cidrs());
  foreach ($want as $addr) {
    if (isset($have[$addr])) {
      continue;
    }
    $pidfile = NBDEXPORT_RUN . '/beacon-http-' . $addr . '.pid';
    nbd_spawn('NBD_BEACON_DENY=' . escapeshellarg($deny) . ' setsid nohup ' . escapeshellarg($php)
      . ' -S ' . escapeshellarg($addr . ':' . (int)$port) . ' ' . escapeshellarg($router)
      . ' >>' . escapeshellarg($log) . ' 2>&1 & echo $! >' . escapeshellarg($pidfile));
  }
  usleep(200000);
  $running = [];
  foreach (nbd_beacon_pidfiles() as $f => $addr) {
    if (nbd_pid_is_beacon((int)@file_get_contents($f))) {
      $running[] = $addr;
    }
  }
  return ['ok' => (bool)$running, 'running' => (bool)$running, 'listen' => $running, 'port' => $port];
}

/** Stop every beacon this plugin started (recorded PIDs, cmdline checked). */
function nbd_beacon_stop() {
  foreach (array_keys(nbd_beacon_pidfiles()) as $f) {
    nbd_kill_verified((int)@file_get_contents($f), 'nbd_pid_is_beacon', 150000);
    @unlink($f);
  }
  return ['ok' => true];
}

/** Containing private /24 for an IPv4, or empty. */
function nbd_ip_to_slash24($ip) {
  if (!nbd_is_private_ipv4($ip)) {
    return '';
  }
  $long = ip2long($ip);
  if ($long === false) {
    return '';
  }
  return long2ip($long & (~255)) . '/24';
}

/** Normalize posted/cfg CIDR to a private a.b.c.0/24, or empty. */
function nbd_scan_normalize_cidr($cidr) {
  $cidr = trim((string)$cidr);
  if (preg_match('#^(\d+\.\d+\.\d+\.\d+)/(\d+)$#', $cidr, $m)) {
    return nbd_ip_to_slash24($m[1]);
  }
  if (preg_match('#^(\d+\.\d+\.\d+\.\d+)$#', $cidr, $m)) {
    return nbd_ip_to_slash24($m[1]);
  }
  return '';
}

function nbd_scan_default_route_ifaces() {
  $out = [];
  $out = nbd_run_lines('ip -4 route show default 2>/dev/null');
  $ifs = [];
  foreach ($out as $line) {
    if (preg_match('/\bdev\s+(\S+)/', $line, $m)) {
      $ifs[preg_replace('/@.*$/', '', $m[1])] = true;
    }
  }
  return $ifs;
}

/**
 * Local private LANs the user may tick on Pull → Scan network.
 * Thunderbolt (and non-default-route ifaces) are checked by default; the
 * default-route LAN is left off when another private LAN exists.
 *
 * @return array[] iface, ip, cidr, extra, checked, label
 */
function nbd_scan_local_lans() {
  $def_if = nbd_scan_default_route_ifaces();
  $rows = [];
  $seen_local = [];
  $out = [];
  $out = nbd_run_lines('ip -4 -o addr show scope global 2>/dev/null');
  foreach ($out as $line) {
    if (!preg_match('/^\d+:\s+(\S+)\s+inet\s+(\d+\.\d+\.\d+\.\d+)\//', $line, $m)) {
      continue;
    }
    $if = preg_replace('/@.*$/', '', $m[1]);
    $ip = $m[2];
    if ($if === 'lo' || strpos($ip, '127.') === 0) {
      continue;
    }
    $cidr = nbd_ip_to_slash24($ip);
    if ($cidr === '') {
      continue;
    }
    $key = $cidr . '|' . $if;
    if (isset($seen_local[$key])) {
      continue;
    }
    $seen_local[$key] = true;
    $is_tb = (bool)preg_match('/^thunderbolt\d+$/', $if);
    $rows[] = [
      'iface' => $if,
      'ip' => $ip,
      'cidr' => $cidr,
      'extra' => false,
      'thunderbolt' => $is_tb,
      'default_route' => isset($def_if[$if]),
      'label' => $cidr . ' (' . $if . ')',
    ];
  }

  $cfg = nbd_load_cfg();
  $extra = trim((string)($cfg['scan_extra_subnets'] ?? ''));
  $have_cidr = [];
  foreach ($rows as $r) {
    $have_cidr[$r['cidr']] = true;
  }
  if ($extra !== '') {
    foreach (preg_split('/[\s,;]+/', $extra, -1, PREG_SPLIT_NO_EMPTY) as $raw) {
      $cidr = nbd_scan_normalize_cidr($raw);
      if ($cidr === '' || isset($have_cidr[$cidr])) {
        continue;
      }
      $have_cidr[$cidr] = true;
      $rows[] = [
        'iface' => 'extra',
        'ip' => '',
        'cidr' => $cidr,
        'extra' => true,
        'thunderbolt' => false,
        'default_route' => false,
        'label' => $cidr . ' (extra)',
      ];
    }
  }

  $local_n = 0;
  foreach ($rows as $r) {
    if (empty($r['extra'])) {
      $local_n++;
    }
  }
  $any_checked = false;
  foreach ($rows as &$r) {
    if (!empty($r['extra'])) {
      $r['checked'] = false;
      continue;
    }
    if ($local_n <= 1) {
      $r['checked'] = true;
    } elseif (!empty($r['thunderbolt']) || empty($r['default_route'])) {
      $r['checked'] = true;
    } else {
      $r['checked'] = false;
    }
    if ($r['checked']) {
      $any_checked = true;
    }
  }
  unset($r);
  if (!$any_checked) {
    foreach ($rows as &$r) {
      if (empty($r['extra'])) {
        $r['checked'] = true;
      }
    }
    unset($r);
  }
  return $rows;
}

/** CIDRs Scan may accept from POST (local private + cfg extra). */
function nbd_scan_allowed_cidrs() {
  $ok = [];
  foreach (nbd_scan_local_lans() as $r) {
    if (!empty($r['cidr'])) {
      $ok[$r['cidr']] = true;
    }
  }
  return $ok;
}

/** @return string[] list of "a.b.c.0/24" */
function nbd_scan_subnets() {
  return array_keys(nbd_scan_allowed_cidrs());
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
  if ($list === $prev) {
    return; // unchanged — no flash write
  }
  nbd_atomic_write(NBDEXPORT_CFG_DIR . '/scan-peers.json', json_encode([
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

/** Max beacon body read (bytes) and max exports taken from one peer. */
function nbd_scan_limits() {
  return ['body' => 65536, 'exports_per_peer' => 16, 'info_probes' => 32, 'text' => 128];
}

/**
 * Fetch peer beacon JSON if present. Body capped; anything larger is ignored.
 */
function nbd_fetch_beacon($ip, $port = null, $timeout_s = 0.35) {
  $port = $port === null ? nbd_beacon_port() : (int)$port;
  $lim = nbd_scan_limits();
  $url = 'http://' . $ip . ':' . $port . '/';
  $ctx = stream_context_create([
    'http' => [
      'method' => 'GET',
      'timeout' => $timeout_s,
      'header' => "Accept: application/json\r\nUser-Agent: NBDExport-scan\r\n",
      'ignore_errors' => true,
      'follow_location' => 0,
      'max_redirects' => 0,
    ],
  ]);
  $raw = @file_get_contents($url, false, $ctx, 0, $lim['body'] + 1);
  if (!is_string($raw) || $raw === '' || strlen($raw) > $lim['body']) {
    return null;
  }
  $j = @json_decode($raw, true, 8);
  if (!is_array($j) || ($j['plugin'] ?? '') !== 'NBDExport') {
    return null;
  }
  return $j;
}

/**
 * Build the URL for an export a peer advertised. The host is always the scanned IP and the
 * port must be an integer; the peer's own url/bind strings are never used.
 * @return string '' if the entry is unusable
 */
function nbd_scan_export_url($ip, array $ex) {
  if (!nbd_is_private_ipv4($ip)) {
    return '';
  }
  $port = $ex['port'] ?? null;
  if (!is_int($port) && !(is_string($port) && ctype_digit($port))) {
    return '';
  }
  $port = (int)$port;
  if ($port < 1 || $port > 65535) {
    return '';
  }
  $name = (string)($ex['name'] ?? '');
  if ($name !== '' && !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $name)) {
    return '';
  }
  return 'nbd://' . $ip . ':' . $port . ($name !== '' ? '/' . $name : '');
}

/**
 * qemu-img info for an NBD export (size only). Uses --image-opts with a fixed
 * driver=raw over nbd, so no format probing and no other qemu protocol can be reached.
 */
function nbd_probe_nbd_info($url) {
  if (!preg_match('#^nbd://(\d+\.\d+\.\d+\.\d+):(\d{1,5})(?:/([A-Za-z0-9._-]{1,64}))?$#', (string)$url, $m)) {
    return null;
  }
  $tools = nbd_detect_tools();
  $img = $tools['qemu_img'] ?? '';
  if ($img === '') {
    return null;
  }
  $opts = 'driver=raw,file.driver=nbd,file.server.type=inet,file.server.host=' . $m[1]
    . ',file.server.port=' . (int)$m[2] . (isset($m[3]) && $m[3] !== '' ? ',file.export=' . $m[3] : '');
  $cmd = 'timeout 2 ' . escapeshellarg($img) . ' info --output=json --image-opts '
    . escapeshellarg($opts) . ' 2>/dev/null';
  $raw = nbd_run($cmd);
  if ($raw === '' || strlen($raw) > 65536) {
    return null;
  }
  $j = @json_decode($raw, true);
  if (!is_array($j)) {
    return null;
  }
  return [
    'format' => 'raw',
    'virtual_size' => isset($j['virtual-size']) ? (int)$j['virtual-size'] : 0,
    'virtual_size_h' => isset($j['virtual-size']) ? nbd_format_bytes((int)$j['virtual-size']) : '',
  ];
}

/** Peer-supplied text: string, control chars stripped, length capped. */
function nbd_scan_text($v) {
  $lim = nbd_scan_limits();
  $s = is_scalar($v) ? (string)$v : '';
  $s = preg_replace('/[\x00-\x1F\x7F]/', '', $s);
  return nbd_str_cap($s, $lim['text']);
}

/**
 * Exports listed by one peer beacon → safe rows (rebuilt URL, capped count/text).
 * @param int $probe_budget remaining qemu-img info probes (decremented)
 */
function nbd_scan_peer_exports($ip, array $beacon, $probe_info, &$probe_budget) {
  $lim = nbd_scan_limits();
  $rows = [];
  $list = (isset($beacon['exports']) && is_array($beacon['exports'])) ? $beacon['exports'] : [];
  foreach (array_slice($list, 0, $lim['exports_per_peer']) as $ex) {
    if (!is_array($ex)) {
      continue;
    }
    $url = nbd_scan_export_url($ip, $ex);
    if ($url === '') {
      continue;
    }
    $inf = null;
    if ($probe_info && $probe_budget > 0) {
      $probe_budget--;
      $inf = nbd_probe_nbd_info($url);
    }
    $rows[] = [
      'url' => $url,
      'port' => (int)$ex['port'],
      'read_only' => array_key_exists('read_only', $ex) ? !empty($ex['read_only']) : null,
      'label' => nbd_scan_text($ex['label'] ?? ''),
      'device_name' => nbd_scan_text($ex['device_name'] ?? ''),
      'info' => $inf,
    ];
  }
  return $rows;
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
 * Scan user-selected private /24s for plugin beacons (default) and optional NBD ports.
 *
 * @param int[]|null $nbd_ports
 * @param bool $probe_info qemu-img info on beacon-advertised URLs only
 * @param string[] $cidrs posted CIDRs; must be in nbd_scan_allowed_cidrs()
 * @param string $mode beacon|nbd
 * @return array{ok:bool,subnets:string[],hits:array,seconds:float,error?:string}
 */
function nbd_scan_network(?array $nbd_ports = null, $probe_info = true, ?array $cidrs = null, $mode = 'beacon') {
  $t0 = microtime(true);
  nbd_ensure_runtime_dirs();
  $mode = ($mode === 'nbd') ? 'nbd' : 'beacon';
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
  $probe_ports = [$beacon_port];
  if ($mode === 'nbd') {
    $probe_ports = array_values(array_unique(array_merge($nbd_ports, [$beacon_port])));
  }

  $allowed = nbd_scan_allowed_cidrs();
  $subnets = [];
  foreach ((array)$cidrs as $raw) {
    $cidr = nbd_scan_normalize_cidr($raw);
    if ($cidr !== '' && isset($allowed[$cidr])) {
      $subnets[] = $cidr;
    }
  }
  $subnets = array_values(array_unique($subnets));
  if (!$subnets) {
    return [
      'ok' => false,
      'error' => 'Select a LAN to scan',
      'subnets' => [],
      'hits' => [],
      'seconds' => 0,
      'mode' => $mode,
    ];
  }

  $self_ips = [];
  foreach (nbd_list_bind_ips() as $row) {
    if (!empty($row['ip'])) {
      $self_ips[$row['ip']] = true;
    }
  }

  $ips = [];
  $selected = array_flip($subnets);
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
  foreach (nbd_scan_known_peers() as $ip) {
    $slash = nbd_ip_to_slash24($ip);
    if ($slash !== '' && isset($selected[$slash]) && !isset($self_ips[$ip])) {
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
  $probe_budget = nbd_scan_limits()['info_probes'];
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
    if (is_array($beacon)) {
      $exports = nbd_scan_peer_exports($ip, $beacon, $probe_info, $probe_budget);
    } else {
      foreach ($info['nbd'] as $port) {
        $exports[] = [
          'url' => 'nbd://' . $ip . ':' . (int)$port,
          'port' => (int)$port,
          'read_only' => null,
          'label' => '',
          'device_name' => '',
          'info' => null,
        ];
      }
    }

    if (!$exports && !is_array($beacon)) {
      continue;
    }

    $hits[] = [
      'ip' => $ip,
      'kind' => is_array($beacon) ? 'peer' : 'nbd_open',
      'hostname' => is_array($beacon) ? nbd_scan_text($beacon['hostname'] ?? '') : '',
      'version' => is_array($beacon) ? nbd_scan_text($beacon['version'] ?? '') : '',
      'plugin' => is_array($beacon) ? 'NBDExport' : '',
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
    'nbd_ports' => $mode === 'nbd' ? $nbd_ports : [],
    'beacon_port' => $beacon_port,
    'mode' => $mode,
    'hits' => $hits,
    'seconds' => round(microtime(true) - $t0, 2),
  ];
}
