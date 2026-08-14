/**
 * NBD Export — Unassigned Devices (Main → Unassigned Devices) overlay.
 *
 * Opt-in. Best-effort DOM (UD owns the page):
 *  1) Fixed-width RO/RW slot on disk Identification cells (no table reflow/flicker)
 *  2) Stable "NBD Hosts" panel under SMB | NFS | ISO shares
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
    // Fixed width = RO/RW swap and empty/full never shift columns.
    // Width sized for "NBD RW" (widest label).
    s.textContent =
      '.nbd-ud-slot{' +
      'display:inline-block;box-sizing:border-box;' +
      'width:4.75em;min-width:4.75em;max-width:4.75em;' +
      'margin-left:0.45em;vertical-align:middle;' +
      'text-align:center;line-height:1.35;' +
      'font-size:0.82em;font-weight:700;letter-spacing:0.02em;' +
      'white-space:nowrap;border-radius:3px;padding:0.08em 0;' +
      'border:1px solid transparent' +
      '}' +
      '.nbd-ud-slot.is-empty{' +
      'visibility:hidden;border-color:transparent;background:transparent;color:transparent' +
      '}' +
      '.nbd-ud-slot.is-ro{' +
      'visibility:visible;color:#1a5c32;' +
      'background:rgba(61,139,90,0.22);border-color:rgba(61,139,90,0.45)' +
      '}' +
      '.nbd-ud-slot.is-rw{' +
      'visibility:visible;color:#fff;background:#b33;border-color:#822' +
      '}' +
      '.nbd-ud-slot a{color:inherit;text-decoration:none;display:block}' +
      '.nbd-ud-slot a:hover{text-decoration:underline}' +
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

  /** Disk rows only (have hdd= on whole-disk toggle), not partition sub-rows. */
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
    var d = rowDevice(tr);
    if (d === device) return true;
    var text = (tr.textContent || '').replace(/\s+/g, ' ');
    if (text.indexOf('(' + device + ')') !== -1) return true;
    return false;
  }

  function ensureSlot(identTd) {
    var slot = identTd.querySelector('.nbd-ud-slot');
    if (slot) return slot;
    slot = document.createElement('span');
    slot.className = 'nbd-ud-slot is-empty';
    slot.setAttribute('aria-hidden', 'true');
    // Invisible width template (same as widest real label)
    slot.innerHTML = '<a href="/Settings/NBDExport">NBD RW</a>';
    identTd.appendChild(slot);
    return slot;
  }

  function setSlot(slot, ex) {
    if (!slot) return;
    if (!ex) {
      if (slot.getAttribute('data-nbd-state') === 'empty') return;
      slot.className = 'nbd-ud-slot is-empty';
      slot.setAttribute('data-nbd-state', 'empty');
      slot.setAttribute('aria-hidden', 'true');
      slot.removeAttribute('data-nbd-dev');
      slot.title = '';
      var a0 = slot.querySelector('a');
      if (a0) {
        a0.textContent = 'NBD RW'; // keep width template
        a0.removeAttribute('title');
      }
      return;
    }
    var ro = !!ex.read_only;
    var mode = ro ? 'RO' : 'RW';
    var state = ro ? 'ro' : 'rw';
    var title = 'NBD Export Host · ' + mode +
      (ex.url ? ' · ' + ex.url : '') +
      (ex.label ? ' · ' + ex.label : '') +
      ' — Settings → Network Services → NBD';
    // Skip DOM write if unchanged (stops flicker)
    if (slot.getAttribute('data-nbd-state') === state &&
        slot.getAttribute('data-nbd-dev') === ex.device) {
      return;
    }
    slot.className = 'nbd-ud-slot is-' + state;
    slot.setAttribute('data-nbd-state', state);
    slot.setAttribute('data-nbd-dev', ex.device);
    slot.setAttribute('aria-hidden', 'false');
    slot.title = title;
    var a = slot.querySelector('a');
    if (!a) {
      a = document.createElement('a');
      a.href = '/Settings/NBDExport';
      slot.appendChild(a);
    }
    a.href = '/Settings/NBDExport';
    a.textContent = 'NBD ' + mode;
    a.title = title;
  }

  /**
   * Ensure every disk row has a fixed-width slot; fill hosted ones.
   * Never removes slots (prevents column shift). Never clears then re-adds.
   */
  function annotateDiskRows() {
    var tbody = document.getElementById('disk-table-body');
    if (!tbody) return;

    var byDev = {};
    exportsList.forEach(function (ex) { byDev[ex.device] = ex; });
    // Also index parent for partition exports
    exportsList.forEach(function (ex) {
      var p = parentDisk(ex.device);
      if (p && !byDev[p]) byDev[p] = ex;
    });

    var rows = tbody.querySelectorAll('tr');
    for (var i = 0; i < rows.length; i++) {
      var tr = rows[i];
      if (!isDiskRow(tr)) continue;
      var tds = tr.children;
      var ident = tds.length > 1 ? tds[1] : tds[0];
      if (!ident) continue;

      var slot = ensureSlot(ident);
      var dev = rowDevice(tr);
      var hit = dev ? byDev[dev] : null;
      if (!hit) {
        // fallback match
        var keys = Object.keys(byDev);
        for (var k = 0; k < keys.length; k++) {
          if (rowMatchesDevice(tr, keys[k])) {
            hit = byDev[keys[k]];
            break;
          }
        }
      }
      setSlot(slot, hit || null);
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
    if (/UnassignedDevices/i.test(path)) return true;
    return false;
  }

  function paint(force) {
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
      var sig = exportSig(list);
      var same = (sig === lastExportSig);
      exportsList = list;
      lastExportSig = sig;
      // Always re-slot after UD AJAX may have wiped slots; skip panel rewrite if same
      paint(!same);
    });
  }

  function watch() {
    var root = document.getElementById('disk-table-body') ||
      document.getElementById('usb_devices_list') ||
      document.body;
    if (!root || typeof MutationObserver === 'undefined') return;
    var t = null;
    var mo = new MutationObserver(function (mutations) {
      // Ignore our own slot updates
      var ours = true;
      for (var i = 0; i < mutations.length; i++) {
        var m = mutations[i];
        if (m.target && m.target.classList && m.target.classList.contains('nbd-ud-slot')) continue;
        if (m.target && m.target.closest && m.target.closest('.nbd-ud-slot, #' + panelId)) continue;
        ours = false;
        break;
      }
      if (ours) return;
      if (t) clearTimeout(t);
      // Debounce: UD rewrites the whole tbody; wait until quiet, then re-attach slots
      t = setTimeout(function () { paint(true); }, 280);
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
