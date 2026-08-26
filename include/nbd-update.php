<?php
/**
 * POST actions for NBD Export. $save = false so update.php does not pollute cfg.
 */
$save = false;

require_once '/usr/local/emhttp/plugins/NBDExport/include/nbd-lib.php';

$action = $_POST['nbd_action'] ?? '';

function nbd_flash($msg) {
  // Unraid progress frame — plain text is fine
  echo htmlspecialchars($msg) . "\n";
}

try {
  switch ($action) {
    case 'save_cfg':
      $cfg = nbd_load_cfg();
      foreach ([
        'enabled', 'default_read_only', 'default_port', 'allow_bind_all', 'destructive_mode',
        'rehydrate_on_start', 'ud_status_overlay',
        'max_concurrent_pulls', 'pull_io_class', 'pull_nice',
      ] as $k) {
        if (isset($_POST[$k])) {
          $cfg[$k] = trim((string)$_POST[$k]);
        }
      }
      // Normalize yes/no
      foreach ([
        'enabled', 'default_read_only', 'allow_bind_all', 'destructive_mode',
        'rehydrate_on_start', 'ud_status_overlay',
      ] as $k) {
        if (isset($cfg[$k])) {
          $cfg[$k] = ($cfg[$k] === 'yes') ? 'yes' : 'no';
        }
      }
      // Empty Default port → protocol default 10809
      $dp = (int)($cfg['default_port'] ?? 0);
      if ($dp < 1024 || $dp > 65535) {
        $cfg['default_port'] = '10809';
      } else {
        $cfg['default_port'] = (string)$dp;
      }
      $mcp = (int)($cfg['max_concurrent_pulls'] ?? 1);
      if ($mcp < 1) {
        $mcp = 1;
      }
      if ($mcp > 4) {
        $mcp = 4;
      }
      $cfg['max_concurrent_pulls'] = (string)$mcp;
      $pic = strtolower((string)($cfg['pull_io_class'] ?? 'idle'));
      $cfg['pull_io_class'] = ($pic === 'best-effort') ? 'best-effort' : 'idle';
      $pn = (int)($cfg['pull_nice'] ?? 10);
      if ($pn < 0) {
        $pn = 0;
      }
      if ($pn > 19) {
        $pn = 19;
      }
      $cfg['pull_nice'] = (string)$pn;
      if (($cfg['enabled'] ?? 'yes') !== 'yes') {
        nbd_stop_all_exports();
      }
      nbd_write_cfg($cfg);
      nbd_write_companion_marker();
      // Soft head hook so opt-in badges can load on Main → Unassigned Devices
      if (($cfg['ud_status_overlay'] ?? 'no') === 'yes') {
        nbd_ud_overlay_inject();
      }
      $msg = 'NBD Export: settings saved.';
      // Destructive Off only blocks *new* Hosts — live writable/risky exports keep
      // running until Stop / Stop all writable / Stop all / Enable=No.
      if (($cfg['destructive_mode'] ?? 'no') === 'yes') {
        $msg .= ' WARNING: Destructive mode is ON (writable / array-cache-pool / mounted / boot hosts allowed).';
      } else {
        $nw = nbd_count_writable_exports();
        if ($nw > 0) {
          $msg .= ' Note: Destructive is Off, but ' . $nw
            . ' writable host(s) still listening — use Stop all writable hosts if unintended.';
        }
      }
      if (($cfg['ud_status_overlay'] ?? 'no') === 'yes') {
        $msg .= ' Unassigned Devices status badges: ON (best-effort overlay).';
      }
      nbd_flash($msg);
      break;

    case 'export_start':
      $dev = trim((string)($_POST['device'] ?? ''));
      // Multi-bind: bind[] checkboxes, or legacy single bind / comma-separated
      $bind_raw = $_POST['bind'] ?? [];
      if (!is_array($bind_raw)) {
        $bind_raw = (string)$bind_raw !== '' ? preg_split('/[\s,]+/', (string)$bind_raw) : [];
      }
      $binds = [];
      foreach ($bind_raw as $b) {
        $b = trim((string)$b);
        if ($b !== '' && !in_array($b, $binds, true)) {
          $binds[] = $b;
        }
      }
      // Empty / invalid → Settings default_port, else protocol default 10809
      $port_raw = trim((string)($_POST['port'] ?? ''));
      $port = ($port_raw === '') ? 0 : (int)$port_raw;
      if ($port < 1024 || $port > 65535) {
        $cfg_port = (int)(nbd_load_cfg()['default_port'] ?? 10809);
        $port = ($cfg_port >= 1024 && $cfg_port <= 65535) ? $cfg_port : 10809;
      }
      $ro = (($_POST['read_only'] ?? 'yes') === 'yes');
      $label = trim((string)($_POST['label'] ?? ''));
      $confirm = (($_POST['nbd_confirm'] ?? '') === 'yes');
      if (!$binds) {
        nbd_flash('NBD Export: ERROR — select at least one bind IP (network to host on).');
        break;
      }
      $urls = [];
      $errs = [];
      foreach ($binds as $bind) {
        $r = nbd_export_start($dev, $bind, $port, $ro, $label, 2, $confirm);
        if (empty($r['ok'])) {
          $errs[] = $bind . ': ' . ($r['error'] ?? 'start failed');
        } else {
          $urls[] = $r['url'] ?? ('nbd://' . $bind . ':' . $port);
        }
      }
      if ($urls) {
        nbd_memory_remember_host($dev, $binds[0], $port, $ro, $label, $binds);
        $mode = $ro ? 'read-only' : 'WRITABLE';
        nbd_flash('NBD Export: hosted ' . implode(', ', $urls) . ' (' . $mode . ')');
      }
      if ($errs) {
        nbd_flash('NBD Export: ERROR — ' . implode('; ', $errs));
      }
      break;

    case 'export_stop':
      $id = trim((string)($_POST['export_id'] ?? ''));
      $r = nbd_export_stop($id);
      nbd_flash(empty($r['ok']) ? ('ERROR — ' . ($r['error'] ?? 'stop failed')) : ('Stopped export ' . $id));
      break;

    case 'export_stop_all':
      nbd_stop_all_exports();
      nbd_flash('NBD Export: all exports stopped.');
      break;

    case 'export_stop_writables':
      $r = nbd_stop_writable_exports();
      $n = (int)($r['count'] ?? 0);
      nbd_flash($n
        ? ('NBD Export: emergency stop — halted ' . $n . ' writable host(s).')
        : 'NBD Export: no writable hosts were listening.');
      break;

    case 'image_start':
      $url = trim((string)($_POST['nbd_url'] ?? ''));
      $out = trim((string)($_POST['output'] ?? ''));
      $fmt = trim((string)($_POST['format'] ?? 'qcow2'));
      $r = nbd_image_start($url, $out, $fmt);
      if (empty($r['ok'])) {
        nbd_flash('NBD Image: ERROR — ' . ($r['error'] ?? 'start failed'));
      } else {
        nbd_memory_remember_pull($url, $out, $fmt);
        if (!empty($r['queued'])) {
          nbd_flash('NBD Image: queued ' . ($r['id'] ?? '') . ' — ' . ($r['warn'] ?? 'see Status → Play'));
        } else {
          $msg = 'NBD Image: job started ' . ($r['id'] ?? '');
          if (!empty($r['warn'])) {
            $msg .= ' — ' . $r['warn'];
          }
          nbd_flash($msg);
        }
      }
      break;

    case 'image_stop':
      $id = trim((string)($_POST['job_id'] ?? ''));
      $r = nbd_image_stop($id);
      nbd_flash(empty($r['ok']) ? ('ERROR — ' . ($r['error'] ?? 'stop failed')) : ('Stopped job ' . $id));
      break;

    case 'image_play':
      $id = trim((string)($_POST['job_id'] ?? ''));
      $force = (isset($_POST['force']) && (string)$_POST['force'] === 'yes');
      $r = nbd_image_play($id, $force);
      if (empty($r['ok'])) {
        nbd_flash('NBD Image: ERROR — ' . ($r['error'] ?? 'play failed'));
      } else {
        $msg = 'NBD Image: started ' . $id . ($force ? ' (forced)' : '');
        if (!empty($r['warn'])) {
          $msg .= ' — ' . $r['warn'];
        }
        nbd_flash($msg);
      }
      break;

    case 'preset_save_host':
      $name = trim((string)($_POST['preset_name'] ?? ''));
      $bind_raw = $_POST['bind'] ?? [];
      if (!is_array($bind_raw)) {
        $bind_raw = (string)$bind_raw !== '' ? preg_split('/[\s,]+/', (string)$bind_raw) : [];
      }
      $binds = [];
      foreach ($bind_raw as $b) {
        $b = trim((string)$b);
        if ($b !== '' && !in_array($b, $binds, true)) {
          $binds[] = $b;
        }
      }
      $fields = [
        'device' => trim((string)($_POST['device'] ?? '')),
        'bind' => $binds ? $binds[0] : '',
        'binds' => $binds,
        'port' => (int)($_POST['port'] ?? 10809),
        'read_only' => (($_POST['read_only'] ?? 'yes') === 'yes') ? 'yes' : 'no',
        'label' => trim((string)($_POST['label'] ?? '')),
      ];
      $r = nbd_memory_save_preset($name, 'host', $fields);
      nbd_flash(empty($r['ok']) ? ('ERROR — ' . ($r['error'] ?? 'save failed')) : ('Saved host preset: ' . ($r['name'] ?? $name)));
      break;

    case 'preset_save_pull':
      $name = trim((string)($_POST['preset_name'] ?? ''));
      $fields = [
        'nbd_url' => trim((string)($_POST['nbd_url'] ?? '')),
        'output' => trim((string)($_POST['output'] ?? '')),
        'format' => trim((string)($_POST['format'] ?? 'qcow2')),
      ];
      $r = nbd_memory_save_preset($name, 'pull', $fields);
      nbd_flash(empty($r['ok']) ? ('ERROR — ' . ($r['error'] ?? 'save failed')) : ('Saved pull preset: ' . ($r['name'] ?? $name)));
      break;

    case 'preset_delete':
      $name = trim((string)($_POST['preset_name'] ?? ''));
      $r = nbd_memory_delete_preset($name);
      nbd_flash(empty($r['ok']) ? ('ERROR — ' . ($r['error'] ?? 'delete failed')) : ('Deleted preset: ' . $name));
      break;

    case 'config_export_flash':
      $r = nbd_config_export_to_flash('');
      if (empty($r['ok'])) {
        nbd_flash('ERROR — ' . ($r['error'] ?? 'export failed'));
      } else {
        nbd_flash('NBD Export: config written to ' . ($r['path'] ?? '') . ' (outside plugin dir — safe across uninstall)');
      }
      break;

    case 'config_import':
      $path = trim((string)($_POST['import_path'] ?? ''));
      $r = nbd_config_import_from_path($path);
      nbd_flash(empty($r['ok'])
        ? ('ERROR — ' . ($r['error'] ?? 'import failed'))
        : ('NBD Export: ' . ($r['msg'] ?? 'imported') . '. Refresh this page.'));
      break;

    default:
      nbd_flash('NBD Export: unknown action');
  }
} catch (Throwable $e) {
  nbd_flash('NBD Export: exception — ' . $e->getMessage());
}
