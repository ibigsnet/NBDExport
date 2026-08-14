/**
 * NBD Export — Unassigned Devices status badges (opt-in).
 *
 * Best-effort DOM overlay on a page we do not control (Unassigned Devices).
 * Small lettering only: "NBD RO" / "NBD RW". Re-applies after UD AJAX refresh.
 */
(function () {
  'use strict';
  if (window.__nbdUdOverlay) return;
  window.__nbdUdOverlay = true;

  var STATUS_URL = '/plugins/NBDExport/include/nbd-ud-status.php';
  var POLL_MS = 8000;
  var exportsByDev = {};
  var styleId = 'nbd-ud-overlay-style';

  function ensureStyle() {
    if (document.getElementById(styleId)) return;
    var s = document.createElement('style');
    s.id = styleId;
    s.textContent =
      '.nbd-ud-badge{display:inline-block;margin-left:0.35em;font-size:0.72em;' +
      'font-weight:600;letter-spacing:0.02em;vertical-align:middle;white-space:nowrap;' +
      'opacity:0.92;cursor:default}' +
      '.nbd-ud-badge-ro{color:#3d8b5a}' +
      '.nbd-ud-badge-rw{color:#c00;font-weight:700}' +
      '.nbd-ud-badge a{color:inherit;text-decoration:none;border-bottom:1px dotted currentColor}' +
      '.nbd-ud-badge a:hover{opacity:1}';
    (document.head || document.documentElement).appendChild(s);
  }

  function basename(path) {
    if (!path) return '';
    return String(path).replace(/^\/dev\//, '').replace(/\/+$/, '');
  }

  function parentDisk(base) {
    // nvme0n1p1 → nvme0n1; sdb1 → sdb; mmcblk0p1 → mmcblk0
    var m = base.match(/^(nvme\d+n\d+)p\d+$/);
    if (m) return m[1];
    m = base.match(/^(mmcblk\d+)p\d+$/);
    if (m) return m[1];
    m = base.match(/^([a-z]+)\d+$/);
    if (m) return m[1];
    return '';
  }

  function loadExports(cb) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', STATUS_URL + '?_=' + Date.now(), true);
    xhr.timeout = 5000;
    xhr.onload = function () {
      var map = {};
      try {
        var j = JSON.parse(xhr.responseText || '{}');
        if (!j || !j.enabled || !j.exports) {
          cb(map);
          return;
        }
        j.exports.forEach(function (ex) {
          var d = basename(ex.device || ex.path || '');
          if (!d) return;
          map[d] = ex;
        });
      } catch (e) { /* ignore */ }
      cb(map);
    };
    xhr.onerror = function () { cb({}); };
    xhr.ontimeout = function () { cb({}); };
    xhr.send();
  }

  function clearBadges(root) {
    var list = (root || document).querySelectorAll('.nbd-ud-badge');
    for (var i = 0; i < list.length; i++) {
      list[i].parentNode && list[i].parentNode.removeChild(list[i]);
    }
  }

  function makeBadge(ex) {
    var ro = !!ex.read_only;
    var span = document.createElement('span');
    span.className = 'nbd-ud-badge ' + (ro ? 'nbd-ud-badge-ro' : 'nbd-ud-badge-rw');
    var mode = ro ? 'RO' : 'RW';
    var title = 'NBD Export: ' + mode +
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

  function cellMatches(text, device) {
    if (!text || !device) return false;
    // UD serial column: "SERIAL (sdb)" or "SERIAL (nvme0n1)"
    var reParen = new RegExp('\\(\\s*' + device.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\)');
    if (reParen.test(text)) return true;
    if (text.indexOf('/dev/' + device) !== -1) return true;
    return false;
  }

  function annotate() {
    ensureStyle();
    var keys = Object.keys(exportsByDev);
    if (!keys.length) {
      clearBadges();
      return;
    }
    clearBadges();

    var tables = [
      document.getElementById('disk-table-body'),
      document.getElementById('usb_devices_list'),
      document.querySelector('.usb_mounts tbody')
    ];
    var rows = [];
    tables.forEach(function (tb) {
      if (!tb) return;
      var trs = tb.querySelectorAll('tr');
      for (var i = 0; i < trs.length; i++) rows.push(trs[i]);
    });
    if (!rows.length) {
      // fallback: any Main table cell mentioning /dev/ or (sdX)
      var all = document.querySelectorAll('#disk-table-body td, .usb_mounts td, table.usb_mounts td');
      for (var a = 0; a < all.length; a++) {
        if (all[a].closest('tr')) rows.push(all[a].closest('tr'));
      }
    }

    var seen = {};
    rows.forEach(function (tr) {
      if (!tr || seen[tr]) return;
      seen[tr] = true;
      var text = (tr.textContent || '').replace(/\s+/g, ' ');
      var hit = null;
      var hitDev = '';
      keys.forEach(function (dev) {
        if (hit) return;
        if (cellMatches(text, dev)) {
          hit = exportsByDev[dev];
          hitDev = dev;
        }
      });
      // Partition export: also tag parent disk row if only partition is hosted
      if (!hit) {
        keys.forEach(function (dev) {
          if (hit) return;
          var parent = parentDisk(dev);
          if (parent && cellMatches(text, parent)) {
            hit = exportsByDev[dev];
            hitDev = parent;
          }
        });
      }
      if (!hit) return;
      // Prefer serial column (usually 2nd td) for badge placement
      var tds = tr.querySelectorAll('td');
      var target = tds.length > 1 ? tds[1] : (tds[0] || tr);
      if (target.querySelector('.nbd-ud-badge')) return;
      target.appendChild(makeBadge(hit));
    });
  }

  function refresh() {
    loadExports(function (map) {
      exportsByDev = map;
      annotate();
    });
  }

  function watch() {
    var root = document.getElementById('disk-table-body') ||
      document.getElementById('usb_devices_list') ||
      document.body;
    if (!root || typeof MutationObserver === 'undefined') return;
    var t = null;
    var mo = new MutationObserver(function () {
      if (t) clearTimeout(t);
      t = setTimeout(annotate, 120);
    });
    mo.observe(root, { childList: true, subtree: true });
  }

  function boot() {
    // Only act if UD table markers exist (or appear soon)
    var tries = 0;
    function wait() {
      tries++;
      var has = document.getElementById('disk-table-body') ||
        document.getElementById('usb_devices_list') ||
        document.querySelector('.usb_mounts');
      if (has || tries > 40) {
        refresh();
        watch();
        setInterval(refresh, POLL_MS);
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
