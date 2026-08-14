<?php
/**
 * Auto-refresh NBD tabs when Host/Pull state ends (failed, done, stopped/stale).
 * Included from nbd_page_footer so every tab (including Status) gets it.
 */
if (defined('NBDEXPORT_LIVE_WATCH')) {
  return;
}
define('NBDEXPORT_LIVE_WATCH', 1);

$nbd_live_baseline = function_exists('nbd_live_snapshot')
  ? nbd_live_snapshot()
  : ['exports' => [], 'jobs' => [], 'watch' => false, 'ts' => time()];
?>
<script>
(function () {
  'use strict';
  if (window.__nbdLiveWatchStarted) return;
  window.__nbdLiveWatchStarted = true;

  var URL = '/plugins/NBDExport/include/nbd-live-status.php';
  var POLL_ACTIVE_MS = 1500;
  var POLL_IDLE_MS = 8000;
  // Server-rendered snapshot at page paint — critical to detect jobs that finish
  // before the first XHR (otherwise baseline becomes "idle" and we never reload).
  var baseline = <?= json_encode($nbd_live_baseline, JSON_UNESCAPED_SLASHES) ?> || null;
  var timer = null;
  var reloading = false;
  var fails = 0;

  function mapById(list) {
    var m = {};
    (list || []).forEach(function (x) {
      if (x && x.id) m[x.id] = x;
    });
    return m;
  }

  function hasActive(snap) {
    if (!snap) return false;
    if (snap.watch) return true;
    var i, list = snap.exports || [];
    for (i = 0; i < list.length; i++) {
      if (list[i].key === 'listening' || list[i].key === 'process_up') return true;
    }
    list = snap.jobs || [];
    for (i = 0; i < list.length; i++) {
      if (list[i].key === 'running') return true;
    }
    return false;
  }

  function shouldReload(prev, next) {
    if (!prev || !next) return false;
    var pe = mapById(prev.exports);
    var ne = mapById(next.exports);
    var pj = mapById(prev.jobs);
    var nj = mapById(next.jobs);
    var id, a, b;

    for (id in pe) {
      if (!Object.prototype.hasOwnProperty.call(pe, id)) continue;
      a = pe[id];
      b = ne[id];
      if (!b) {
        if (a.key === 'listening' || a.key === 'process_up') return true;
        continue;
      }
      if ((a.key === 'listening' || a.key === 'process_up') &&
          b.key !== 'listening' && b.key !== 'process_up') {
        return true;
      }
    }
    for (id in ne) {
      if (!Object.prototype.hasOwnProperty.call(ne, id)) continue;
      if (!pe[id] && (ne[id].key === 'listening' || ne[id].key === 'process_up')) {
        return true;
      }
    }

    for (id in pj) {
      if (!Object.prototype.hasOwnProperty.call(pj, id)) continue;
      a = pj[id];
      b = nj[id];
      if (!b) {
        // Job state file gone after finish/stop
        if (a.key === 'running' || a.key === 'done' || a.key === 'failed') return true;
        continue;
      }
      // Any status change after page paint (running→failed/done/idle, idle→failed, …)
      if (a.key !== b.key) return true;
      if (!!a.ok !== !!b.ok && (b.key === 'done' || b.key === 'failed')) return true;
    }
    for (id in nj) {
      if (!Object.prototype.hasOwnProperty.call(nj, id)) continue;
      if (!pj[id]) return true; // new job appeared
    }
    return false;
  }

  function reloadSoon(reason) {
    if (reloading) return;
    reloading = true;
    try {
      if (window.console && console.info) {
        console.info('NBD Export: reloading — ' + reason);
      }
    } catch (e) { /* ignore */ }
    setTimeout(function () {
      // cache-bust so Unraid doesn't serve a stale tab shell
      var u = window.location.href.replace(/([?&])_nbd=\d+/, '$1').replace(/[?&]$/, '');
      var sep = u.indexOf('?') >= 0 ? '&' : '?';
      window.location.replace(u + sep + '_nbd=' + Date.now());
    }, 250);
  }

  function poll() {
    if (reloading) return;
    fetch(URL + '?_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) {
        if (!r.ok) throw new Error('http ' + r.status);
        return r.json();
      })
      .then(function (data) {
        fails = 0;
        if (!data || data.ok === false) {
          schedule(hasActive(baseline));
          return;
        }
        // Always compare to paint-time baseline first
        if (shouldReload(baseline, data)) {
          reloadSoon('state ' + JSON.stringify({
            from: { e: (baseline.exports || []).map(function (x) { return x.key; }), j: (baseline.jobs || []).map(function (x) { return x.key; }) },
            to: { e: (data.exports || []).map(function (x) { return x.key; }), j: (data.jobs || []).map(function (x) { return x.key; }) }
          }));
          return;
        }
        baseline = data;
        schedule(hasActive(data));
      })
      .catch(function () {
        fails++;
        // Keep trying quickly if we believed something was active at paint
        schedule(fails < 8 || hasActive(baseline));
      });
  }

  function schedule(active) {
    if (timer) clearTimeout(timer);
    timer = setTimeout(poll, active ? POLL_ACTIVE_MS : POLL_IDLE_MS);
  }

  // After Host/Pull POST to progressFrame, poll hard
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (!f || !f.querySelector) return;
    if (!f.querySelector('[name="nbd_action"]')) return;
    setTimeout(function () {
      if (timer) clearTimeout(timer);
      timer = setTimeout(poll, 400);
    }, 300);
  }, true);

  // First poll immediately (baseline already from PHP)
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { poll(); });
  } else {
    poll();
  }
})();
</script>
