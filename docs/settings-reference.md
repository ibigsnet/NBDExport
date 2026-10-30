# Settings reference

**Path:** Settings → Network Services → NBD  
**Config:** `/boot/config/plugins/NBDExport/NBDExport.cfg`  
**Memory / presets:** `/boot/config/plugins/NBDExport/memory.json`

Every tab shows a shared header: **disks currently hosted** (and an orange banner if Destructive mode is ON).

---

## Contents

- [Tabs](#tabs)
- [Settings tab](#settings-tab)
- [Host tab (NBD server)](#host-tab-nbd-server)
- [Pull tab (NBD client)](#pull-tab-nbd-client)
- [Runtime paths](#runtime-paths)

## Tabs

| Tab | Purpose |
|-----|---------|
| **Status** | Tools (`qemu-nbd` / `qemu-img`); pull jobs Active / Queued / History (list-remove keeps qcow2/raw) |
| **Host** | Publish a local Unraid disk/partition (NBD server) |
| **Pull** | Image `nbd://…` or a local disk/file into a file under `/mnt/…`; Scan network is a LAN table + button |
| **Logs** | `/var/log/nbdexport/` (survives History list-remove) |
| **Settings** | Enable, defaults, Destructive mode, export/import config |
| **Help** | Safe Mode / CLI recovery |

---

## Settings tab

| Control | Default | Notes |
|---------|---------|--------|
| Enable NBD Export | Yes | **No** + Apply stops all plugin-managed Hosts (not external qemu-nbd). Running Pulls continue; queued Pulls wait until Yes. Yes does not restart Hosts |
| Default read-only | Yes | Prefill for new Host listeners |
| Default port | 10809 | 1024–65535; multi-disk uses other free ports |
| Allow bind 0.0.0.0 | No | All interfaces (`0.0.0.0` or `::`). Dangerous; basic NBD has no auth — see [security-and-bind.md](security-and-bind.md) |
| Allow public bind IPs | No | Lets Host use a non-private address of this server (greyed out otherwise) |
| Destructive mode | No | Only for writable host or hosting array/cache/pool, mounted, or boot disks. See [destructive-mode.md](destructive-mode.md). Host tab still confirms the device. Host checklist: [hosting-safety.md](hosting-safety.md). |
| Unassigned Devices badges | No | Opt-in small **NBD RO** / **NBD RW** lettering on Main → Unassigned Devices for hosted disks. Best-effort overlay on a third-party page — [integration-unassigned-devices.md](integration-unassigned-devices.md). |
| Export config | — | Download JSON or write `/boot/config/nbdexport-config-*.json` (outside plugin dir). Uninstall wipes plugin flash state. |
| Import config | — | Path under `/boot/config/` or `/mnt/` only |

**Apply** saves settings only — it does not start hosting or pulling.

---

## Host tab (NBD server)

Publishes one local **block device** (whole disk or partition — raw blocks including partition table). Does not copy data until a client connects. Multi-disk: host again with another free port.

| Field / action | Notes |
|----------------|--------|
| Device | From `lsblk`; array/cache/pool, mounted, or boot need Destructive mode |
| Listen on (bind IPs) | Checkboxes — this server's own addresses; Thunderbolt listed first; public ones greyed out unless allowed. Writable = exactly one |
| Listen port | TCP port for the server |
| Read-only | Strongly recommended Yes |
| Label | Optional note |
| **Host disk/partition on network** | Start `qemu-nbd` |
| **Stop** (header table) | Stop that listener |

---

## Pull tab (NBD client)

Connects to a disk already hosted on the network and writes a **file** under Unraid storage. Does not publish a local disk.

| Field / action | Notes |
|----------------|--------|
| NBD URL | `nbd://ip:port` of a running host |
| Output path | File in an existing folder on a mounted disk/pool under `/mnt/` — never `/dev/…`, a symlink, `/tmp` or RAM. Written as `name.partial`, renamed on success. Placeholder: `/mnt/user/domains/disk.qcow2` |
| Overwrite existing file | No | **No** refuses an existing output. **Yes** replaces it only when the new Pull succeeds |
| Format | **qcow2** (default, sparse / VM-friendly) or **raw** (full image; often named `.img`). Other `qemu-img` formats via CLI — see [when-to-use — formats](when-to-use-nbd.md#image-formats-not-only-qcow2). |
| **Pull remote disk → file** | Background `qemu-img convert` (idle IO; queues if another Pull is running) |
| Status → **Play** / **Force start** | Start a queued Pull; Force overrides concurrency (array/parity warn) |

Jobs appear on **Status**; they keep running if you close the browser.

---

## Runtime paths

| Path | Purpose |
|------|---------|
| `/var/run/nbdexport/` | pid + state JSON |
| `/var/log/nbdexport/` | host and job logs |
| `/boot/config/plugins/NBDExport/companion.json` | soft companion marker |
| `/boot/config/plugins/NBDExport/memory.json` | last-used fields + named presets |
