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

  window.nbdConfirmDestructiveSave = function (form) {
    if (!form) return true;
    var sel = form.querySelector('[name="destructive_mode"]');
    if (!sel || sel.value !== 'yes') return true;
    return window.confirm(
      'Enable Destructive mode?\n\n' +
      'This unlocks riskier Host options (you still pick the device later):\n\n' +
      '• Writable host — peer can write to the Unraid disk you select ' +
      '(not just image it read-only)\n\n' +
      '• Host disks that are already in use or critical:\n' +
      '  – Unraid array / parity members\n' +
      '  – disks with a mounted filesystem\n' +
      '  – the Unraid flash (USB boot) drive\n\n' +
      'Safe default is OFF: only read-only host of free, non-array disks.\n' +
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
        'Destructive mode (Settings) is required before hosting array members, ' +
        'mounted disks, or the Unraid flash drive — even read-only.\n' +
        'Prefer an unassigned, unmounted disk for imaging.'
      );
      return false;
    }

    if (needConfirm) {
      var msg = 'Host this Unraid disk on the network?\n  ' + devLabel + '\n  (' + devPath + ')\n\n';
      msg += 'Publishes raw blocks via NBD. A client must connect (Pull tab or qemu-img).\n\n';
      if (!ro) {
        msg += 'WARNING: WRITABLE — the peer can write to this Unraid disk and can destroy data.\n\n';
      } else {
        msg += 'Read-only — peer can image this disk but cannot write it.\n\n';
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
})();
</script>
