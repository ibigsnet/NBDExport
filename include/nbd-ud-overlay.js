/**
 * NBD Export — Unassigned Devices (Main → Unassigned Devices) overlay.
 *
 * Opt-in. Best-effort DOM (UD owns the page):
 *  1) RO/RW badge on hosted disk rows only — absolute in Identification so empty
 *     rows stay stock layout (no permanent column shift / flicker).
 *  2) "NBD Hosts" panel under SMB | NFS | ISO shares.
 */
(function () {
  'use strict';
  if (window.__nbdUdOverlay) return;
  window.__nbdUdOverlay = true;

  var STATUS_URL = '/plugins/NBDExport/include/nbd-ud-status.php';
  var POLL_MS = 6000;
  var exportsList = [];
  var lastExportSig = '';
  var styleId = 'nbd-ud-overlay-style';
  var panelId = 'nbd-ud-hosts-panel';
  var painting = false;

  function ensureStyle() {
    if (document.getElementById(styleId)) return;
    var s = document.createElement('style');
    s.id = styleId;
    s.textContent =
      /* Only present when a disk is hosted — does not reserve width on other rows */
      'td.nbd-ud-ident-host{' +
      'position:relative;' +
      'padding-right:5.1em !important' + /* room for absolute badge without crushing serial */
      '}' +
      '.nbd-ud-badge{' +
      'position:absolute;right:0.25em;top:50%;transform:translateY(-50%);' +
      'box-sizing:border-box;width:4.6em;text-align:center;' +
      'font-size:0.8em;font-weight:700;letter-spacing:0.02em;' +
      'white-space:nowrap;border-radius:3px;padding:0.1em 0;line-height:1.3;' +
      'pointer-events:auto;z-index:2' +
      '}' +
      '.nbd-ud-badge-ro{' +
      'color:#1a5c32;background:rgba(61,139,90,0.22);border:1px solid rgba(61,139,90,0.45)' +
      '}' +
      '.nbd-ud-badge-rw{' +
      'color:#fff;background:#b33;border:1px solid #822' +
      '}' +
      '.nbd-ud-badge a{color:inherit;text-decoration:none;display:block}' +
      '.nbd-ud-badge a:hover{text-decoration:underline}' +
      '#' + panelId + '{margin:1.1em 0 0.5em}' +
      '#' + panelId + ' .nbd-ud-hosts-title{font-weight:600}' +
      '#' + panelId + ' .nbd-ud-hosts-note{font-size:0.9em;opacity:0.85;margin:0.25em 0 0.45em}' +
      '#' + panelId + ' table.nbd-ud-hosts-table{width:100%}' +
      '#' + panelId + ' .nbd-ud-mode-ro{color:#1a5c32;font-weight:700}' +
      '#' + panelId + ' .nbd-ud-mode-rw{color:#c00;font-weight:700}';
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

  function exportSig(list) {
    return (list || []).map(function (ex) {
      return ex.device + ':' + (ex.read_only ? 'ro' : 'rw') + ':' + (ex.url || '');
    }).sort().join('|');
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

  function isDiskRow(tr) {
    return !!(tr && tr.querySelector && tr.querySelector('[hdd]'));
  }

  function rowDevice(tr) {
    var hddEl = tr.querySelector('[hdd]');
    if (hddEl) return String(hddEl.getAttribute('hdd') || '');
    var btn = tr.querySelector('button[device]');
    if (btn) return basename(btn.getAttribute('device') || '');
    return '';
  }

  function rowMatchesDevice(tr, device) {
    if (!tr || !device) return false;
    if (rowDevice(tr) === device) return true;
    var text = (tr.textContent || '').replace(/\s+/g, ' ');
    return text.indexOf('(' + device + ')') !== -1;
  }

  function identCell(tr) {
    var tds = tr.children;
    return tds.length > 1 ? tds[1] : tds[0];
  }

  /** Strip all our disk-row chrome so UD looks stock when nothing is hosted. */
  function clearAllDiskBadges(tbody) {
    if (!tbody) return;
    var badges = tbody.querySelectorAll('.nbd-ud-badge, .nbd-ud-slot');
    for (var i = 0; i < badges.length; i++) {
      if (badges[i].parentNode) badges[i].parentNode.removeChild(badges[i]);
    }
    var cells = tbody.querySelectorAll('td.nbd-ud-ident-host');
    for (var j = 0; j < cells.length; j++) {
      cells[j].classList.remove('nbd-ud-ident-host');
    }
  }

  function applyBadge(ident, ex) {
    if (!ident || !ex) return;
    ident.classList.add('nbd-ud-ident-host');
    var ro = !!ex.read_only;
    var mode = ro ? 'RO' : 'RW';
    var state = ro ? 'ro' : 'rw';
    var title = 'NBD Export Host · ' + mode +
      (ex.url ? ' · ' + ex.url : '') +
      (ex.label ? ' · ' + ex.label : '') +
      ' — Settings → Network Services → NBD';

    var badge = ident.querySelector('.nbd-ud-badge');
    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'nbd-ud-badge';
      var a = document.createElement('a');
      a.href = '/Settings/NBDExport';
      badge.appendChild(a);
      ident.appendChild(badge);
    }
    // In-place update only when state changes
    if (badge.getAttribute('data-nbd-state') === state &&
        badge.getAttribute('data-nbd-dev') === ex.device) {
      return;
    }
    badge.className = 'nbd-ud-badge nbd-ud-badge-' + state;
    badge.setAttribute('data-nbd-state', state);
    badge.setAttribute('data-nbd-dev', ex.device);
    badge.title = title;
    var link = badge.querySelector('a');
    if (link) {
      link.href = '/Settings/NBDExport';
      link.textContent = 'NBD ' + mode;
      link.title = title;
    }
  }

  function annotateDiskRows() {
    var tbody = document.getElementById('disk-table-body');
    if (!tbody) return;

    // Nothing hosted → remove every leftover badge/padding so layout is stock
    if (!exportsList.length) {
      clearAllDiskBadges(tbody);
      return;
    }

    var byDev = {};
    exportsList.forEach(function (ex) { byDev[ex.device] = ex; });
    exportsList.forEach(function (ex) {
      var p = parentDisk(ex.device);
      if (p && !byDev[p]) byDev[p] = ex;
    });

    var hostedIds = {};
    var rows = tbody.querySelectorAll('tr');
    for (var i = 0; i < rows.length; i++) {
      var tr = rows[i];
      if (!isDiskRow(tr)) continue;
      var ident = identCell(tr);
      if (!ident) continue;

      var hit = null;
      var dev = rowDevice(tr);
      if (dev && byDev[dev]) hit = byDev[dev];
      if (!hit) {
        var keys = Object.keys(byDev);
        for (var k = 0; k < keys.length; k++) {
          if (rowMatchesDevice(tr, keys[k])) {
            hit = byDev[keys[k]];
            break;
          }
        }
      }

      if (hit) {
        applyBadge(ident, hit);
        hostedIds[hit.device] = true;
      } else {
        // This disk not hosted — strip badge/padding if we left any
        var old = ident.querySelector('.nbd-ud-badge, .nbd-ud-slot');
        if (old && old.parentNode) old.parentNode.removeChild(old);
        ident.classList.remove('nbd-ud-ident-host');
      }
    }
  }

  function findSharesBlock() {
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

    var sig = exportSig(exportsList);
    if (body.getAttribute('data-nbd-sig') === sig) return;
    body.setAttribute('data-nbd-sig', sig);

    if (!exportsList.length) {
      panel.style.display = 'none';
      body.innerHTML = '';
      return;
    }

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
    if (document.getElementById('disk-table-body')) return true;
    if (document.getElementById('usb_devices_list')) return true;
    var path = (location.pathname || '') + (location.hash || '');
    return /UnassignedDevices/i.test(path);
  }

  function paint() {
    if (!isUdMainPage() || painting) return;
    painting = true;
    try {
      ensureStyle();
      annotateDiskRows();
      renderHostsPanel();
    } finally {
      painting = false;
    }
  }

  function refresh() {
    loadExports(function (list) {
      exportsList = list;
      lastExportSig = exportSig(list);
      paint();
    });
  }

  function watch() {
    var root = document.getElementById('disk-table-body') ||
      document.getElementById('usb_devices_list') ||
      document.body;
    if (!root || typeof MutationObserver === 'undefined') return;
    var t = null;
    var mo = new MutationObserver(function (mutations) {
      var ours = true;
      for (var i = 0; i < mutations.length; i++) {
        var m = mutations[i];
        var tEl = m.target;
        if (tEl && tEl.classList && (tEl.classList.contains('nbd-ud-badge') || tEl.classList.contains('nbd-ud-slot'))) continue;
        if (tEl && tEl.closest && tEl.closest('.nbd-ud-badge, .nbd-ud-slot, #' + panelId)) continue;
        ours = false;
        break;
      }
      if (ours) return;
      if (t) clearTimeout(t);
      t = setTimeout(paint, 300);
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
