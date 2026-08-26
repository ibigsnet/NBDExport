<?php
/**
 * Live status poller: updates badges in place (no full page reload on
 * Active→Idle/Failed). Stop/Cancel forms stay usable.
 * Included from nbd_page_footer on every NBD tab.
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
  var baseline = <?= json_encode($nbd_live_baseline, JSON_UNESCAPED_SLASHES) ?> || null;
  var timer = null;
  var fails = 0;

  var BADGE_CLASSES = [
    'nbd-badge-ok', 'nbd-badge-info', 'nbd-badge-stale', 'nbd-badge-bad', 'nbd-badge-rw', 'nbd-badge-run', 'nbd-badge-paused'
  ];

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
      if (list[i].key === 'running' || list[i].key === 'queued' || list[i].key === 'paused') return true;
    }
    return false;
  }

  function setBadge(el, item) {
    if (!el || !item) return;
    var prev = el.getAttribute('data-nbd-key') || '';
    if (prev === item.key && el.textContent === item.label) {
      // Still refresh size/log even if key same
      return false;
    }
    BADGE_CLASSES.forEach(function (c) { el.classList.remove(c); });
    el.classList.add('nbd-badge');
    if (item.class) el.classList.add(item.class);
    el.textContent = item.label || item.key || '';
    el.title = item.hint || '';
    el.setAttribute('data-nbd-key', item.key || '');
    return true;
  }

  function applyExport(item) {
    var row = document.querySelector('tr[data-nbd-export-id="' + cssEscape(item.id) + '"]');
    if (!row) return;
    var badge = row.querySelector('.nbd-live-export-badge');
    if (badge) {
      if (!badge.classList.contains('nbd-live-export-badge')) badge.classList.add('nbd-live-export-badge');
      setBadge(badge, item);
    }
    var form = row.querySelector('.nbd-live-stop-form');
    if (form) {
      var live = item.key === 'listening' || item.key === 'process_up';
      form.style.display = live ? 'inline' : 'none';
    }
  }

  function applyJob(item) {
    // Cards (Status) or legacy table rows
    var row = document.querySelector('.nbd-job-card[data-nbd-job-id="' + cssEscape(item.id) + '"]')
      || document.querySelector('tr[data-nbd-job-id="' + cssEscape(item.id) + '"]');
    if (!row) return;
    var badge = row.querySelector('.nbd-live-job-badge');
    if (badge) setBadge(badge, item);
    var running = item.key === 'running';
    var paused = item.key === 'paused';
    var queued = item.key === 'queued';
    var pause = row.querySelector('.nbd-live-job-pause-form');
    if (pause) pause.style.display = running ? 'inline-flex' : 'none';
    var resume = row.querySelector('.nbd-live-job-resume-form');
    if (resume) resume.style.display = paused ? 'inline-flex' : 'none';
    var stop = row.querySelector('.nbd-live-job-stop-form');
    if (stop) stop.style.display = (running || paused) ? 'inline-flex' : 'none';
    var play = row.querySelector('.nbd-live-job-play-form');
    if (play) play.style.display = queued ? 'inline-flex' : 'none';
    var force = row.querySelector('.nbd-live-job-force-form');
    if (force) force.style.display = queued ? 'inline-flex' : 'none';
    var cancel = row.querySelector('.nbd-live-job-cancel-form');
    if (cancel) cancel.style.display = queued ? 'inline-flex' : 'none';
    var pctEl = row.querySelector('.nbd-live-job-pct');
    if (pctEl) {
      if (item.progress_pct != null && item.progress_pct !== '') {
        var p = Number(item.progress_pct);
        pctEl.textContent = isFinite(p) ? (Math.round(p * 10) / 10) + '%' : '—';
      } else {
        pctEl.textContent = '—';
      }
    }
    var elEl = row.querySelector('.nbd-live-job-elapsed');
    if (elEl) {
      var elh = item.elapsed_h ? String(item.elapsed_h) : '';
      elEl.textContent = elh ? (' · ' + elh + ' elapsed') : '';
    }
    var etaEl = row.querySelector('.nbd-live-job-eta');
    if (etaEl) {
      var eh = item.eta_h ? String(item.eta_h) : '';
      // Hide until we have a real estimate (no sticky ETA…)
      etaEl.textContent = eh ? (' · ' + eh) : '';
    }
    var size = row.querySelector('.nbd-live-job-size');
    if (size && item.output_size_h) size.textContent = ' · ' + item.output_size_h;
    var netEl = row.querySelector('.nbd-live-job-net');
    if (netEl) {
      var nh = item.rate_net_h ? String(item.rate_net_h) : '';
      netEl.textContent = nh ? (' · net ' + nh) : '';
    }
    var diskEl = row.querySelector('.nbd-live-job-disk');
    if (diskEl) {
      var dh = item.rate_disk_h ? String(item.rate_disk_h) : '';
      diskEl.textContent = dh ? (' · disk ' + dh) : '';
    }
    var started = row.querySelector('.nbd-live-job-started');
    if (started && item.started_h) started.textContent = 'started ' + item.started_h;
    var logBox = row.querySelector('[data-nbd-job-log="' + cssEscape(item.id) + '"]')
      || document.querySelector('[data-nbd-job-log="' + cssEscape(item.id) + '"]');
    if (logBox && item.log_tail != null) {
      var pre = logBox.querySelector('.nbd-live-job-log, pre.nbd-log');
      if (pre && item.log_tail !== '') {
        pre.textContent = item.log_tail;
      }
    }
  }

  function cssEscape(s) {
    // Simple attr selector escape for our ids (alphanumeric + - . _)
    return String(s).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  }

  function updateChromeCounts(snap) {
    var el = document.getElementById('nbd-live-chrome-counts');
    if (!el || !snap) return;
    var ne = typeof snap.live_exports === 'number' ? snap.live_exports : (snap.exports || []).length;
    var nj = typeof snap.live_jobs === 'number'
      ? snap.live_jobs
      : (snap.jobs || []).filter(function (j) { return j.key === 'running'; }).length;
    var nq = typeof snap.queued_jobs === 'number'
      ? snap.queued_jobs
      : (snap.jobs || []).filter(function (j) { return j.key === 'queued'; }).length;
    var t = ' · ' + ne + ' live';
    if (nj) t += ' · ' + nj + ' pull job(s)';
    if (nq) t += ' · ' + nq + ' queued';
    el.textContent = t;
  }

  /**
   * Patch badges/controls in place. Returns true if anything meaningful changed.
   * Does NOT reload the page — keeps Stop/Cancel forms and user focus.
   */
  function applySnapshot(snap) {
    if (!snap) return false;
    var changed = false;
    var prevE = mapById((baseline && baseline.exports) || []);
    var prevJ = mapById((baseline && baseline.jobs) || []);

    (snap.exports || []).forEach(function (ex) {
      var p = prevE[ex.id];
      if (!p || p.key !== ex.key || p.alive !== ex.alive || p.listening !== ex.listening) {
        changed = true;
      }
      applyExport(ex);
    });
    // Exports that vanished from snapshot — mark stopped in UI if row remains
    Object.keys(prevE).forEach(function (id) {
      if (mapById(snap.exports)[id]) return;
      var row = document.querySelector('tr[data-nbd-export-id="' + cssEscape(id) + '"]');
      if (!row) return;
      changed = true;
      var badge = row.querySelector('.nbd-live-export-badge');
      if (badge) {
        setBadge(badge, {
          key: 'stale',
          label: 'Stopped / stale',
          class: 'nbd-badge-stale',
          hint: 'No longer listening'
        });
      }
      var form = row.querySelector('.nbd-live-stop-form');
      if (form) form.style.display = 'none';
    });

    (snap.jobs || []).forEach(function (job) {
      var p = prevJ[job.id];
      if (!p || p.key !== job.key || p.output_size !== job.output_size || !!p.ok !== !!job.ok) {
        changed = true;
      }
      applyJob(job);
    });
    Object.keys(prevJ).forEach(function (id) {
      if (mapById(snap.jobs)[id]) return;
      changed = true;
      var badge = document.querySelector('tr[data-nbd-job-id="' + cssEscape(id) + '"] .nbd-live-job-badge');
      if (badge) {
        setBadge(badge, {
          key: 'idle',
          label: 'Idle',
          class: 'nbd-badge-stale',
          hint: 'Job state cleared'
        });
      }
      var form = document.querySelector('tr[data-nbd-job-id="' + cssEscape(id) + '"] .nbd-live-job-stop-form');
      if (form) form.style.display = 'none';
    });

    updateChromeCounts(snap);
    return changed;
  }

  function poll() {
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
        applySnapshot(data);
        baseline = data;
        schedule(hasActive(data));
      })
      .catch(function () {
        fails++;
        schedule(fails < 8 || hasActive(baseline));
      });
  }

  function schedule(active) {
    if (timer) clearTimeout(timer);
    timer = setTimeout(poll, active ? POLL_ACTIVE_MS : POLL_IDLE_MS);
  }

  // After Host/Pull POST, poll soon so Stop / Start reflect without full reload
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (!f || !f.querySelector) return;
    if (!f.querySelector('[name="nbd_action"]')) return;
    setTimeout(function () {
      if (timer) clearTimeout(timer);
      timer = setTimeout(poll, 500);
    }, 400);
  }, true);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { poll(); });
  } else {
    poll();
  }
})();
</script>
