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

  function nbdSelectedBinds() {
    var boxes = document.querySelectorAll('#nbd_export_form input.nbd-bind-cb:checked');
    var out = [];
    for (var i = 0; i < boxes.length; i++) {
      if (boxes[i].value) out.push(boxes[i].value);
    }
    return out;
  }

  function nbdSetBindChecks(ips) {
    var want = {};
    if (!ips) ips = [];
    if (typeof ips === 'string') {
      ips = ips.split(/[\s,]+/).filter(Boolean);
    }
    for (var i = 0; i < ips.length; i++) want[ips[i]] = true;
    var boxes = document.querySelectorAll('#nbd_export_form input.nbd-bind-cb');
    for (var j = 0; j < boxes.length; j++) {
      boxes[j].checked = !!want[boxes[j].value];
    }
  }

  window.nbdApplyHostPreset = function (name) {
    if (!name || !NBD_PRESETS[name] || NBD_PRESETS[name].type !== 'host') return;
    var f = NBD_PRESETS[name].fields || {};
    var dev = document.getElementById('nbd_device');
    var port = document.getElementById('nbd_port');
    var ro = document.getElementById('nbd_read_only');
    var lab = document.getElementById('nbd_label');
    if (dev && f.device) dev.value = f.device;
    if (f.binds && f.binds.length) {
      nbdSetBindChecks(f.binds);
    } else if (f.bind) {
      nbdSetBindChecks(f.bind);
    }
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
    // Multi-bind: store selected IPs as comma-separated for preset_save_host
    el = document.getElementById('nbd_ps_bind'); if (el) el.value = nbdSelectedBinds().join(',');
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

  // Abortable network scan (Pull tab). Stop only cancels the browser wait —
  // the PHP scan may still finish in the background for a moment.
  var nbdScanAbort = null;
  var nbdScanIdleStyle = '';

  function nbdScanSetIdle(btn) {
    if (!btn) return;
    btn.value = 'Scan network';
    btn.disabled = false;
    btn.style.cssText = nbdScanIdleStyle || '';
    btn.removeAttribute('data-nbd-scanning');
  }

  function nbdScanSetScanning(btn) {
    if (!btn) return;
    if (!btn.getAttribute('data-nbd-scanning')) {
      nbdScanIdleStyle = btn.getAttribute('style') || '';
    }
    btn.setAttribute('data-nbd-scanning', '1');
    btn.value = 'Stop scanning';
    btn.disabled = false;
    btn.style.cssText = 'color:#fff;background:#a33;border-color:#822;font-weight:700';
  }

  window.nbdScanNetwork = function () {
    var btn = document.getElementById('nbd_scan_btn');
    var st = document.getElementById('nbd_scan_status');
    var box = document.getElementById('nbd_scan_results');

    // Second click while scanning → stop
    if (btn && btn.getAttribute('data-nbd-scanning') === '1') {
      if (nbdScanAbort) {
        try { nbdScanAbort.abort(); } catch (e) { /* ignore */ }
      }
      nbdScanAbort = null;
      nbdScanSetIdle(btn);
      if (st) st.textContent = 'Scan stopped.';
      return;
    }

    if (st) st.textContent = 'Scanning private LANs for NBD ports (10809+) and peer beacons (10808)…';
    if (box) {
      box.style.display = 'none';
      box.innerHTML = '';
    }
    nbdScanSetScanning(btn);

    if (nbdScanAbort) {
      try { nbdScanAbort.abort(); } catch (e2) { /* ignore */ }
    }
    nbdScanAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var url = '/plugins/NBDExport/include/nbd-scan.php?probe_info=1&_=' + Date.now();
    var opts = { credentials: 'same-origin', cache: 'no-store' };
    if (nbdScanAbort) opts.signal = nbdScanAbort.signal;

    fetch(url, opts)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        nbdScanAbort = null;
        nbdScanSetIdle(btn);
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
        nbdScanAbort = null;
        nbdScanSetIdle(btn);
        if (e && (e.name === 'AbortError' || e.message === 'The user aborted a request.')) {
          if (st) st.textContent = 'Scan stopped.';
          return;
        }
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
    var bindIps = nbdSelectedBinds();
    if (!bindIps.length) {
      window.alert('Select at least one network (bind IP) to host on.');
      return false;
    }
    var opt = devSel.options[devSel.selectedIndex];
    var warn = opt && opt.getAttribute('data-warn') === '1';
    var flags = (opt && opt.getAttribute('data-flags')) || '';
    var ro = !roSel || roSel.value === 'yes';
    var needConfirm = !ro || warn;

    var devPath = devSel.value;
    var devLabel = (opt && opt.textContent) ? String(opt.textContent).replace(/\s+/g, ' ').trim() : devPath;
    var bindLine = bindIps.join(', ');

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
      msg += 'Bind IP(s): ' + bindLine + '\n\n';
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
        'Host this Unraid disk on the network (read-only)?\n  ' + devSel.value + '\n' +
        'Bind IP(s): ' + bindLine + '\n\n' +
        'Clients use nbd://IP:port per selected network. Multi-disk: use another port for the next host.'
      )) return false;
      if (conf) conf.value = 'no';
    }
    return true;
  };

  /**
   * Best-practice filename extension vs Pull format (warning only — never blocks).
   * Returns { level: 'ok'|'warn'|'hint', message, suggest }
   */
  window.nbdCheckOutputExtension = function (path, format) {
    path = String(path || '').trim();
    format = String(format || 'qcow2').toLowerCase();
    var base = path.split('/').pop() || '';
    var dot = base.lastIndexOf('.');
    var ext = (dot > 0) ? base.slice(dot + 1).toLowerCase() : '';
    // strip query-ish junk
    ext = ext.replace(/[^a-z0-9]/g, '');

    var qcowExt = { qcow2: 1, qcow: 1, qc2: 1 };
    var rawExt = { img: 1, raw: 1, dd: 1, bin: 1 };

    if (format === 'qcow2') {
      if (ext === '') {
        return {
          level: 'hint',
          message: 'No file extension — for qcow2, prefer a name ending in .qcow2 (e.g. disk.qcow2).',
          suggest: path + (path.slice(-1) === '/' ? 'disk.qcow2' : '.qcow2')
        };
      }
      if (qcowExt[ext]) {
        return { level: 'ok', message: '', suggest: '' };
      }
      if (rawExt[ext]) {
        return {
          level: 'warn',
          message: 'Format is qcow2 but the path ends in .' + ext + '. '
            + 'Best practice: use .qcow2 for qcow2 images (e.g. …/name.qcow2). '
            + 'The file will still be qcow2 inside — the extension only confuses tools and humans.',
          suggest: path.replace(/\.[^.\/]+$/, '.qcow2')
        };
      }
      return {
        level: 'warn',
        message: 'Format is qcow2 but the path ends in .' + ext + '. '
          + 'Usual extension is .qcow2. Wrong extensions do not change the real format.',
        suggest: path.replace(/\.[^.\/]+$/, '.qcow2')
      };
    }

    if (format === 'raw') {
      if (ext === '') {
        return {
          level: 'hint',
          message: 'No file extension — for raw, .img or .raw is common (e.g. disk.img).',
          suggest: path + (path.slice(-1) === '/' ? 'disk.img' : '.img')
        };
      }
      if (rawExt[ext]) {
        return { level: 'ok', message: '', suggest: '' };
      }
      if (qcowExt[ext]) {
        return {
          level: 'warn',
          message: 'Format is raw but the path ends in .' + ext + ' (qcow2-style). '
            + 'Best practice: use .img or .raw for raw images. '
            + 'A .qcow2 name on a raw file will confuse qemu / VM managers.',
          suggest: path.replace(/\.[^.\/]+$/, '.img')
        };
      }
      return {
        level: 'warn',
        message: 'Format is raw but the path ends in .' + ext + '. '
          + 'Usual extensions are .img or .raw.',
        suggest: path.replace(/\.[^.\/]+$/, '.img')
      };
    }

    return { level: 'ok', message: '', suggest: '' };
  };

  window.nbdIsUserSharePath = function (path) {
    path = String(path || '').trim();
    return /^\/mnt\/user0?(\/|$)/.test(path);
  };

  window.nbdUpdatePathHint = function () {
    var hint = document.getElementById('nbd_path_hint');
    var out = document.getElementById('nbd_image_out');
    if (!hint || !out) return;
    var path = String(out.value || '').trim();
    if (!path) {
      hint.style.display = 'none';
      hint.textContent = '';
      return;
    }
    if (window.nbdIsUserSharePath(path)) {
      hint.style.display = 'block';
      hint.className = 'nbd-ext-hint nbd-ext-warn';
      hint.textContent = 'Path is under /mnt/user or /mnt/user0 (FUSE/array). '
        + 'Large sparse pulls are usually faster on /mnt/cache/…, /mnt/diskN/…, or a pool mount. '
        + 'Allowed — continue if that is what you want.';
      return;
    }
    if (/^\/mnt\/(cache|disk\d+|disks)\b/.test(path) || /^\/mnt\/[^\/]+(\/|$)/.test(path)) {
      hint.style.display = 'none';
      hint.textContent = '';
      return;
    }
    hint.style.display = 'none';
    hint.textContent = '';
  };

  window.nbdUpdateExtHint = function () {
    var hint = document.getElementById('nbd_ext_hint');
    var out = document.getElementById('nbd_image_out');
    var fmt = document.getElementById('nbd_format');
    if (!hint || !out || !fmt) return;
    var path = String(out.value || '').trim();
    window.nbdUpdatePathHint();
    if (!path) {
      hint.style.display = 'none';
      hint.textContent = '';
      return;
    }
    var r = window.nbdCheckOutputExtension(path, fmt.value);
    if (r.level === 'ok' || !r.message) {
      hint.style.display = 'none';
      hint.textContent = '';
      return;
    }
    hint.style.display = 'block';
    hint.className = r.level === 'warn' ? 'nbd-ext-hint nbd-ext-warn' : 'nbd-ext-hint nbd-ext-info';
    var t = r.message;
    if (r.suggest) t += ' Suggested: ' + r.suggest;
    hint.textContent = t;
  };

  window.nbdConfirmImage = function (form) {
    var out = form.querySelector('#nbd_image_out');
    var fmtEl = form.querySelector('#nbd_format') || document.getElementById('nbd_format');
    var path = out ? String(out.value || '').trim() : '';
    var format = fmtEl ? String(fmtEl.value || 'qcow2') : 'qcow2';
    if (!path) {
      window.alert('Enter an output path under /mnt/…');
      return false;
    }
    if (path.indexOf('/dev/') === 0) {
      window.alert('Output cannot be a block device (/dev/…). Use a file under /mnt/.');
      return false;
    }
    // Folder-only pick from fileTree — need a file name
    if (path.slice(-1) === '/') {
      window.alert('Output path ends with /. Append a file name (e.g. disk.qcow2).');
      return false;
    }
    var base = path.split('/').pop() || '';
    if (base.indexOf('.') < 0 && !window.confirm(
      'Output has no file extension.\n  ' + path + '\n\n'
      + 'Append .qcow2 / .img before continuing? (Cancel to go back and edit.)'
    )) {
      return false;
    }

    if (window.nbdIsUserSharePath(path)) {
      if (!window.confirm(
        'Output is under /mnt/user or /mnt/user0.\n\n'
        + 'For large images, /mnt/cache/… or /mnt/diskN/… is usually better.\n\n'
        + 'Continue with:\n  ' + path + '\n?'
      )) return false;
    }

    var ext = window.nbdCheckOutputExtension(path, format);
    if (ext.level === 'warn') {
      var msg = 'Extension / format mismatch\n\n'
        + ext.message + '\n\n'
        + 'Output: ' + path + '\n'
        + 'Format: ' + format + '\n';
      if (ext.suggest) msg += '\nSuggested path:\n  ' + ext.suggest + '\n';
      msg += '\nContinue with the path as typed? (Job is not blocked.)';
      if (!window.confirm(msg)) return false;
    } else if (ext.level === 'hint') {
      if (!window.confirm(
        ext.message + '\n\nOutput: ' + path + '\nFormat: ' + format + '\n\nContinue anyway?'
      )) return false;
    }

    var srcEl = form.querySelector('#nbd_url');
    var src = srcEl ? String(srcEl.value || '').trim() : '';
    var stEl = document.getElementById('nbd_source_type');
    var st = stEl ? stEl.value : 'nbd';
    var msg = 'Convert into a file on this Unraid?\n  source: ' + src + '\n  → ' + path + '\n  format: ' + format;
    if (st === 'local_device') {
      msg += '\n\nReading a whole local /dev device. Prefer unassigned/unmounted disks.';
    }
    msg += '\n\nContinue?';
    return window.confirm(msg);
  };

  window.nbdSourceTypeChanged = function () {
    var sel = document.getElementById('nbd_source_type');
    var inp = document.getElementById('nbd_url');
    var scan = document.getElementById('nbd_scan_btn');
    var browse = document.getElementById('nbd_src_browse_wrap');
    var sub = document.getElementById('nbd_pull_submit');
    if (!sel || !inp) return;
    var t = sel.value;
    if (t === 'nbd') {
      inp.placeholder = 'nbd://10.255.0.1:10809';
      if (scan) scan.style.display = '';
      if (browse) browse.style.display = 'none';
      if (sub) sub.value = 'Convert NBD → file on Unraid';
    } else if (t === 'local_device') {
      inp.placeholder = '/dev/nvme0n1';
      if (scan) scan.style.display = 'none';
      if (browse) browse.style.display = 'none';
      if (sub) sub.value = 'Convert local disk → file on Unraid';
    } else {
      inp.placeholder = '/mnt/cache/images/disk.img';
      if (scan) scan.style.display = 'none';
      if (browse) browse.style.display = 'inline-block';
      if (sub) sub.value = 'Convert local file → file on Unraid';
    }
    try {
      var pick = document.getElementById('nbd_src_pick');
      if (pick && typeof jQuery !== 'undefined') {
        jQuery(pick).next('.fileTree').slideUp('fast');
      }
    } catch (e) { /* ignore */ }
  };

  /**
   * Stock fileTreeAttach registers document mousedown to dismiss overlays.
   * That race makes absolute trees flash open/closed. Keep the tree open when
   * the gesture starts on the input or its tree (Browse.page pattern).
   */
  function nbdGuardFileTreeDismiss(inputEl) {
    if (!inputEl || inputEl.dataset.nbdFtGuard === '1') return;
    var stop = function (e) {
      e.stopPropagation();
    };
    inputEl.addEventListener('mousedown', stop, true);
    inputEl.addEventListener('click', stop, true);
    var tree = inputEl.nextElementSibling;
    if (tree && tree.classList && tree.classList.contains('fileTree')) {
      tree.addEventListener('mousedown', stop, true);
    }
    inputEl.dataset.nbdFtGuard = '1';
  }

  function nbdAttachFileTreeOnce(inputEl, onFile, onFolder) {
    if (!inputEl || typeof window.jQuery === 'undefined' || typeof jQuery.fn.fileTreeAttach !== 'function') {
      return false;
    }
    if (inputEl.dataset.nbdFt === '1') {
      nbdGuardFileTreeDismiss(inputEl);
      return true;
    }
    // Drop a stale tree node from a previous attach (tab re-entry)
    var next = inputEl.nextElementSibling;
    if (next && next.classList && next.classList.contains('fileTree')) {
      next.parentNode.removeChild(next);
    }
    jQuery(inputEl).fileTreeAttach(null, onFile || null, onFolder || null);
    inputEl.dataset.nbdFt = '1';
    nbdGuardFileTreeDismiss(inputEl);
    // Guard the tree node created by fileTreeAttach
    var tree = inputEl.nextElementSibling;
    if (tree && tree.classList && tree.classList.contains('fileTree')) {
      tree.addEventListener('mousedown', function (e) { e.stopPropagation(); }, true);
    }
    return true;
  }

  /** Local-file source: VM-style tree via pick field (keeps nbd:// field free of /mnt tree). */
  window.nbdBrowseLocalSource = function () {
    var pick = document.getElementById('nbd_src_pick');
    var url = document.getElementById('nbd_url');
    if (!pick || !url) return;
    var ok = nbdAttachFileTreeOnce(
      pick,
      function (file) {
        url.value = String(file || '');
        try { jQuery(url).trigger('change'); } catch (e) { /* ignore */ }
      },
      function (folder) {
        var next = String(folder || '');
        if (/\.(qcow2|qcow|img|raw|dd|bin)$/i.test(next.split('/').pop() || '')) {
          url.value = next;
          try { jQuery(url).trigger('change'); } catch (e) { /* ignore */ }
        }
      }
    );
    if (!ok) {
      window.alert('Folder browser unavailable (jquery.filetree.js not loaded). Type the /mnt path instead.');
      return;
    }
    jQuery(pick).trigger('click');
  };

  function nbdApplyFolderPick(out, folder) {
    var cur = String(out.value || '').trim();
    var next = String(folder || '');
    if (!next) return;
    // Folder pick: keep trailing slash so user can type a file name
    if (next.slice(-1) !== '/' && !/\.(qcow2|qcow|img|raw|dd|bin)$/i.test(next.split('/').pop() || '')) {
      next = next.replace(/\/?$/, '/');
    }
    // If they already typed a basename, preserve it when replacing the directory
    var base = '';
    if (cur && cur.slice(-1) !== '/') {
      var parts = cur.split('/');
      var last = parts[parts.length - 1] || '';
      if (last.indexOf('.') >= 0) base = last;
    }
    if (base && next.slice(-1) === '/') {
      out.value = next + base;
    } else {
      out.value = next;
    }
    try { jQuery(out).trigger('change'); } catch (e) { /* ignore */ }
    window.nbdUpdateExtHint();
  }

  // Live extension + path hints; Unraid folder picker on Pull output
  function nbdWirePullPathUi() {
    var out = document.getElementById('nbd_image_out');
    var fmt = document.getElementById('nbd_format');
    if (!out && !fmt) return;
    if (out) {
      if (!out.dataset.nbdHintWired) {
        out.addEventListener('input', window.nbdUpdateExtHint);
        out.addEventListener('change', window.nbdUpdateExtHint);
        out.dataset.nbdHintWired = '1';
      }
      // Same jquery.filetree.js as VM/Docker; CSS makes it in-flow like VM templates
      if (!nbdAttachFileTreeOnce(out, function (file) {
        out.value = String(file || '');
        try { jQuery(out).trigger('change'); } catch (e) { /* ignore */ }
        window.nbdUpdateExtHint();
      }, function (folder) {
        nbdApplyFolderPick(out, folder);
      }) && window.console && console.warn) {
        console.warn('NBD Export: jquery.filetree.js missing — output path click-browse disabled');
      }
    }
    if (fmt && !fmt.dataset.nbdHintWired) {
      fmt.addEventListener('change', window.nbdUpdateExtHint);
      fmt.dataset.nbdHintWired = '1';
    }
    if (typeof window.nbdSourceTypeChanged === 'function') {
      window.nbdSourceTypeChanged();
    }
    window.nbdUpdateExtHint();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', nbdWirePullPathUi);
  } else {
    nbdWirePullPathUi();
  }

  // Live status auto-refresh lives in nbd-live-watch.php (footer) so Status tab gets it too.
})();
</script>
