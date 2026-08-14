/**
 * NBD Export — Unassigned Devices (Main → Unassigned Devices) overlay.
 *
 * Opt-in only. Best-effort DOM on a page we do not control:
 *  1) Small RO/RW badge on the disk Identification cell for hosted devices
 *  2) "NBD Hosts" panel under the SMB | NFS | ISO shares block (not a mount type —
 *     local Host exports from this Unraid)
 */
(function () {
  'use strict';
  if (window.__nbdUdOverlay) return;
  window.__nbdUdOverlay = true;

  var STATUS_URL = '/plugins/NBDExport/include/nbd-ud-status.php';
  var POLL_MS = 5000;
  var exportsList = [];
  var styleId = 'nbd-ud-overlay-style';
  var panelId = 'nbd-ud-hosts-panel';

  function ensureStyle() {
    if (document.getElementById(styleId)) return;
    var s = document.createElement('style');
    s.id = styleId;
    s.textContent =
      /* Disk-row badge (Identification column) */
      '.nbd-ud-badge{display:inline-block;margin-left:0.5em;padding:0.1em 0.45em;' +
      'font-size:0.85em;font-weight:700;letter-spacing:0.03em;vertical-align:middle;' +
      'white-space:nowrap;border-radius:3px;line-height:1.35}' +
      '.nbd-ud-badge-ro{color:#1a5c32;background:rgba(61,139,90,0.22);border:1px solid rgba(61,139,90,0.45)}' +
      '.nbd-ud-badge-rw{color:#fff;background:#b33;border:1px solid #822}' +
      '.nbd-ud-badge a{color:inherit;text-decoration:none}' +
      '.nbd-ud-badge a:hover{text-decoration:underline}' +
      /* Hosts panel under shares */
      '#' + panelId + '{margin:1.1em 0 0.5em}' +
      '#' + panelId + ' .nbd-ud-hosts-title{font-weight:600}' +
      '#' + panelId + ' .nbd-ud-hosts-note{font-size:0.9em;opacity:0.85;margin:0.25em 0 0.45em}' +
      '#' + panelId + ' table.nbd-ud-hosts-table{width:100%}' +
      '#' + panelId + ' .nbd-ud-mode-ro{color:#1a5c32;font-weight:700}' +
      '#' + panelId + ' .nbd-ud-mode-rw{color:#c00;font-weight:700}' +
      '#' + panelId + '.nbd-ud-empty{display:none}';
    (document.head || document.documentElement).appendChild(s);
  }

  function basename(path) {
    if (!path) return '';
    return String(path).replace(/^\/dev\//, '').replace(/\/+$/, '');
  }

  function parentDisk(base) {
    var m = base.match(/^(nvme\d+n\d+)p\d+$/);
    if (m) return m[1];
    m = base.match(/^(mmcblk\d+)p\d+$/);
    if (m) return m[1];
    m = base.match(/^([a-z]+)\d+$/);
    if (m) return m[1];
    return '';
  }

  function loadExports(cb) {
    var url = STATUS_URL + '?_=' + Date.now();
    function parse(j) {
      var list = [];
      if (j && j.enabled && j.exports && j.exports.length) {
        j.exports.forEach(function (ex) {
          var d = basename(ex.device || ex.path || '');
          if (!d) return;
          list.push({
            device: d,
            path: ex.path || ('/dev/' + d),
            read_only: !!ex.read_only,
            url: ex.url || '',
            label: ex.label || '',
            port: ex.port || 0,
            bind: ex.bind || ''
          });
        });
      }
      cb(list);
    }
    if (typeof fetch === 'function') {
      fetch(url, { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) {
          if (!r.ok) throw new Error('status ' + r.status);
          return r.json();
        })
        .then(parse)
        .catch(function () { cb([]); });
      return;
    }
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.timeout = 5000;
    xhr.withCredentials = true;
    xhr.onload = function () {
      try { parse(JSON.parse(xhr.responseText || '{}')); }
      catch (e) { cb([]); }
    };
    xhr.onerror = function () { cb([]); };
    xhr.ontimeout = function () { cb([]); };
    xhr.send();
  }

  function makeBadge(ex) {
    var ro = !!ex.read_only;
    var span = document.createElement('span');
    span.className = 'nbd-ud-badge ' + (ro ? 'nbd-ud-badge-ro' : 'nbd-ud-badge-rw');
    span.setAttribute('data-nbd-dev', ex.device);
    var mode = ro ? 'RO' : 'RW';
    var title = 'NBD Export Host · ' + mode +
      (ex.url ? ' · ' + ex.url : '') +
      (ex.label ? ' · ' + ex.label : '') +
      ' — Settings → Network Services → NBD';
    span.title = title;
    var a = document.createElement('a');
    a.href = '/Settings/NBDExport';
    a.textContent = 'NBD ' + mode;
    a.title = title;
    span.appendChild(a);
    return span;
  }

  function clearDiskBadges(root) {
    var list = (root || document).querySelectorAll('.nbd-ud-badge');
    for (var i = 0; i < list.length; i++) {
      if (list[i].parentNode) list[i].parentNode.removeChild(list[i]);
    }
  }

  /** Match a hosted device to a UD disk row. */
  function rowMatchesDevice(tr, device) {
    if (!tr || !device) return false;
    // Strongest: hdd='nvme1n1' on toggle control
    var hddEl = tr.querySelector('[hdd]');
    if (hddEl) {
      var h = String(hddEl.getAttribute('hdd') || '');
      if (h === device) return true;
    }
    // Mount button device='/dev/nvme1n1'
    var btn = tr.querySelector('button[device], [device^="/dev/"]');
    if (btn) {
      var d = basename(btn.getAttribute('device') || '');
      if (d === device) return true;
    }
    var text = (tr.textContent || '').replace(/\s+/g, ' ');
    if (text.indexOf('(' + device + ')') !== -1) return true;
    if (text.indexOf('/dev/' + device) !== -1) return true;
    return false;
  }

  function annotateDiskRows() {
    var tbody = document.getElementById('disk-table-body');
    if (!tbody) return;
    clearDiskBadges(tbody);

    var byDev = {};
    exportsList.forEach(function (ex) { byDev[ex.device] = ex; });
    var keys = Object.keys(byDev);
    if (!keys.length) return;

    var rows = tbody.querySelectorAll('tr');
    for (var i = 0; i < rows.length; i++) {
      var tr = rows[i];
      var hit = null;
      for (var k = 0; k < keys.length; k++) {
        var dev = keys[k];
        if (rowMatchesDevice(tr, dev)) {
          hit = byDev[dev];
          break;
        }
        // partition export → parent disk row
        var parent = parentDisk(dev);
        if (parent && rowMatchesDevice(tr, parent)) {
          hit = byDev[dev];
          break;
        }
      }
      if (!hit) continue;
      // Identification column = 2nd td (Device | Identification | …)
      var tds = tr.children;
      var target = tds.length > 1 ? tds[1] : tds[0];
      if (!target || target.querySelector('.nbd-ud-badge')) continue;
      target.appendChild(makeBadge(hit));
    }
  }

  function findSharesBlock() {
    // UD: .show-shares contains SMB | NFS | ISO title + remotes table
    var show = document.querySelector('.show-shares');
    if (show) return show;
    var remotes = document.getElementById('remotes-table-body');
    if (remotes) {
      var table = remotes.closest('table');
      return table ? table.parentNode : null;
    }
    return null;
  }

  function ensureHostsPanel() {
    var existing = document.getElementById(panelId);
    if (existing) return existing;

    var host = findSharesBlock();
    if (!host) {
      // Fallback: after unassigned disks table
      var disks = document.getElementById('disk-table-body');
      host = disks ? disks.closest('table') : null;
      if (host) host = host.parentNode;
    }
    if (!host) return null;

    var wrap = document.createElement('div');
    wrap.id = panelId;
    wrap.className = 'nbd-ud-hosts-wrap';
    wrap.innerHTML =
      '<div class="title shift" style="margin-top:0.75em">' +
      '<span class="left nbd-ud-hosts-title">' +
      'NBD Hosts <span style="font-weight:500;opacity:0.85">(this Unraid · not SMB/NFS mounts)</span>' +
      '</span></div>' +
      '<p class="nbd-ud-hosts-note">' +
      'Disks currently published by <strong>NBD Export</strong> Host. ' +
      'RO = read-only; <span class="nbd-ud-mode-rw">RW</span> = writable. ' +
      'Manage under <a href="/Settings/NBDExport">Settings → Network Services → NBD</a>.' +
      '</p>' +
      '<table class="nbd-ud-hosts-table tablesorter samba_mounts">' +
      '<thead><tr>' +
      '<td>Mode</td><td>Device</td><td>Clients use</td><td>Label</td><td></td>' +
      '</tr></thead>' +
      '<tbody id="nbd-ud-hosts-body"></tbody>' +
      '</table>';

    // Place after remotes-buttons if present, else after shares block content
    var buttons = document.getElementById('remotes-buttons');
    if (buttons && buttons.parentNode) {
      if (buttons.nextSibling) {
        buttons.parentNode.insertBefore(wrap, buttons.nextSibling);
      } else {
        buttons.parentNode.appendChild(wrap);
      }
    } else {
      host.appendChild(wrap);
    }
    return wrap;
  }

  function renderHostsPanel() {
    var panel = ensureHostsPanel();
    if (!panel) return;
    var body = document.getElementById('nbd-ud-hosts-body');
    if (!body) return;

    if (!exportsList.length) {
      panel.classList.add('nbd-ud-empty');
      body.innerHTML =
        '<tr><td colspan="5" class="nbd-muted" style="opacity:0.75">' +
        'No NBD hosts on this server right now.' +
        '</td></tr>';
      // Keep section visible but subtle when empty? Hide to reduce noise.
      panel.style.display = 'none';
      return;
    }

    panel.classList.remove('nbd-ud-empty');
    panel.style.display = '';
    var html = '';
    exportsList.forEach(function (ex) {
      var ro = !!ex.read_only;
      var modeCls = ro ? 'nbd-ud-mode-ro' : 'nbd-ud-mode-rw';
      var modeTxt = ro ? 'NBD RO' : 'NBD RW';
      html += '<tr>' +
        '<td><span class="' + modeCls + '">' + modeTxt + '</span></td>' +
        '<td><code>' + escapeHtml(ex.path || ('/dev/' + ex.device)) + '</code></td>' +
        '<td><code>' + escapeHtml(ex.url || '') + '</code></td>' +
        '<td>' + escapeHtml(ex.label || '') + '</td>' +
        '<td><a href="/Settings/NBDExport">Open NBD</a></td>' +
        '</tr>';
    });
    body.innerHTML = html;
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function isUdMainPage() {
    // Disk table is the definitive signal for Main → Unassigned Devices
    if (document.getElementById('disk-table-body')) return true;
    if (document.getElementById('usb_devices_list')) return true;
    var path = (location.pathname || '') + (location.hash || '');
    if (/UnassignedDevices/i.test(path)) return true;
    // Title / heading sometimes present before tbody fills
    var t = document.body ? (document.body.innerText || '') : '';
    if (t.indexOf('Unassigned Disk') !== -1 && t.indexOf('Identification') !== -1) return true;
    return false;
  }

  function paint() {
    if (!isUdMainPage()) return;
    ensureStyle();
    annotateDiskRows();
    renderHostsPanel();
  }

  function refresh() {
    loadExports(function (list) {
      exportsList = list;
      paint();
    });
  }

  function watch() {
    var root = document.getElementById('disk-table-body') ||
      document.getElementById('usb_devices_list') ||
      document.querySelector('.show-shares') ||
      document.body;
    if (!root || typeof MutationObserver === 'undefined') return;
    var t = null;
    var mo = new MutationObserver(function () {
      if (t) clearTimeout(t);
      t = setTimeout(paint, 150);
    });
    mo.observe(root, { childList: true, subtree: true });
  }

  function boot() {
    var tries = 0;
    function wait() {
      tries++;
      if (isUdMainPage() || tries > 60) {
        if (isUdMainPage()) {
          refresh();
          watch();
          setInterval(refresh, POLL_MS);
        }
        return;
      }
      setTimeout(wait, 250);
    }
    wait();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
