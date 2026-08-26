# Troubleshooting


## Contents

- [qemu-nbd / qemu-img missing](#qemu-nbd-qemu-img-missing)
- [Host fails immediately](#host-fails-immediately)
- [Peer cannot connect](#peer-cannot-connect)
- [RO Host: write fails (expected messages)](#ro-host-write-fails-expected-messages)
- [RO Host still allows writes?](#ro-host-still-allows-writes)
- [Pull job stuck / failed](#pull-job-stuck-failed)
- [Slow throughput](#slow-throughput)
- [Blank first tab / old “section 3” docs](#blank-first-tab-old-section-3-docs)
- [Uninstall left something behind](#uninstall-left-something-behind)

## qemu-nbd / qemu-img missing

These are **userspace tools** (usually with Unraid’s VM stack), not a Linux kernel version gate and not “Unraid 7.2+.”  
Enable/install VM-related components so `qemu-nbd` and `qemu-img` appear under `/usr/bin`. The **Status** tab shows detected paths.

## Host fails immediately

- Check bind IP is assigned (`ip -4 addr`)  
- Port already in use → pick another port (multi-disk needs unique ports)  
- Device path missing (VFIO-bound disks are invisible to the host)  
- Destructive mode Off while selecting array/cache/pool, mounted, boot, or writable  
- See `/var/log/nbdexport/*.log`

## Peer cannot connect

- Firewall / wrong bind IP (host only listens on that address)  
- No route to bind IP (bring up Thunderbolt Net or correct VLAN)  
- Host stopped or crashed  

Probe: `qemu-img info nbd://IP:PORT`

## RO Host: write fails (expected messages)

When the Host tab has **Read-only = Yes**, the server uses `qemu-nbd --read-only`. Clients may still **read**; **writes must fail**.

Observed with **qemu-io** / **qemu-img** (other clients may phrase errors differently):

| What you do | Typical client message |
|-------------|------------------------|
| Read with `qemu-io -r` | Succeeds |
| Write with `qemu-io -r … write …` | `Block node is read-only` |
| Open for write (no `-r`) then write | `Could not open image: Permission denied` |
| `qemu-img info` / convert **from** RO URL | Succeeds (read path only) |

On a **writable** Host (Read-only = No), the same `qemu-io write` path should succeed — only use that on disks you accept modifying.

More context: [security-and-bind.md — What “read-only” protects](security-and-bind.md#what-read-only-protects).

## RO Host still allows writes?

If a client can **modify** sectors on a Host you believe is read-only:

1. Confirm the **port** — you may be on a second export that is writable (multi-disk / multi-port).  
2. On the Host Unraid, Status / hosted list: mode must show **Read-only**, not **Writable**.  
3. Re-open Host with Read-only **Yes** (re-export).  
4. Confirm you are not writing to a **local** path or a different `nbd://` URL.

## Pull job stuck / failed

- Host not running or wrong URL  
- Destination disk full  
- Path not under `/mnt/` (or `/tmp/`)  
- Check job log on **Status** or `/var/log/nbdexport/job-*.log`

## Slow throughput or failed mid-pull

- Multi-terabyte jobs need **sustained** bandwidth and a link that stays up for hours — Thunderbolt host-net or 10G+ when you can  
- Solid private Wi‑Fi can work for smaller disks (single stream + sparse convert; re-Pull while the host is still up). Spotty/congested wireless often fails mid-`qemu-img convert` — ordinary TCP, no special resume  
- Prefer Thunderbolt host-net or 10G+ wired; raise MTU on **both** ends if appropriate (Thunderbolt Net docs)  
- Trained Thunderbolt rate ≠ full TCP — expect less than the sticker number  
- Reality check: Thunderbolt 4–class host-net often trains ~**20 Gbit/s each way** (not 40 each way), roughly **2× a 10 Gbit/s NIC** one-way — if you expected that class of speed, check bind IP (guest Wi‑Fi/br0 by mistake), cable, or CPU/storage on the Pull side  

## Blank first tab / old “section 3” docs

UI is **tabs** (Status · Host · Pull · Settings), not numbered sections. Update the plugin and hard-refresh. Docs live in this repo under `docs/`.

## Logs tab vs Status History

- **Status → History** shows job **cards** (run JSON under `/var/run/nbdexport/`).
  **Remove from list** drops those cards only — **log files stay** under `/var/log/nbdexport/`.
- **Logs** tab lists every `*.log` there (Pull, Host, beacon), including after History clear.
  **Clear log** (top and bottom) deletes finished logs; live job/host/beacon logs are kept.

## Pull job failure codes (Status “Reason”)

Status maps wrapper tokens, exit codes, and common qemu messages to a short **Reason**
so the basic cause is clear without digging the log. Full map:
`nbd_fail_reason_table()` in `include/nbd-lib.php`.

### Wrapper / user actions

| Code / marker | Meaning |
|---------------|---------|
| `wait_src` | Could not open NBD/source (peer down, wrong port, no route) |
| `stopped_by_user` | Stop/Cancel from Status |
| `cancelled_while_queued` | Removed from queue before start |
| `process_exited` | Wrapper/process exited without a clearer marker |
| `convert` (no mapped rc) | Convert failed — open Log or **Found a bug?** |

### Exit codes (convert / signals)

| Code / marker | Meaning |
|---------------|---------|
| `convert rc=138` | Killed by SIGUSR1 (old progress bug — update + Retry) |
| `convert rc=137` / `9` | SIGKILL (OOM or manual kill) |
| `convert rc=143` / `15` | SIGTERM (Stop / shutdown) |
| `convert rc=139` | SIGSEGV crash |
| `convert rc=134` | SIGABRT |
| `convert rc=130` | SIGINT / Ctrl-C |
| `convert rc=129` | SIGHUP |

### Network / disk (log fragments)

| Code / marker | Meaning |
|---------------|---------|
| `no route to host` | Network / VPN / firewall to NBD host |
| `connection refused` | Export down or wrong port |
| `connection reset by peer` | Peer stopped mid-pull |
| `connection timed out` | Timed out reaching NBD |
| `network is unreachable` / `host is down` | Path to peer is dead |
| `no space left` / `disk quota exceeded` | Destination full / quota |
| `permission denied` / `read-only file system` | Output path permissions |
| `input/output error` | I/O on source or destination |
| `could not open` / `no such file or directory` | Missing path/device |
| `failed to connect` / `export not found` | NBD peer / export name |
| `broken pipe` | Peer closed mid-transfer |

### Unknown reasons — Found a bug?

If Reason is **outside** that map, the Failed card shows a right-anchored
**Found a bug?** button. It opens a panel with:

1. **Plugin diagnostics** (copy/paste) — version, Unraid, job id, source/output,
   fail code/reason, qemu version, log tail  
2. Links to the [Unraid support forum](https://forums.unraid.net/topic/200219-plugin-nbd-export-host-disks-over-network-block-device-image-to-qcow2raw/),
   [GitHub Issues](https://github.com/ibigsnet/NBDExport/issues), and the
   [repository](https://github.com/ibigsnet/NBDExport)  
3. A pointer to Unraid **Tools → Diagnostics** for the full system zip (same idea as
   Thunderbolt Net’s empty-state diagnostics)

Paste the plugin blob (and attach the Unraid zip when filing) so we can dig further.

## Uninstall left something behind

CA Apps / Plugins **Remove** runs `plugin remove nbd.plg`. From **2026.08.26ad** the remove
script always exits 0 and wipes package + emhttp + flash plugin trees + run/log state.

If an older build left leftovers after a failed CA uninstall:

```bash
plugin remove nbd.plg
# or, if the plg symlink is already gone but files remain:
removepkg NBDExport-*-x86_64-1
rm -rf /usr/local/emhttp/plugins/NBDExport /usr/local/emhttp/plugins/NbdExport
rm -rf /boot/config/plugins/NBDExport /boot/config/plugins/NbdExport
rm -rf /var/run/nbdexport /var/log/nbdexport
```

Your **qcow2/raw under `/mnt/`** are kept. Export JSON under `/boot/config/nbdexport-config-*.json`
(saved outside the plugin dir) is kept.