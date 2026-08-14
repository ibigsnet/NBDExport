<?php
/**
 * Shared JS for Host / Pull / Settings tabs (presets + confirms).
 * Expects $destructive and $presets from nbd-page-boot.php.
 */
if (!isset($destructive)) {
  $destructive = 'no';
}
if (!isset($presets) || !is_array($presets)) {
  $presets = [];
}
?>
<script>
(function () {
  var destructiveOn = <?= $destructive === 'yes' ? 'true' : 'false' ?>;
  var NBD_PRESETS = <?= json_encode($presets, JSON_UNESCAPED_SLASHES) ?> || {};

  window.nbdApplyHostPreset = function (name) {
    if (!name || !NBD_PRESETS[name] || NBD_PRESETS[name].type !== 'host') return;
    var f = NBD_PRESETS[name].fields || {};
    var dev = document.getElementById('nbd_device');
    var bind = document.getElementById('nbd_bind');
    var port = document.getElementById('nbd_port');
    var ro = document.getElementById('nbd_read_only');
    var lab = document.getElementById('nbd_label');
    if (dev && f.device) dev.value = f.device;
    if (bind && f.bind) bind.value = f.bind;
    if (port && f.port) port.value = f.port;
    if (ro && f.read_only) ro.value = f.read_only;
    if (lab && f.label != null) lab.value = f.label;
  };
  window.nbdApplyPullPreset = function (name) {
    if (!name || !NBD_PRESETS[name] || NBD_PRESETS[name].type !== 'pull') return;
    var f = NBD_PRESETS[name].fields || {};
    var u = document.getElementById('nbd_url');
    var o = document.getElementById('nbd_image_out');
    var fmt = document.getElementById('nbd_format');
    if (u && f.nbd_url) u.value = f.nbd_url;
    if (o && f.output) o.value = f.output;
    if (fmt && f.format) fmt.value = f.format;
  };
  window.nbdFillHostPreset = function () {
    var src = document.getElementById('nbd_export_form');
    if (!src) return true;
    var el;
    el = document.getElementById('nbd_ps_device'); if (el) el.value = (src.querySelector('[name=device]') || {}).value || '';
    el = document.getElementById('nbd_ps_bind'); if (el) el.value = (src.querySelector('[name=bind]') || {}).value || '';
    el = document.getElementById('nbd_ps_port'); if (el) el.value = (src.querySelector('[name=port]') || {}).value || '';
    el = document.getElementById('nbd_ps_ro'); if (el) el.value = (src.querySelector('[name=read_only]') || {}).value || 'yes';
    el = document.getElementById('nbd_ps_label'); if (el) el.value = (src.querySelector('[name=label]') || {}).value || '';
    return true;
  };
  window.nbdFillPullPreset = function () {
    var src = document.getElementById('nbd_pull_form');
    if (!src) return true;
    var el;
    el = document.getElementById('nbd_ps_url'); if (el) el.value = (src.querySelector('[name=nbd_url]') || {}).value || '';
    el = document.getElementById('nbd_ps_out'); if (el) el.value = (src.querySelector('[name=output]') || {}).value || '';
    el = document.getElementById('nbd_ps_fmt'); if (el) el.value = (src.querySelector('[name=format]') || {}).value || 'qcow2';
    return true;
  };

  window.nbdScanNetwork = function () {
    var btn = document.getElementById('nbd_scan_btn');
    var st = document.getElementById('nbd_scan_status');
    var box = document.getElementById('nbd_scan_results');
    if (st) st.textContent = 'Scanning private LANs for NBD ports (10809+) and peer beacons (10808)…';
    if (box) {
      box.style.display = 'none';
      box.innerHTML = '';
    }
    if (btn) btn.disabled = true;
    var url = '/plugins/NBDExport/include/nbd-scan.php?probe_info=1&_=' + Date.now();
    fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (btn) btn.disabled = false;
        if (!data || !data.ok) {
          if (st) st.textContent = (data && data.error) ? data.error : 'Scan failed';
          return;
        }
        var n = (data.hits && data.hits.length) || 0;
        if (st) {
          st.textContent = 'Scan finished in ' + (data.seconds || '?') + 's — '
            + n + ' host(s) on ' + ((data.subnets && data.subnets.join(', ')) || 'private LANs');
        }
        if (!box) return;
        if (!n) {
          box.style.display = 'block';
          box.innerHTML = '<p class="nbd-muted">No NBD listeners or NBD Export beacons found. '
            + 'On the peer: Host tab → start export (beacon listens on TCP <strong>10808</strong> while exports are up). '
            + 'Check bind IP is reachable from this Unraid.</p>';
          return;
        }
        var html = '<table class="tablesorter" style="width:auto;min-width:32em">'
          + '<thead><tr>'
          + '<th>Host</th><th>Kind</th><th>Export</th><th>RO</th><th>Size</th><th></th>'
          + '</tr></thead><tbody>';
        data.hits.forEach(function (h) {
          var hostLabel = (h.hostname ? h.hostname + ' · ' : '') + h.ip
            + (h.version ? ' <span class="nbd-muted">v' + h.version + '</span>' : '');
          var kind = h.kind === 'peer'
            ? '<span class="nbd-badge-ok">NBD Export peer</span>'
            : '<span class="nbd-badge-info">NBD port open</span>';
          var exs = h.exports || [];
          if (!exs.length) {
            html += '<tr><td>' + hostLabel + '</td><td>' + kind + '</td><td colspan="3" class="nbd-muted">beacon only / no exports</td><td></td></tr>';
            return;
          }
          exs.forEach(function (ex, idx) {
            var ro = ex.read_only === true ? 'RO' : (ex.read_only === false ? '<span class="nbd-bad">RW</span>' : '—');
            var size = (ex.info && ex.info.virtual_size_h) ? ex.info.virtual_size_h : '—';
            var lab = [ex.label, ex.device_name].filter(Boolean).join(' · ') || ex.url;
            var esc = (ex.url || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
            html += '<tr>'
              + (idx === 0 ? '<td rowspan="' + exs.length + '">' + hostLabel + '</td><td rowspan="' + exs.length + '">' + kind + '</td>' : '')
              + '<td><code>' + esc + '</code>'
              + (lab && lab !== ex.url ? '<br><span class="nbd-muted">' + lab.replace(/</g, '') + '</span>' : '')
              + '</td><td>' + ro + '</td><td>' + size + '</td>'
              + '<td><input type="button" value="Use" onclick="nbdScanUseUrl(\'' + esc.replace(/'/g, "\\'") + '\')"></td>'
              + '</tr>';
          });
        });
        html += '</tbody></table>'
          + '<p class="nbd-muted" style="margin-top:0.5em">Live VM disk on <code>nbd://</code> is Attach (not Pull) — see client-attach docs. '
          + 'Writable peers need care (single writer).</p>';
        box.innerHTML = html;
        box.style.display = 'block';
      })
      .catch(function (e) {
        if (btn) btn.disabled = false;
        if (st) st.textContent = 'Scan error: ' + (e && e.message ? e.message : 'network');
      });
  };
  window.nbdScanUseUrl = function (u) {
    var el = document.getElementById('nbd_url');
    if (el) {
      el.value = u;
      el.focus();
    }
    var st = document.getElementById('nbd_scan_status');
    if (st) st.textContent = 'Filled NBD URL — set output path and Pull, or use Attach/VM patterns from docs.';
  };

  window.nbdConfirmDestructiveSave = function (form) {
    if (!form) return true;
    var sel = form.querySelector('[name="destructive_mode"]');
    if (!sel || sel.value !== 'yes') return true;
    return window.confirm(
      'Enable Destructive mode?\n\n' +
      'This unlocks riskier Host options (you still pick the device later):\n\n' +
      '• Writable host — peer can write blocks to the Unraid disk you select ' +
      '(not only read them over NBD)\n\n' +
      '• Host disks that are already in use or critical:\n' +
      '  – Unraid array / parity / cache / pool members\n' +
      '  – disks with a mounted filesystem\n' +
      '  – the Unraid boot device (usually USB flash; whatever holds /boot)\n\n' +
      'Safe default is OFF: read-only NBD host of free, unassigned disks.\n' +
      'Leave OFF unless you intentionally need one of the above.'
    );
  };

  window.nbdConfirmExport = function (form) {
    var conf = form.querySelector('#nbd_confirm');
    if (conf) conf.value = 'no';
    var devSel = form.querySelector('#nbd_device');
    var roSel = form.querySelector('#nbd_read_only');
    if (!devSel || !devSel.value) {
      window.alert('Select a device to export.');
      return false;
    }
    var opt = devSel.options[devSel.selectedIndex];
    var warn = opt && opt.getAttribute('data-warn') === '1';
    var flags = (opt && opt.getAttribute('data-flags')) || '';
    var ro = !roSel || roSel.value === 'yes';
    var needConfirm = !ro || warn;

    var devPath = devSel.value;
    var devLabel = (opt && opt.textContent) ? String(opt.textContent).replace(/\s+/g, ' ').trim() : devPath;

    if (!ro && !destructiveOn) {
      window.alert(
        'Writable host is blocked.\n\n' +
        'Either set Read-only to Yes, or enable Destructive mode under Settings ' +
        '(allows a peer to write to the Unraid disk you select).'
      );
      return false;
    }
    if (warn && !destructiveOn) {
      window.alert(
        'This Unraid disk is already in use or critical:\n  ' + devLabel + '\n\n' +
        'Flags: ' + (flags || 'array / mounted / flash') + '\n\n' +
        'Destructive mode (Settings) is required before hosting array/cache/pool ' +
        'members, mounted disks, or the Unraid boot device — even read-only.\n' +
        'Prefer an unassigned, unmounted disk for NBD host.'
      );
      return false;
    }

    if (needConfirm) {
      var msg = 'Host this Unraid disk on the network?\n  ' + devLabel + '\n  (' + devPath + ')\n\n';
      msg += 'Publishes raw blocks via NBD (Network Block Device). A client attaches ' +
        'as a remote disk (tools, nbd-client, Pull tab, qemu-img, …).\n\n';
      if (!ro) {
        msg += 'WARNING: WRITABLE — the peer can write to this Unraid disk and can destroy data.\n\n';
      } else {
        msg += 'Read-only — peer can read blocks but cannot write this disk through NBD.\n\n';
      }
      if (warn) {
        msg += 'Note: this disk is marked in-use/critical (' + (flags || 'array/mounted/flash') + ').\n\n';
      }
      msg += 'Continue?';
      if (!window.confirm(msg)) return false;
      if (!ro) {
        if (!window.confirm(
          'FINAL CONFIRMATION\n\n' +
          'Writable NBD — peer can write to:\n  ' + devLabel + '\n  ' + devPath + '\n\n' +
          'A mistake can destroy data on this Unraid disk.'
        )) return false;
      }
      if (conf) conf.value = 'yes';
    } else {
      if (!window.confirm(
        'Host this Unraid disk on the network (read-only)?\n  ' + devSel.value + '\n\n' +
        'Clients use nbd://IP:port. Multi-disk: use another port for the next host.'
      )) return false;
      if (conf) conf.value = 'no';
    }
    return true;
  };

  window.nbdConfirmImage = function (form) {
    var out = form.querySelector('#nbd_image_out');
    var path = out ? String(out.value || '').trim() : '';
    if (!path) {
      window.alert('Enter an output path under /mnt/…');
      return false;
    }
    if (path.indexOf('/dev/') === 0) {
      window.alert('Output cannot be a block device (/dev/…). Use a file under /mnt/.');
      return false;
    }
    return window.confirm(
      'Pull remote NBD disk into a file on this Unraid?\n  → ' + path + '\n\nContinue?'
    );
  };

  // Live status auto-refresh lives in nbd-live-watch.php (footer) so Status tab gets it too.
})();
</script>
