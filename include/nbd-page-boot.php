<?php
/**
 * Shared bootstrap for NBD Export Settings tabs (Status / Host / Pull / Settings).
 */
require_once '/usr/local/emhttp/plugins/NBDExport/include/nbd-lib.php';

$plugin = 'NBDExport';
$cfg = function_exists('parse_plugin_cfg') ? parse_plugin_cfg($plugin) : [];
if (!is_array($cfg)) {
  $cfg = [];
}
$cfg = array_merge(nbd_load_cfg(), $cfg);
$st = nbd_status();
$tools = $st['tools'];
$exports = $st['exports'];
$jobs = function_exists('nbd_jobs_with_external') ? nbd_jobs_with_external() : ($st['jobs'] ?? []);
$binds = $st['bind_ips'];
$disks = nbd_list_disks();
$ver = htmlspecialchars($st['plugin_version']);
$enabled = (($cfg['enabled'] ?? 'yes') === 'yes') ? 'yes' : 'no';
$def_ro = (($cfg['default_read_only'] ?? 'yes') === 'yes') ? 'yes' : 'no';
$def_port = htmlspecialchars($cfg['default_port'] ?? '10809');
$allow_all = (($cfg['allow_bind_all'] ?? 'no') === 'yes') ? 'yes' : 'no';
$destructive = (($cfg['destructive_mode'] ?? 'no') === 'yes') ? 'yes' : 'no';
$ud_overlay = (($cfg['ud_status_overlay'] ?? 'no') === 'yes') ? 'yes' : 'no';
$pull_io_class = (($cfg['pull_io_class'] ?? 'idle') === 'best-effort') ? 'best-effort' : 'idle';
$max_concurrent_pulls = (string)max(1, min(4, (int)($cfg['max_concurrent_pulls'] ?? 1)));
$pull_nice = (string)max(0, min(19, (int)($cfg['pull_nice'] ?? 10)));
$allow_upgrade_busy = (($cfg['allow_upgrade_while_busy'] ?? 'no') === 'yes') ? 'yes' : 'no';
$notify_pull_done = (($cfg['notify_pull_done'] ?? 'off') === 'normal') ? 'normal' : 'off';
$npf = strtolower((string)($cfg['notify_pull_failed'] ?? 'warning'));
$notify_pull_failed = in_array($npf, ['warning', 'alert'], true) ? $npf : 'off';
$nhd = strtolower((string)($cfg['notify_host_down'] ?? 'off'));
$notify_host_down = in_array($nhd, ['warning', 'alert'], true) ? $nhd : 'off';
$ud_plugin_present = is_dir('/usr/local/emhttp/plugins/unassigned.devices');
$tbn = !empty($st['thunderboltnet']);
$frr = !empty($st['fabricrouting']);
$mem = nbd_memory_load();
$last_host = is_array($mem['last_host'] ?? null) ? $mem['last_host'] : [];
$last_pull = is_array($mem['last_pull'] ?? null) ? $mem['last_pull'] : [];
$presets = is_array($mem['presets'] ?? null) ? $mem['presets'] : [];
$host_presets = [];
$pull_presets = [];
foreach ($presets as $pn => $pv) {
  if (!is_array($pv)) {
    continue;
  }
  if (($pv['type'] ?? '') === 'host') {
    $host_presets[$pn] = $pv;
  } elseif (($pv['type'] ?? '') === 'pull') {
    $pull_presets[$pn] = $pv;
  }
}
$pref_device = $last_host['device'] ?? '';
$pref_bind = $last_host['bind'] ?? '';
$pref_ro = (($last_host['read_only'] ?? $def_ro) === 'yes') ? 'yes' : 'no';
$pref_label = htmlspecialchars($last_host['label'] ?? '');
$pref_url = htmlspecialchars($last_pull['nbd_url'] ?? '');
$pref_out = htmlspecialchars($last_pull['output'] ?? '');
$pref_fmt = ($last_pull['format'] ?? 'qcow2') === 'raw' ? 'raw' : 'qcow2';

$default_bind = $pref_bind;
if ($default_bind === '') {
  foreach ($binds as $b) {
    if (!empty($b['preferred'])) {
      $default_bind = $b['ip'];
      break;
    }
  }
}
if ($default_bind === '' && $binds) {
  $default_bind = $binds[0]['ip'];
}

/** Port prefill: last used, or default; if already listening, next free (multi-disk). */
$used_ports = [];
foreach ($exports as $e) {
  if (!empty($e['alive']) && !empty($e['port'])) {
    $used_ports[(int)$e['port']] = true;
  }
}
$pref_port_int = (int)($last_host['port'] ?? $cfg['default_port'] ?? 10809);
if ($pref_port_int < 1024 || $pref_port_int > 65535) {
  $pref_port_int = 10809;
}
if (!empty($used_ports[$pref_port_int])) {
  $try = $pref_port_int;
  for ($i = 0; $i < 64; $i++) {
    $try++;
    if ($try > 65535) {
      $try = 10809;
    }
    if (empty($used_ports[$try])) {
      $pref_port_int = $try;
      break;
    }
  }
}
$pref_port = htmlspecialchars((string)$pref_port_int);

function nbd_page_styles() {
  static $done = false;
  if ($done) {
    return;
  }
  $done = true;
  echo <<<'CSS'
<style>
/* Center the Unraid tab strip (Network Settings style: balanced row of tabs) */
.tabs {
  justify-content: center !important;
}
.tabs .tabs-container {
  justify-content: center !important;
  width: auto !important;
  max-width: 100%;
  margin: 0 auto;
}
.nbd-wrap { max-width: 64em; margin-left: auto; margin-right: auto; }
/* VM-template style: in-flow tree (pushes page), not absolute overlay (glitchy dismiss) */
.nbd-wrap .fileTree {
  position: relative !important;
  left: auto !important;
  top: auto !important;
  width: min(36em, 100%);
  max-height: 16em;
  z-index: 20;
  margin: 0.35em 0 0.6em;
  overflow-y: auto;
  overflow-x: hidden;
}
.nbd-wrap .nbd-companion {
  margin: 0.5em 0 1em;
  padding: 0.55em 0.75em;
  border-left: 3px solid #e68a2e;
  background: rgba(200, 140, 40, 0.08);
  font-size: 0.92em;
  line-height: 1.4;
}
.nbd-wrap .nbd-companion-strip {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
  margin: 0.65em 0 0;
}
.nbd-wrap .nbd-companion-card {
  flex: 1 1 14em;
  min-width: 12em;
  max-width: 22em;
  padding: 0.55em 0.75em;
  border-radius: 6px;
  border: 1px solid rgba(128, 128, 128, 0.35);
  background: rgba(128, 128, 128, 0.08);
  font-size: 0.9em;
  line-height: 1.4;
}
.nbd-wrap .nbd-companion-card.nbd-companion-ok {
  border-color: rgba(46, 160, 90, 0.45);
  background: rgba(46, 160, 90, 0.1);
}
.nbd-wrap .nbd-companion-title {
  font-weight: 700;
  margin: 0 0 0.35em;
  font-size: 0.98em;
}
.nbd-wrap .nbd-companion-status {
  display: inline-block;
  font-weight: 700;
  margin-right: 0.25em;
}
.nbd-wrap .nbd-companion-status.nbd-status-ok { color: #2a7; }
.nbd-wrap .nbd-companion-status.nbd-status-warn { color: #c80; }
.nbd-wrap .nbd-companion-card p { margin: 0.25em 0; }
.nbd-wrap .nbd-chrome-footer .nbd-companion-strip { margin-top: 0.55em; }

.nbd-wrap .nbd-tip {
  margin: 0.5em 0 0.85em;
  padding: 0.55em 0.75em;
  border-left: 3px solid #4a90d9;
  background: rgba(74, 144, 217, 0.12);
  font-size: 0.92em;
  line-height: 1.45;
}
.nbd-wrap .nbd-config-backup { margin-top: 1em; }
.nbd-wrap .nbd-config-backup-actions {
  display: flex; flex-wrap: wrap; gap: 0.5rem 1rem; align-items: center; margin-top: 0.4em;
}
.nbd-wrap a.nbd-btn-link {
  color: var(--orange-bold, #e68a2e); font-weight: 700; text-decoration: underline;
}
.nbd-wrap .nbd-import-details { margin-top: 0.55em; font-size: 0.95em; }
.nbd-wrap .nbd-import-details summary { cursor: pointer; font-weight: 600; opacity: 0.9; }
.nbd-wrap .nbd-empty {
  margin: 0.75em 0; padding: 1em 1.1em; border-radius: 8px;
  border: 1px dashed rgba(74, 144, 217, 0.55); background: rgba(74, 144, 217, 0.08); line-height: 1.45;
}
.nbd-wrap .nbd-empty strong { color: #4a90d9; }
.nbd-wrap .nbd-status-legend {
  display: flex; flex-wrap: wrap; gap: 0.45em 0.85em; margin: 0.5em 0 0.85em; font-size: 0.88em; opacity: 0.9;
}
.nbd-wrap .nbd-muted { opacity: 0.78; font-size: 0.92em; }
.nbd-wrap .nbd-ok { color: #2a7; font-weight: 600; }
.nbd-wrap .nbd-warn { color: #c80; }
.nbd-wrap .nbd-bad { color: #c33; font-weight: 600; }
.nbd-wrap .nbd-badge {
  display: inline-block; padding: 0.15em 0.55em; border-radius: 4px;
  font-size: 0.85em; font-weight: 600; background: rgba(128, 128, 128, 0.28);
}
.nbd-wrap .nbd-badge-ok { background: rgba(46, 160, 90, 0.4); } /* Done / Listening */
.nbd-wrap .nbd-badge-info { background: rgba(74, 144, 217, 0.4); } /* Queued / Active */
.nbd-wrap .nbd-badge-stale { background: rgba(140, 140, 140, 0.35); } /* Idle */
.nbd-wrap .nbd-badge-bad { background: rgba(200, 60, 60, 0.4); } /* Failed */
.nbd-wrap .nbd-badge-run { background: rgba(220, 140, 40, 0.5); color: inherit; } /* Running (orange) */
.nbd-wrap .nbd-badge-paused { background: rgba(140, 90, 200, 0.5); color: inherit; } /* Paused (purple) */
.nbd-wrap .nbd-badge-rw { background: rgba(220, 140, 40, 0.45); } /* Writable host (not Pause) */
.nbd-wrap .nbd-section { margin: 1.25em 0 0; padding: 0 0 0.5em; }
.nbd-wrap .nbd-section > h3 {
  margin: 0 0 0.5em; padding: 0.35em 0 0.45em; font-size: 1.12em; font-weight: 700;
  border-bottom: 1px solid rgba(128, 128, 128, 0.28);
}
.nbd-wrap .nbd-section-lead {
  margin: 0 0 0.85em; font-size: 0.92em; opacity: 0.85; line-height: 1.4;
}
.nbd-wrap .nbd-actions {
  margin: 1.1em 0 0; padding: 0.85em 0 0.15em; border-top: 1px dashed rgba(128, 128, 128, 0.4);
}
.nbd-wrap .nbd-actions input[type="submit"] { min-width: 10em; }
.nbd-wrap pre.nbd-cli {
  white-space: pre-wrap; font-size: 0.88em; opacity: 0.85; margin: 0.5em 0 0;
  padding: 0.5em 0.65em; border-radius: 4px; background: rgba(0, 0, 0, 0.06);
}
.nbd-wrap pre.nbd-log { white-space: pre-wrap; margin: 0; font-size: 0.85em; opacity: 0.8; max-height: 12em; overflow: auto; }
.nbd-wrap table.nbd-data { width: 100%; margin: 0.5em 0 0.75em; table-layout: fixed; }
.nbd-wrap table.nbd-data th,
.nbd-wrap table.nbd-data td {
  text-align: left; padding: 0.35em 0.5em; vertical-align: top;
  border-bottom: 1px solid rgba(128, 128, 128, 0.25);
  word-break: break-word; overflow-wrap: anywhere;
}
.nbd-wrap table.nbd-data code { word-break: break-all; font-size: 0.9em; }
.nbd-wrap .nbd-job-list { margin: 0.5em 0 0.75em; display: flex; flex-direction: column; gap: 0.65em; }
.nbd-wrap .nbd-job-card {
  border: 1px solid rgba(128,128,128,0.35); border-radius: 6px;
  padding: 0.55em 0.75em; background: rgba(128,128,128,0.06);
}
.nbd-wrap .nbd-job-card-top {
  display: flex; flex-wrap: wrap; align-items: center; gap: 0.45em 0.75em; margin-bottom: 0.35em;
}
.nbd-wrap .nbd-live-job-metrics {
  display: inline-flex; flex-wrap: wrap; align-items: baseline; gap: 0 0.15em;
  font-variant-numeric: tabular-nums; white-space: normal;
}
.nbd-wrap .nbd-live-job-metrics .nbd-live-job-size,
.nbd-wrap .nbd-live-job-metrics .nbd-live-job-pct,
.nbd-wrap .nbd-live-job-metrics .nbd-live-job-elapsed,
.nbd-wrap .nbd-live-job-metrics .nbd-live-job-eta,
.nbd-wrap .nbd-live-job-metrics .nbd-live-job-net,
.nbd-wrap .nbd-live-job-metrics .nbd-live-job-disk {
  white-space: nowrap;
}
.nbd-wrap .nbd-job-card-meta { font-size: 0.92em; line-height: 1.45; }
.nbd-wrap .nbd-job-card-meta .nbd-job-path {
  display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 100%; font-size: 0.9em;
}
.nbd-wrap .nbd-job-card-actions {
  margin-top: 0.45em; display: flex; flex-wrap: wrap; gap: 0.4em; align-items: center;
}
.nbd-wrap .nbd-job-card-actions form {
  display: inline-flex !important; margin: 0; align-items: stretch; vertical-align: middle;
  height: 2.25em;
}
.nbd-wrap .nbd-job-card-actions input[type="submit"],
.nbd-wrap .nbd-job-card-actions button {
  margin: 0; vertical-align: middle;
  box-sizing: border-box;
  height: 2.25em; min-height: 2.25em; min-width: 5.75em;
  line-height: 1.15; padding: 0 0.85em;
}
.nbd-wrap .nbd-job-card-meta .nbd-job-path {
  display: inline; white-space: normal; word-break: break-all; overflow-wrap: anywhere;
  max-width: none; font-size: 0.9em;
}
.nbd-wrap .nbd-job-rmout {
  display: block; margin: 0.4em 0 0.15em; font-size: 0.9em; cursor: pointer;
}
.nbd-wrap .nbd-job-fail {
  margin-top: 0.35em; padding: 0.35em 0.5em; border-radius: 4px;
  border-left: 3px solid rgba(200, 60, 60, 0.85);
  background: rgba(200, 60, 60, 0.12); font-size: 0.92em; line-height: 1.35;
}
.nbd-wrap .nbd-job-bug-btn {
  margin-left: auto;
}
.nbd-wrap .nbd-bug-panel {
  margin-top: 0.55em; padding: 0.65em 0.75em; border-radius: 6px;
  border: 1px solid rgba(200, 140, 40, 0.55);
  background: rgba(200, 140, 40, 0.08);
  font-size: 0.92em; line-height: 1.45;
}
.nbd-wrap .nbd-bug-panel .nbd-bug-lead,
.nbd-wrap .nbd-bug-panel .nbd-bug-tools {
  margin: 0 0 0.55em;
}
.nbd-wrap .nbd-bug-panel .nbd-bug-tools { margin-bottom: 0.75em; }
.nbd-wrap .nbd-bug-panel a { word-break: break-word; }
.nbd-wrap .nbd-diag-label {
  display: block; font-weight: 600; margin: 0.35em 0 0.3em;
}
.nbd-wrap textarea.nbd-diag {
  width: 100%; box-sizing: border-box;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 0.85em; line-height: 1.35;
  padding: 0.55em 0.65em; border-radius: 4px;
  border: 1px solid rgba(128, 128, 128, 0.4);
  background: rgba(0, 0, 0, 0.12);
  resize: vertical; min-height: 10em;
}
.nbd-wrap .nbd-bug-actions { margin: 0.45em 0 0; }
.nbd-wrap .nbd-job-card details.nbd-job-log { margin-top: 0.45em; font-size: 0.9em; }
.nbd-wrap .nbd-job-card details.nbd-job-log summary { cursor: pointer; opacity: 0.85; font-weight: 600; }
.nbd-wrap .nbd-logs-toolbar {
  margin: 0 0 0.75em; padding: 0.55em 0.65em;
  border: 1px solid rgba(128,128,128,0.3); border-radius: 6px;
  background: rgba(128,128,128,0.06);
}
.nbd-wrap .nbd-logs-toolbar-bottom { margin: 0.85em 0 0; }
.nbd-wrap .nbd-logs-clear-form {
  display: flex; flex-wrap: wrap; gap: 0.35em; align-items: center; margin: 0;
}
.nbd-wrap .nbd-log-list { display: flex; flex-direction: column; gap: 0.55em; margin: 0.5em 0 0; }
.nbd-wrap details.nbd-log-file {
  border: 1px solid rgba(128,128,128,0.35); border-radius: 6px;
  padding: 0.45em 0.65em; background: rgba(128,128,128,0.05);
}
.nbd-wrap details.nbd-log-file summary {
  cursor: pointer; font-size: 0.95em; line-height: 1.45;
  display: flex; flex-wrap: wrap; align-items: center; gap: 0.35em 0.55em;
}
.nbd-wrap details.nbd-log-file pre.nbd-log-full {
  max-height: 28em; overflow: auto; margin: 0.45em 0 0.15em;
}
.nbd-wrap .nbd-job-section-title { margin: 1.1em 0 0.45em; font-size: 1.05em; }
.nbd-wrap .nbd-jobs-toolbar {
  margin: 0 0 0.65em; padding: 0.55em 0.65em;
  border: 1px solid rgba(128,128,128,0.3); border-radius: 6px;
  background: rgba(128,128,128,0.06);
}
.nbd-wrap .nbd-jobs-toolbar form {
  display: flex; flex-wrap: wrap; gap: 0.45em 0.55em; align-items: center;
}
.nbd-wrap .nbd-toolbar-group {
  display: inline-flex; flex-wrap: wrap; gap: 0.35em; align-items: center;
}
.nbd-wrap .nbd-toolbar-label {
  font-size: 0.85em; font-weight: 700; opacity: 0.85; margin-right: 0.15em;
}
.nbd-wrap .nbd-toolbar-sep {
  opacity: 0.45; font-weight: 700; padding: 0 0.15em; user-select: none;
}
.nbd-wrap .nbd-job-sel { display: inline-flex; width: 1.4em; flex: 0 0 auto; }
.nbd-wrap .nbd-job-sel-spacer { display: inline-block; width: 1.4em; }
.nbd-wrap .nbd-job-edit {
  margin-top: 0.5em; padding: 0.5em 0.65em; border-radius: 4px;
  background: rgba(128,128,128,0.08); border: 1px dashed rgba(128,128,128,0.35);
}
.nbd-wrap .nbd-job-edit label { display: block; margin: 0.35em 0; font-size: 0.92em; }
.nbd-wrap .nbd-job-edit-toggle { cursor: pointer; }
.nbd-wrap details.nbd-cli-box { margin-top: 1.25em; font-size: 0.95em; }
.nbd-wrap details.nbd-cli-box summary { cursor: pointer; font-weight: 600; }
/* Shared header block (all tabs) */
.nbd-wrap .nbd-chrome-top {
  margin: 0 0 1em;
  padding-bottom: 0.85em;
  border-bottom: 2px solid rgba(128, 128, 128, 0.35);
}
.nbd-wrap .nbd-destructive-banner {
  margin: 0 0 0.75em;
  padding: 0.55em 0.75em;
  border-radius: 6px;
  border: 1px solid rgba(230, 138, 46, 0.65);
  border-left-width: 4px;
  background: rgba(230, 138, 46, 0.18);
  color: var(--orange-bold, #e68a2e);
  font-weight: 600;
  font-size: 0.92em;
  line-height: 1.35;
}
.nbd-wrap .nbd-destructive-banner a { color: inherit; font-weight: 700; }
.nbd-wrap .nbd-chrome-hosted h3 {
  margin: 0 0 0.35em;
  font-size: 1.05em;
  font-weight: 700;
}
.nbd-wrap .nbd-chrome-hosted .nbd-section-lead { margin-bottom: 0.5em; }
.nbd-wrap .nbd-chrome-footer {
  margin: 1.5em 0 0.5em;
  padding: 0.75em 0 0;
  border-top: 1px solid rgba(128, 128, 128, 0.3);
  font-size: 0.9em;
  line-height: 1.4;
  opacity: 0.92;
}
.nbd-wrap .nbd-chrome-footer .nbd-companion {
  margin: 0.55em 0 0;
}
.nbd-wrap .nbd-ext-hint.nbd-ext-warn {
  border: 1px solid rgba(200, 120, 40, 0.55);
  background: rgba(230, 160, 60, 0.15);
  color: inherit;
}
.nbd-wrap .nbd-ext-hint.nbd-ext-info {
  border: 1px solid rgba(100, 140, 180, 0.45);
  background: rgba(100, 140, 180, 0.12);
  color: inherit;
}
.nbd-wrap .nbd-tab-body {
  margin-top: 0.35em;
}
</style>
CSS;
}

/**
 * Shared header on every NBD tab: Destructive banner + hosted-disks list.
 */
function nbd_page_header() {
  global $exports, $destructive, $enabled, $tools, $jobs;
  if (!isset($exports)) {
    return;
  }
  $n = is_array($exports) ? count($exports) : 0;
  $nj = is_array($jobs) ? count($jobs) : 0;
  ?>
<div class="nbd-chrome-top">
<?php if ($destructive === 'yes'): ?>
  <div class="nbd-destructive-banner" role="status">
    Destructive mode is <strong>ON</strong> —
    writable host and/or hosting in-use or critical disks
    (array, cache, pool, mounted, or Unraid boot device) is unlocked.
    Prefer Read-only = Yes. Turn off under
    <a href="/Settings/NBDSettings">Settings</a>
    when finished.
  </div>
<?php endif; ?>
<?php if ($enabled !== 'yes'): ?>
  <div class="nbd-destructive-banner" role="status" style="border-color:rgba(200,60,60,0.5);background:rgba(200,60,60,0.12);color:#c33">
    NBD Export is <strong>disabled</strong> — enable under <a href="/Settings/NBDSettings">Settings</a>.
  </div>
<?php endif; ?>

  <div class="nbd-chrome-hosted">
    <h3>Disks this Unraid is hosting
      <span class="nbd-muted" style="font-weight:500;font-size:0.88em" id="nbd-live-chrome-counts">
        · <?= (int)$n ?> live<?= $nj ? ' · ' . (int)$nj . ' pull job(s)' : '' ?>
      </span>
    </h3>
<?php if (!$exports): ?>
    <div class="nbd-empty" style="margin:0.4em 0 0;padding:0.65em 0.85em">
      None hosted on this Unraid. Use the <strong>Host</strong> tab to publish a disk or partition
      (one free port per disk).
    </div>
<?php else:
  $n_writable = 0;
  foreach ($exports as $_e) {
    // Match stop-writables: missing read_only treated as writable (legacy / unsafe default)
    $ro = array_key_exists('read_only', $_e) ? !empty($_e['read_only']) : false;
    if (!$ro) {
      $n_writable++;
    }
  }
?>
    <div class="nbd-status-legend" style="margin:0.35em 0 0.45em">
      <span><span class="nbd-badge nbd-badge-ok">Listening</span></span>
      <span><span class="nbd-badge nbd-badge-info">Active</span></span>
      <span><span class="nbd-badge nbd-badge-stale">Stopped</span></span>
      <span><span class="nbd-badge nbd-badge-rw">Writable</span></span>
    </div>
<?php
  // Caveat only when Destructive is Off but RW hosts still listening on THIS Unraid
  if ($n_writable > 0 && $destructive !== 'yes'):
?>
    <div class="nbd-destructive-banner" role="alert" style="margin:0.35em 0 0.55em;border-color:rgba(200,60,60,0.55);background:rgba(200,60,60,0.14);color:#b33">
      <div>
        <strong>On this Unraid:</strong>
        <strong><?= (int)$n_writable ?> writable</strong> host<?= $n_writable === 1 ? '' : 's' ?> still listening
        while Destructive mode is <strong>Off</strong>.
        Peers can write <em>these local</em> disk(s).
        This banner is about Host exports on <em>this</em> server — not whether a remote
        <code>nbd://</code> you Pull from is read-only.
        Destructive Off only blocks <em>starting</em> new elevated Hosts; it does not stop ones already up.
      </div>
      <form method="POST" action="/update.php" target="progressFrame" style="display:block;margin:0.55em 0 0"
        onsubmit="return confirm('Emergency stop: halt ALL writable NBD hosts now?\n\nRead-only hosts stay up.');">
        <input type="hidden" name="#file" value="NBDExport/NBDExport.cfg">
        <input type="hidden" name="#include" value="/plugins/NBDExport/include/nbd-update.php">
        <input type="hidden" name="nbd_action" value="export_stop_writables">
        <input type="submit" name="#apply" value="Stop all writable hosts"
          style="color:#fff;background:#a33;border-color:#822;font-weight:700">
      </form>
    </div>
<?php endif; ?>
    <table class="nbd-data tablesorter">
      <thead>
        <tr>
          <th>Status</th>
          <th>Disk</th>
          <th>Clients use</th>
          <th>Mode</th>
          <th>Label</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($exports as $e):
  $ust = nbd_export_ui_status($e);
  $ro = !empty($e['read_only']);
  $eid = htmlspecialchars($e['id'] ?? '');
?>
        <tr data-nbd-export-id="<?= $eid ?>">
          <td>
            <span class="nbd-badge <?= htmlspecialchars($ust['class']) ?> nbd-live-export-badge"
              data-nbd-key="<?= htmlspecialchars($ust['key'] ?? '') ?>"
              title="<?= htmlspecialchars($ust['hint']) ?>"><?= htmlspecialchars($ust['label']) ?></span>
          </td>
          <td><code><?= htmlspecialchars($e['device'] ?? '') ?></code></td>
          <td><code><?= htmlspecialchars($e['url'] ?? (($e['bind'] ?? '') . ':' . ($e['port'] ?? ''))) ?></code></td>
          <td><?= $ro
            ? '<span class="nbd-badge nbd-badge-ok">Read-only</span>'
            : '<span class="nbd-badge nbd-badge-rw">Writable</span>' ?></td>
          <td><?= htmlspecialchars($e['label'] ?? '') ?></td>
          <td class="nbd-live-export-actions">
            <form method="POST" action="/update.php" target="progressFrame" style="display:inline" class="nbd-live-stop-form">
              <input type="hidden" name="#file" value="NBDExport/NBDExport.cfg">
              <input type="hidden" name="#include" value="/plugins/NBDExport/include/nbd-update.php">
              <input type="hidden" name="nbd_action" value="export_stop">
              <input type="hidden" name="export_id" value="<?= $eid ?>">
              <input type="submit" name="#apply" value="Stop">
            </form>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    <div style="margin-top:0.4em;display:flex;flex-wrap:wrap;gap:0.45em;align-items:center">
<?php if ($n >= 1): ?>
      <form method="POST" action="/update.php" target="progressFrame" style="display:inline"
        onsubmit="return confirm('Stop ALL hosted disks (read-only and writable)?');">
        <input type="hidden" name="#file" value="NBDExport/NBDExport.cfg">
        <input type="hidden" name="#include" value="/plugins/NBDExport/include/nbd-update.php">
        <input type="hidden" name="nbd_action" value="export_stop_all">
        <input type="submit" name="#apply" value="Stop all hosted disks">
      </form>
<?php endif; ?>
<?php if ($n_writable > 0): ?>
      <form method="POST" action="/update.php" target="progressFrame" style="display:inline"
        onsubmit="return confirm('Emergency stop: halt ALL writable NBD hosts now?\n\nRead-only hosts stay up.');">
        <input type="hidden" name="#file" value="NBDExport/NBDExport.cfg">
        <input type="hidden" name="#include" value="/plugins/NBDExport/include/nbd-update.php">
        <input type="hidden" name="nbd_action" value="export_stop_writables">
        <input type="submit" name="#apply" value="Stop all writable hosts"
          style="color:#fff;background:#a33;border-color:#822;font-weight:700"
          title="Security emergency: kill writable exports only">
      </form>
<?php endif; ?>
    </div>
<?php endif; ?>
  </div>
</div>
  <?php
}

/**
 * Shared footer on every tab: short description, companions, docs links.
 */
function nbd_page_footer($show_cli = false) {
  global $tbn, $frr;
  $tbn_install = 'https://raw.githubusercontent.com/ibigsnet/ThunderboltNet/main/thunderboltnet.plg';
  $frr_install = 'https://raw.githubusercontent.com/ibigsnet/FabricRouting/main/fabricrouting.plg';
  ?>
<div class="nbd-chrome-footer">
  <strong>Network Block Device</strong> —
  temporarily publish a whole disk/partition over TCP as NBD — raw blocks for remote tools or convert/archive (not SMB/NFS folders).
  Tabs: <strong>Host</strong> publish · <strong>Pull</strong> save to file · <strong>Help</strong> recovery / peer host · <strong>Settings</strong> options.
  <span class="nbd-muted"> NBD binds its own IP:port — companions below are optional.</span>
  <div class="nbd-companion-strip" aria-label="Related plugins">
    <div class="nbd-companion-card<?= !empty($tbn) ? ' nbd-companion-ok' : '' ?>">
      <div class="nbd-companion-title">Private underlay (Thunderbolt Net)</div>
<?php if (!empty($tbn)): ?>
      <p><span class="nbd-companion-status nbd-status-ok">Installed</span>
        Prefer a Thunderbolt bind IP on Host
        (<a href="/Settings/NetworkSettings" onclick="return ibigsGotoNetTab('Thunderbolt', event)">Network Settings → Thunderbolt</a>).</p>
<?php else: ?>
      <p><span class="nbd-companion-status nbd-status-warn">Not installed</span>
        Optional private cable path for Host bind. Skip if you already use a trusted LAN IP.</p>
      <p>
        Install <strong>Thunderbolt Net</strong> from CA or
        <a href="<?= htmlspecialchars($tbn_install) ?>" target="_blank" rel="noopener">raw .plg</a>.
      </p>
<?php endif; ?>
    </div>
    <div class="nbd-companion-card<?= !empty($frr) ? ' nbd-companion-ok' : '' ?>">
      <div class="nbd-companion-title">Multi-hop (FRR / OpenFabric)</div>
<?php if (!empty($frr)): ?>
      <p><span class="nbd-companion-status nbd-status-ok">Installed</span>
        Optional multi-hop only — not required for a single NBD Host/Pull.
        <a href="/Settings/NetworkSettings" onclick="return ibigsGotoNetTab('Fabric Routing', event)">Network Settings → Fabric Routing</a>.</p>
<?php else: ?>
      <p><span class="nbd-companion-status nbd-status-warn">Not installed</span>
        Optional for rings / multi-hop / Proxmox FRR peers. Skip for a single static path or direct LAN.</p>
      <p>
        Install <strong>Fabric Routing</strong> from CA or
        <a href="<?= htmlspecialchars($frr_install) ?>" target="_blank" rel="noopener">raw .plg</a>.
      </p>
<?php endif; ?>
    </div>
  </div>
<script>
/* Fleet standard: Network Settings sibling tabs (ibigsGotoNetTab).
   Never deep-link /Settings/ThunderboltNet or /Settings/FabricRouting (standalone CA). */
(function (global) {
  'use strict';
  var WANT = 'ibigsWantTab';
  var WANT_LEGACY = 'tbnWantTab';
  var NET = '/Settings/NetworkSettings';
  function findTab(needle) {
    var tabs = document.querySelectorAll('.tabs [role="tab"], .tabs a, #menu a, .nav-item');
    var want = (needle || '').toLowerCase();
    if (!want) return null;
    for (var i = 0; i < tabs.length; i++) {
      var t = (tabs[i].textContent || tabs[i].innerText || '').replace(/\s+/g, ' ').trim().toLowerCase();
      if (t.indexOf(want) !== -1) return tabs[i];
    }
    return null;
  }
  function setWant(n) {
    try { sessionStorage.setItem(WANT, n); sessionStorage.setItem(WANT_LEGACY, n); } catch (e) {}
  }
  function getWant() {
    try { return sessionStorage.getItem(WANT) || sessionStorage.getItem(WANT_LEGACY); } catch (e) { return null; }
  }
  function clearWant() {
    try { sessionStorage.removeItem(WANT); sessionStorage.removeItem(WANT_LEGACY); } catch (e) {}
  }
  function gotoNetTab(needle, evt) {
    if (evt && evt.preventDefault) evt.preventDefault();
    var tab = findTab(needle);
    if (tab) {
      tab.click();
      try { tab.scrollIntoView({ block: 'nearest', inline: 'nearest' }); } catch (e) {}
      return false;
    }
    setWant(needle);
    global.location.href = NET;
    return false;
  }
  function applyWanted() {
    var want = getWant();
    if (!want) return;
    var tab = findTab(want);
    if (!tab) return;
    clearWant();
    setTimeout(function () { tab.click(); }, 50);
  }
  if (typeof global.ibigsGotoNetTab !== 'function') {
    global.ibigsGotoNetTab = gotoNetTab;
  }
  global.nbdGotoNetTab = function (n, e) { return global.ibigsGotoNetTab(n, e); };
  if (typeof global.tbnGotoNetTab !== 'function') {
    global.tbnGotoNetTab = function (n, e) { return global.ibigsGotoNetTab(n, e); };
  }
  if (typeof global.frrGotoNetTab !== 'function') {
    global.frrGotoNetTab = function (n, e) { return global.ibigsGotoNetTab(n, e); };
  }
  function onReady() { applyWanted(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', onReady);
  else onReady();
  setTimeout(applyWanted, 200);
  setTimeout(applyWanted, 600);
})(window);
</script>
  <p class="nbd-muted" style="margin:0.45em 0 0">
    Docs:
    <a href="/Settings/NBDHelp">Help tab</a>
    ·
    <a href="https://github.com/ibigsnet/NBDExport/blob/main/docs/how-to-use.md" target="_blank" rel="noopener">how to use ↗</a>
    ·
    <a href="https://github.com/ibigsnet/NBDExport/blob/main/docs/peer-host-linux.md" target="_blank" rel="noopener">peer Linux host ↗</a>
    ·
    <a href="https://github.com/ibigsnet/NBDExport/blob/main/DOCS.md" target="_blank" rel="noopener">DOCS ↗</a>
  </p>
</div>
<?php
  // Auto-refresh when Host/Pull goes terminal (all tabs, including Status)
  @include __DIR__ . '/nbd-live-watch.php';
}

