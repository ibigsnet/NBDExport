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
$jobs = $st['jobs'];
$binds = $st['bind_ips'];
$disks = nbd_list_disks();
$ver = htmlspecialchars($st['plugin_version']);
$enabled = (($cfg['enabled'] ?? 'yes') === 'yes') ? 'yes' : 'no';
$def_ro = (($cfg['default_read_only'] ?? 'yes') === 'yes') ? 'yes' : 'no';
$def_port = htmlspecialchars($cfg['default_port'] ?? '10809');
$allow_all = (($cfg['allow_bind_all'] ?? 'no') === 'yes') ? 'yes' : 'no';
$destructive = (($cfg['destructive_mode'] ?? 'no') === 'yes') ? 'yes' : 'no';
$ud_overlay = (($cfg['ud_status_overlay'] ?? 'no') === 'yes') ? 'yes' : 'no';
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
.nbd-wrap { max-width: 54em; margin-left: auto; margin-right: auto; }
.nbd-wrap .nbd-companion {
  margin: 0.5em 0 1em;
  padding: 0.55em 0.75em;
  border-left: 3px solid #e68a2e;
  background: rgba(200, 140, 40, 0.08);
  font-size: 0.92em;
  line-height: 1.4;
}
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
.nbd-wrap .nbd-badge-ok { background: rgba(46, 160, 90, 0.4); }
.nbd-wrap .nbd-badge-info { background: rgba(74, 144, 217, 0.4); }
.nbd-wrap .nbd-badge-stale { background: rgba(140, 140, 140, 0.35); }
.nbd-wrap .nbd-badge-bad { background: rgba(200, 60, 60, 0.4); }
.nbd-wrap .nbd-badge-rw { background: rgba(220, 140, 40, 0.45); }
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
.nbd-wrap pre.nbd-log { white-space: pre-wrap; margin: 0; font-size: 0.85em; opacity: 0.8; }
.nbd-wrap table.nbd-data { width: 100%; margin: 0.5em 0 0.75em; }
.nbd-wrap table.nbd-data th,
.nbd-wrap table.nbd-data td {
  text-align: left; padding: 0.35em 0.5em; vertical-align: top;
  border-bottom: 1px solid rgba(128, 128, 128, 0.25);
}
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
    <h3>Disks currently hosted on the network
      <span class="nbd-muted" style="font-weight:500;font-size:0.88em">
        · <?= (int)$n ?> live<?= $nj ? ' · ' . (int)$nj . ' pull job(s)' : '' ?>
      </span>
    </h3>
<?php if (!$exports): ?>
    <div class="nbd-empty" style="margin:0.4em 0 0;padding:0.65em 0.85em">
      None hosted. Use the <strong>Host</strong> tab to publish a disk or partition
      (one free port per disk).
    </div>
<?php else:
  $n_writable = 0;
  foreach ($exports as $_e) {
    if (empty($_e['read_only'])) {
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
<?php if ($n_writable > 0): ?>
    <div class="nbd-destructive-banner" role="alert" style="margin:0.35em 0 0.55em;border-color:rgba(200,60,60,0.55);background:rgba(200,60,60,0.14);color:#b33">
      <strong><?= (int)$n_writable ?> writable</strong> host<?= $n_writable === 1 ? '' : 's' ?> listening —
      peers can write the selected disk(s). Turning Destructive mode <strong>Off</strong> does
      <em>not</em> stop them (only blocks starting new ones).
      <form method="POST" action="/update.php" target="progressFrame" style="display:inline;margin-left:0.5em"
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
?>
        <tr>
          <td>
            <span class="nbd-badge <?= htmlspecialchars($ust['class']) ?>" title="<?= htmlspecialchars($ust['hint']) ?>"><?= htmlspecialchars($ust['label']) ?></span>
          </td>
          <td><code><?= htmlspecialchars($e['device'] ?? '') ?></code></td>
          <td><code><?= htmlspecialchars($e['url'] ?? (($e['bind'] ?? '') . ':' . ($e['port'] ?? ''))) ?></code></td>
          <td><?= $ro
            ? '<span class="nbd-badge nbd-badge-ok">Read-only</span>'
            : '<span class="nbd-badge nbd-badge-rw">Writable</span>' ?></td>
          <td><?= htmlspecialchars($e['label'] ?? '') ?></td>
          <td>
            <form method="POST" action="/update.php" target="progressFrame" style="display:inline">
              <input type="hidden" name="#file" value="NBDExport/NBDExport.cfg">
              <input type="hidden" name="#include" value="/plugins/NBDExport/include/nbd-update.php">
              <input type="hidden" name="nbd_action" value="export_stop">
              <input type="hidden" name="export_id" value="<?= htmlspecialchars($e['id'] ?? '') ?>">
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
  ?>
<div class="nbd-chrome-footer">
  <strong>Network Block Device</strong> —
  temporarily publish a whole disk/partition over TCP as NBD — raw blocks for remote tools or convert/archive (not SMB/NFS folders).
  Tabs: <strong>Host</strong> publish · <strong>Pull</strong> save to file · <strong>Settings</strong> options.
  <div class="nbd-companion">
    <strong>Companions</strong> —
<?php if (!empty($tbn)): ?>
    Thunderbolt Net — prefer a Thunderbolt bind IP
    (<a href="/Settings/NetworkSettings" onclick="return ibigsGotoNetTab('Thunderbolt', event)">Network Settings → Thunderbolt</a>).
<?php else: ?>
    Optional <a href="https://github.com/ibigsnet/ThunderboltNet" target="_blank" rel="noopener">Thunderbolt Net</a>
    for a private underlay.
<?php endif; ?>
<?php if (!empty($frr)): ?>
    Fabric Routing (FRR): installed
    (<a href="/Settings/NetworkSettings" onclick="return ibigsGotoNetTab('Fabric Routing', event)">Network Settings → Fabric Routing</a>;
    optional multi-hop only).
<?php else: ?>
    Fabric Routing (FRR): not needed for NBD.
<?php endif; ?>
    <span class="nbd-muted"> NBD binds its own IP:port.</span>
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
<?php if ($show_cli): ?>
  <details class="nbd-cli-box">
    <summary>CLI reference (same path the UI wraps)</summary>
    <pre class="nbd-cli"># Host (read-only, private bind) — multi-disk: change --port per disk
qemu-nbd --read-only --persistent --shared=2 \
  --bind=10.255.0.1 --port=10809 --format=raw /dev/nvme0n1

# Pull on Unraid
qemu-img info nbd://10.255.0.1:10809
qemu-img convert -p -f raw -O qcow2 -t writeback -W \
  nbd://10.255.0.1:10809 /mnt/user/domains/example.qcow2</pre>
    <p class="nbd-muted" style="margin:0.4em 0 0">
      Docs:
      <a href="https://github.com/ibigsnet/NBDExport/blob/main/docs/how-to-use.md" target="_blank" rel="noopener">how to use ↗</a>
      ·
      <a href="https://github.com/ibigsnet/NBDExport/blob/main/docs/imaging-workflow.md" target="_blank" rel="noopener">imaging ↗</a>
      ·
      <a href="https://github.com/ibigsnet/NBDExport/blob/main/docs/security-and-bind.md" target="_blank" rel="noopener">security ↗</a>
    </p>
  </details>
<?php else: ?>
  <p class="nbd-muted" style="margin:0.45em 0 0">
    Docs:
    <a href="https://github.com/ibigsnet/NBDExport/blob/main/docs/how-to-use.md" target="_blank" rel="noopener">how to use ↗</a>
    ·
    <a href="https://github.com/ibigsnet/NBDExport/blob/main/DOCS.md" target="_blank" rel="noopener">DOCS ↗</a>
  </p>
<?php endif; ?>
</div>
  <?php
}
