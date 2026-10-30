<?php
/**
 * CLI hook run by the plugin's own background wrappers when they exit.
 *
 *   php nbd-hook.php job <job-id>             Pull wrapper finished → persist result, start next queued
 *   php nbd-hook.php export <exp-id> <rc>     qemu-nbd exited → record unexpected stop (+ notify)
 *
 * Not reachable from the WebUI (CLI only). All work runs under the plugin state lock.
 */
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}
require_once __DIR__ . '/nbd-lib.php';

$kind = (string)($argv[1] ?? '');
$id = (string)($argv[2] ?? '');

if ($kind === 'job' && nbd_valid_id($id, 'job')) {
  nbd_locked(function () use ($id) {
    $j = nbd_job_load($id);
    if (is_array($j) && empty($j['finished'])) {
      $log = (string)($j['log'] ?? '');
      $tail = ($log !== '' && is_file($log)) ? (string)@file_get_contents($log) : '';
      nbd_job_finalize($j, strpos($tail, 'NBD_JOB_OK') !== false);
    }
    nbd_jobs_reconcile();
  });
  exit(0);
}

if ($kind === 'export' && nbd_valid_id($id, 'exp')) {
  nbd_locked(function () use ($id) {
    $statefile = NBDEXPORT_RUN . '/' . $id . '.json';
    if (!is_file($statefile)) {
      return; // stopped from the UI (state already removed)
    }
    $e = @json_decode((string)@file_get_contents($statefile), true);
    if (!is_array($e)) {
      return;
    }
    $e['stale'] = true;
    $e['exit_rc'] = (int)($GLOBALS['argv'][3] ?? -1);
    $e['exited_at'] = date('c');
    nbd_maybe_notify_host_down($e, $statefile);
    nbd_beacon_ensure();
  });
  exit(0);
}

fwrite(STDERR, "usage: nbd-hook.php job <job-id> | export <exp-id> <rc>\n");
exit(2);
