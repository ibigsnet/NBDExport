<?php
/**
 * POST actions for NBD Export. $save = false so update.php does not pollute cfg.
 */
$save = false;

require_once '/usr/local/emhttp/plugins/NbdExport/include/nbd-lib.php';

$action = $_POST['nbd_action'] ?? '';

function nbd_flash($msg) {
  // Unraid progress frame — plain text is fine
  echo htmlspecialchars($msg) . "\n";
}

try {
  switch ($action) {
    case 'save_cfg':
      $cfg = nbd_load_cfg();
      foreach (['enabled', 'default_read_only', 'default_port', 'allow_bind_all', 'destructive_mode', 'rehydrate_on_start'] as $k) {
        if (isset($_POST[$k])) {
          $cfg[$k] = trim((string)$_POST[$k]);
        }
      }
      // Normalize yes/no
      foreach (['enabled', 'default_read_only', 'allow_bind_all', 'destructive_mode', 'rehydrate_on_start'] as $k) {
        if (isset($cfg[$k])) {
          $cfg[$k] = ($cfg[$k] === 'yes') ? 'yes' : 'no';
        }
      }
      if (($cfg['enabled'] ?? 'yes') !== 'yes') {
        nbd_stop_all_exports();
      }
      nbd_write_cfg($cfg);
      nbd_write_companion_marker();
      $msg = 'NBD Export: settings saved.';
      if (($cfg['destructive_mode'] ?? 'no') === 'yes') {
        $msg .= ' WARNING: Destructive mode is ON (writable / array / mounted exports allowed).';
      }
      nbd_flash($msg);
      break;

    case 'export_start':
      $dev = trim((string)($_POST['device'] ?? ''));
      $bind = trim((string)($_POST['bind'] ?? ''));
      $port = (int)($_POST['port'] ?? 10809);
      $ro = (($_POST['read_only'] ?? 'yes') === 'yes');
      $label = trim((string)($_POST['label'] ?? ''));
      $confirm = (($_POST['nbd_confirm'] ?? '') === 'yes');
      $r = nbd_export_start($dev, $bind, $port, $ro, $label, 2, $confirm);
      if (empty($r['ok'])) {
        nbd_flash('NBD Export: ERROR — ' . ($r['error'] ?? 'start failed'));
      } else {
        nbd_flash('NBD Export: started ' . ($r['url'] ?? $r['id']) . ($ro ? ' (read-only)' : ' (WRITABLE)'));
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

    case 'image_start':
      $url = trim((string)($_POST['nbd_url'] ?? ''));
      $out = trim((string)($_POST['output'] ?? ''));
      $fmt = trim((string)($_POST['format'] ?? 'qcow2'));
      $r = nbd_image_start($url, $out, $fmt);
      if (empty($r['ok'])) {
        nbd_flash('NBD Image: ERROR — ' . ($r['error'] ?? 'start failed'));
      } else {
        nbd_flash('NBD Image: job started ' . ($r['id'] ?? ''));
      }
      break;

    case 'image_stop':
      $id = trim((string)($_POST['job_id'] ?? ''));
      $r = nbd_image_stop($id);
      nbd_flash(empty($r['ok']) ? ('ERROR — ' . ($r['error'] ?? 'stop failed')) : ('Stopped job ' . $id));
      break;

    default:
      nbd_flash('NBD Export: unknown action');
  }
} catch (Throwable $e) {
  nbd_flash('NBD Export: exception — ' . $e->getMessage());
}
