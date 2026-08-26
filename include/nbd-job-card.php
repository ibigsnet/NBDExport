<?php
/**
 * Render one Pull job card for Status.
 * Expects $j (job array). Optional $nbd_queue_pos / $nbd_queue_total for ↑↓.
 */
if (!isset($j) || !is_array($j)) {
  return;
}
$jst = nbd_job_ui_status($j);
$jkey = $jst['key'] ?? '';
$running = ($jkey === 'running');
$paused = ($jkey === 'paused');
$queued = ($jkey === 'queued');
$clearable = function_exists('nbd_job_is_clearable') && nbd_job_is_clearable($j);
$external = !empty($j['external']);
$jid_raw = (string)($j['id'] ?? '');
$jid = htmlspecialchars($jid_raw);
$pct = nbd_job_progress_pct($j);
$pct_h = ($pct !== null) ? (rtrim(rtrim(number_format($pct, 1, '.', ''), '0'), '.') . '%') : '—';
$eta = function_exists('nbd_job_progress_eta') ? nbd_job_progress_eta($j) : ['label' => ''];
$eta_h = trim((string)($eta['label'] ?? ''));
$size_h = htmlspecialchars($j['output_size_h'] ?? '—');
$src = (string)($j['url'] ?? '');
$out = (string)($j['output'] ?? '');
$fmt = (string)($j['format'] ?? 'qcow2');
$src_short = (strlen($src) > 56) ? (substr($src, 0, 28) . '…' . substr($src, -24)) : $src;
$out_short = (strlen($out) > 56) ? ('…' . substr($out, -52)) : $out;
$tail = !empty($j['log']) ? nbd_log_tail_display($j['log'], 8) : '';
$open_log = ($running || $paused) ? ' open' : '';
$elapsed = function_exists('nbd_job_elapsed_seconds') ? nbd_job_elapsed_seconds($j) : null;
$elapsed_h = ($elapsed !== null) ? nbd_format_duration($elapsed) : '';
$rates = ($running && function_exists('nbd_job_io_rates')) ? nbd_job_io_rates($j) : ['net_h' => '', 'disk_h' => ''];
$net_h = (string)($rates['net_h'] ?? '');
$disk_h = (string)($rates['disk_h'] ?? '');
if ($running && $eta_h === '') {
  $eta_h = 'ETA…';
}
$out_exists = ($out !== '' && $out !== '(unknown)' && @is_file($out));
$qpos = isset($nbd_queue_pos) ? (int)$nbd_queue_pos : -1;
$qtot = isset($nbd_queue_total) ? (int)$nbd_queue_total : 0;
?>
        <div class="nbd-job-card" data-nbd-job-id="<?= $jid ?>" data-nbd-key="<?= htmlspecialchars($jkey) ?>">
          <div class="nbd-job-card-top">
<?php if ($clearable): ?>
            <label class="nbd-job-sel" title="Select for Clear">
              <input type="checkbox" class="nbd-job-cb" name="job_ids[]" value="<?= $jid ?>" form="nbd-jobs-clear-form">
            </label>
<?php else: ?>
            <span class="nbd-job-sel nbd-job-sel-spacer"></span>
<?php endif; ?>
            <span class="nbd-badge <?= htmlspecialchars($jst['class']) ?> nbd-live-job-badge"
              data-nbd-key="<?= htmlspecialchars($jkey) ?>"
              title="<?= htmlspecialchars($jst['hint']) ?>"><?= htmlspecialchars($jst['label']) ?></span>
            <span class="nbd-live-job-metrics">
              <span class="nbd-live-job-pct"><?= htmlspecialchars($pct_h) ?></span>
              <span class="nbd-muted nbd-live-job-elapsed"><?= $elapsed_h !== '' ? (' · ' . htmlspecialchars($elapsed_h) . ' elapsed') : '' ?></span>
              <span class="nbd-muted nbd-live-job-eta"><?= $eta_h !== '' ? (' · ' . htmlspecialchars($eta_h)) : '' ?></span>
              <span class="nbd-muted nbd-live-job-size"> · <?= $size_h ?></span>
              <span class="nbd-muted nbd-live-job-net"><?= $net_h !== '' ? (' · net ' . htmlspecialchars($net_h)) : '' ?></span>
              <span class="nbd-muted nbd-live-job-disk"><?= $disk_h !== '' ? (' · disk ' . htmlspecialchars($disk_h)) : '' ?></span>
            </span>
            <span class="nbd-muted nbd-live-job-started">started <?= nbd_format_when_html($j['started'] ?? '') ?></span>
          </div>
          <div class="nbd-job-card-meta">
            <code class="nbd-job-path" title="<?= htmlspecialchars($src) ?>"><?= htmlspecialchars($src_short) ?></code>
            <code class="nbd-job-path nbd-muted" title="<?= htmlspecialchars($out) ?>">→ <?= htmlspecialchars($out_short) ?></code>
          </div>
          <div class="nbd-job-card-actions nbd-live-job-actions">
              <form method="POST" action="/update.php" target="progressFrame" style="display:<?= $running ? 'inline' : 'none' ?>" class="nbd-live-job-pause-form">
                <input type="hidden" name="#file" value="NBDExport/NBDExport.cfg">
                <input type="hidden" name="#include" value="/plugins/NBDExport/include/nbd-update.php">
                <input type="hidden" name="nbd_action" value="image_pause">
                <input type="hidden" name="job_id" value="<?= $jid ?>">
                <input type="submit" name="#apply" value="Pause">
              </form>
              <form method="POST" action="/update.php" target="progressFrame" style="display:<?= $paused ? 'inline' : 'none' ?>" class="nbd-live-job-resume-form">
                <input type="hidden" name="#file" value="NBDExport/NBDExport.cfg">
                <input type="hidden" name="#include" value="/plugins/NBDExport/include/nbd-update.php">
                <input type="hidden" name="nbd_action" value="image_resume">
                <input type="hidden" name="job_id" value="<?= $jid ?>">
                <input type="submit" name="#apply" value="Resume">
              </form>
              <form method="POST" action="/update.php" target="progressFrame" style="display:<?= ($running || $paused) ? 'inline' : 'none' ?>" class="nbd-live-job-stop-form">
                <input type="hidden" name="#file" value="NBDExport/NBDExport.cfg">
                <input type="hidden" name="#include" value="/plugins/NBDExport/include/nbd-update.php">
                <input type="hidden" name="nbd_action" value="image_stop">
                <input type="hidden" name="job_id" value="<?= $jid ?>">
                <input type="submit" name="#apply" value="Stop">
              </form>
              <form method="POST" action="/update.php" target="progressFrame" style="display:<?= $queued ? 'inline' : 'none' ?>" class="nbd-live-job-play-form">
                <input type="hidden" name="#file" value="NBDExport/NBDExport.cfg">
                <input type="hidden" name="#include" value="/plugins/NBDExport/include/nbd-update.php">
                <input type="hidden" name="nbd_action" value="image_play">
                <input type="hidden" name="job_id" value="<?= $jid ?>">
                <input type="hidden" name="force" value="no">
                <input type="submit" name="#apply" value="Play">
              </form>
              <form method="POST" action="/update.php" target="progressFrame" style="display:<?= $queued ? 'inline' : 'none' ?>" class="nbd-live-job-force-form"
                onsubmit="return confirm('Force start this Pull while another may still be running?\n\nConcurrent array writes contend for parity and can stall the WebUI.');">
                <input type="hidden" name="#file" value="NBDExport/NBDExport.cfg">
                <input type="hidden" name="#include" value="/plugins/NBDExport/include/nbd-update.php">
                <input type="hidden" name="nbd_action" value="image_play">
                <input type="hidden" name="job_id" value="<?= $jid ?>">
                <input type="hidden" name="force" value="yes">
                <input type="submit" name="#apply" value="Force start">
              </form>
              <form method="POST" action="/update.php" target="progressFrame" style="display:<?= $queued ? 'inline' : 'none' ?>" class="nbd-live-job-cancel-form">
                <input type="hidden" name="#file" value="NBDExport/NBDExport.cfg">
                <input type="hidden" name="#include" value="/plugins/NBDExport/include/nbd-update.php">
                <input type="hidden" name="nbd_action" value="image_stop">
                <input type="hidden" name="job_id" value="<?= $jid ?>">
                <input type="submit" name="#apply" value="Cancel">
              </form>
<?php if ($queued && $qtot > 1): ?>
              <form method="POST" action="/update.php" target="progressFrame" style="display:inline" title="Move earlier in queue">
                <input type="hidden" name="#file" value="NBDExport/NBDExport.cfg">
                <input type="hidden" name="#include" value="/plugins/NBDExport/include/nbd-update.php">
                <input type="hidden" name="nbd_action" value="queue_move">
                <input type="hidden" name="job_id" value="<?= $jid ?>">
                <input type="hidden" name="dir" value="-1">
                <input type="submit" name="#apply" value="↑" <?= ($qpos <= 0) ? 'disabled' : '' ?>>
              </form>
              <form method="POST" action="/update.php" target="progressFrame" style="display:inline" title="Move later in queue">
                <input type="hidden" name="#file" value="NBDExport/NBDExport.cfg">
                <input type="hidden" name="#include" value="/plugins/NBDExport/include/nbd-update.php">
                <input type="hidden" name="nbd_action" value="queue_move">
                <input type="hidden" name="job_id" value="<?= $jid ?>">
                <input type="hidden" name="dir" value="1">
                <input type="submit" name="#apply" value="↓" <?= ($qpos < 0 || $qpos >= $qtot - 1) ? 'disabled' : '' ?>>
              </form>
<?php endif; ?>
<?php if ($clearable && !$external): ?>
              <form method="POST" action="/update.php" target="progressFrame" style="display:inline"
                onsubmit="return confirm('Retry this Pull with the same source and output?\n\n<?= htmlspecialchars(addslashes($src), ENT_QUOTES) ?>\n→ <?= htmlspecialchars(addslashes($out), ENT_QUOTES) ?>\n\nIncomplete output can be removed first on the next dialog.');">
                <input type="hidden" name="#file" value="NBDExport/NBDExport.cfg">
                <input type="hidden" name="#include" value="/plugins/NBDExport/include/nbd-update.php">
                <input type="hidden" name="nbd_action" value="image_retry">
                <input type="hidden" name="job_id" value="<?= $jid ?>">
                <input type="hidden" name="remove_output" value="no">
                <input type="submit" name="#apply" value="Retry">
              </form>
              <button type="button" class="nbd-job-edit-toggle" data-nbd-edit="<?= $jid ?>">Edit &amp; retry…</button>
<?php if ($out_exists): ?>
              <form method="POST" action="/update.php" target="progressFrame" style="display:inline"
                onsubmit="return confirm('DELETE output file from disk?\n\n<?= htmlspecialchars(addslashes($out), ENT_QUOTES) ?>\n\nCannot resume a stopped convert — this frees space. Job card stays until Clear.');">
                <input type="hidden" name="#file" value="NBDExport/NBDExport.cfg">
                <input type="hidden" name="#include" value="/plugins/NBDExport/include/nbd-update.php">
                <input type="hidden" name="nbd_action" value="job_delete_output">
                <input type="hidden" name="job_id" value="<?= $jid ?>">
                <input type="submit" name="#apply" value="Delete file">
              </form>
<?php endif; ?>
<?php endif; ?>
          </div>
<?php if ($clearable && !$external): ?>
          <div class="nbd-job-edit" id="nbd-edit-<?= $jid ?>" style="display:none">
            <form method="POST" action="/update.php" target="progressFrame" class="nbd-job-edit-form"
              onsubmit="return confirm('Start retry with these values?');">
              <input type="hidden" name="#file" value="NBDExport/NBDExport.cfg">
              <input type="hidden" name="#include" value="/plugins/NBDExport/include/nbd-update.php">
              <input type="hidden" name="nbd_action" value="image_retry">
              <input type="hidden" name="job_id" value="<?= $jid ?>">
              <label>Source <input type="text" name="nbd_url" value="<?= htmlspecialchars($src) ?>" style="width:min(28em,100%)"></label>
              <label>Output <input type="text" name="output" value="<?= htmlspecialchars($out) ?>" style="width:min(28em,100%)"></label>
              <label>Format
                <select name="format">
                  <option value="qcow2" <?= $fmt === 'qcow2' ? 'selected' : '' ?>>qcow2</option>
                  <option value="raw" <?= $fmt === 'raw' ? 'selected' : '' ?>>raw</option>
                </select>
              </label>
              <label><input type="checkbox" name="remove_output" value="yes" <?= $out_exists ? 'checked' : '' ?>> Remove existing output first</label>
              <input type="submit" name="#apply" value="Start retry">
            </form>
          </div>
<?php endif; ?>
<?php if ($tail !== ''): ?>
          <details class="nbd-job-log" data-nbd-job-log="<?= $jid ?>"<?= $open_log ?>>
            <summary>Log</summary>
            <pre class="nbd-log nbd-live-job-log"><?= htmlspecialchars($tail) ?></pre>
          </details>
<?php endif; ?>
        </div>
<?php
