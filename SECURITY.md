# Security — NBD Export

Copyright (c) 2026 ibigs, LLC · Author: RifleJock · License: GPL-3.0-or-later

This document is for **operators**, **Community Applications reviewers**, and **anyone deciding whether to Host a disk**. NBD is raw block I/O over TCP — treat Host like temporarily plugging a drive into another machine, over the network.

| | |
|--|--|
| **User guide (bind / isolation)** | [docs/security-and-bind.md](docs/security-and-bind.md) |
| **When Destructive mode is required** | [docs/destructive-mode.md](docs/destructive-mode.md) |
| **Hosting checklist (do this every time)** | [docs/hosting-safety.md](docs/hosting-safety.md) |
| **Support** | [Unraid forum](https://forums.unraid.net/topic/200219-plugin-nbd-export-host-disks-over-network-block-device-image-to-qcow2raw/) |

---

## TL;DR for CA / plugin review

| Expectation | How this plugin behaves |
|-------------|-------------------------|
| Safe until the user opts in | **Read-only** host default; **Destructive mode Off**; **no `0.0.0.0` bind** unless allowed; **no auto re-export** on array start |
| Idle install is quiet | No Host listener until the user starts one; no cloud / telemetry / phone-home |
| Network surface is explicit | Only what the user starts: `qemu-nbd` on a chosen IP:port; optional discovery beacon only while Host exports are up |
| Privileged but scoped | Root (Unraid plugin model). Starts `qemu-nbd` / `qemu-img`. Does **not** edit `network.cfg`, array membership, or reformat disks |
| Server-side gates | Destructive / RW / array-mounted / boot rules enforced in PHP even if the UI is bypassed |
| Clean uninstall | Stops **managed** processes only (pid files under `/var/run/nbdexport`); removes plugin tree; leaves user images under `/mnt/` |
| Supply chain | CA PluginURL → GitHub **`stable`**; development on **`main`** |

**Five-minute review path**

1. [`default.cfg`](default.cfg) — product defaults  
2. [`include/nbd-lib.php`](include/nbd-lib.php) — `nbd_export_start`, `nbd_device_risk`, bind rules  
3. [`nbd.plg`](nbd.plg) — FILE list, Method=remove, no opaque blobs  
4. This file + [docs/hosting-safety.md](docs/hosting-safety.md)  
5. Optional: [docs/destructive-mode.md](docs/destructive-mode.md)

---

## Threat model (honest)

### What NBD is

- Peers see **sectors**, not “a share with permissions.”  
- Basic **qemu-nbd has no authentication / TLS** in this plugin.  
- **Access control = network path + bind IP + stop when done.**

### Trust boundary

| Trusted | Untrusted (do not Host toward these) |
|---------|--------------------------------------|
| Machines on an isolated Thunderbolt / copper fabric you control | The public Internet |
| A VLAN or private LAN segment dedicated to lab work | Guest / IoT / untrusted Wi‑Fi clients |
| Logged-in Unraid WebUI users (same as any root plugin) | Random LAN clients that can reach a `0.0.0.0` bind |

### What a hostile client can do if they reach an open export

| Host mode | Impact |
|-----------|--------|
| **Read-only** | Read all blocks on that device (full disk image equivalent). Cannot write through NBD. |
| **Writable** | Read **and** write blocks — data loss / ransomware-class damage to that device. |

Read-only does **not** freeze local writers on the source Unraid host. Quiesce or unmount local use when you need a consistent image.

### What this plugin does *not* claim

- Not a replacement for VPN, firewall, or disk encryption at rest  
- Not multi-tenant “share this disk safely with the world”  
- Not a shared SAN / multi-writer cluster filesystem  

---

## Privilege model

| Capability | Notes |
|------------|--------|
| Runs as **root** | Standard Unraid plugin / emhttp model |
| May start **`qemu-nbd`** | Against user-selected block devices (`/dev/…`) |
| May start **`qemu-img convert`** | Pull jobs; output restricted under `/mnt/` (never `/dev/…`) |
| WebUI actions | Via Unraid `update.php` + plugin include (same auth as other Settings plugins) |

### Explicit non-goals (blast radius)

| Does **not** | |
|--------------|--|
| Edit `/boot/config/network.cfg` | Management LAN stays Unraid’s |
| Change array / pool membership | No “add disk” / wipe |
| Reformat or partition disks | |
| Install unrelated packages at boot | Tools are Unraid VM stack (`qemu-nbd` / `qemu-img`) |
| Require Thunderbolt Net or Fabric Routing | Soft companions only |
| Phone home / analytics / ads | No external calls for “license check” or telemetry |
| Inject into Unraid Core UI by default | Host status lives under **Network Services → NBD**. Optional **opt-in** soft hook for small badges on Main → Unassigned Devices (third-party page; default **Off**) — [docs/integration-unassigned-devices.md](docs/integration-unassigned-devices.md) |

---

## Defaults (safe until the user opts in)

| Setting | Default | Why |
|---------|---------|-----|
| `enabled` | **yes** | UI available; does not start listeners by itself |
| `default_read_only` | **yes** | Host exports use `qemu-nbd --read-only` |
| `default_port` | **10809** | Common qemu-nbd default (not a privileged port) |
| `allow_bind_all` | **no** | Blocks bind `0.0.0.0` until explicitly allowed |
| `destructive_mode` | **no** | Blocks RW host and array/mounted/boot sources |
| `rehydrate_on_start` | **no** | No surprise re-export after reboot / array start |
| `ud_status_overlay` | **no** | No DOM badges on Unassigned Devices until the user opts in |

Source of truth: [`default.cfg`](default.cfg). Live flash: `/boot/config/plugins/NBDExport/NBDExport.cfg`.

---

## Server-side enforcement (Host)

All Host starts go through `nbd_export_start()` in `include/nbd-lib.php`. UI confirmations are **not** the only gate.

| Rule | Enforced |
|------|----------|
| Empty bind IP refused | Yes — prefer Thunderbolt / private LAN IP |
| Bind `0.0.0.0` | Only if `allow_bind_all=yes` |
| Writable host | Requires `destructive_mode=yes` + confirm |
| Array / parity / cache / pool / `md*` | Requires Destructive even if read-only |
| Mounted filesystem on device | Requires Destructive |
| Device that backs `/boot` | Requires Destructive for RO; **writable boot device always refused** |
| Risky or RW start without `confirm` | Refused (server) |
| Pull output path under `/dev/…` | Always refused |

Destructive mode is modeled after **Unassigned Devices** destructive mode: rare, intentional, orange banner while On. Details: [docs/destructive-mode.md](docs/destructive-mode.md).

---

## Network surface while Host is running

| Listener | When | Auth | Payload / data |
|----------|------|------|----------------|
| **`qemu-nbd`** (default port **10809+**) | User started a Host export | **None** (protocol) | Raw disk blocks on that device |
| **Discovery beacon** (port **10808**, if build includes discovery) | While managed Host exports are up | None; **private client IPs only** | JSON: hostname, version, export URLs/labels — **not** disk contents |

### Isolation recommendations (Host)

1. Bind to a **specific private IP** (Thunderbolt fabric IP first when present).  
2. Prefer a **dedicated link or VLAN** between trusted machines.  
3. Do **not** forward NBD ports through a consumer router to the Internet.  
4. **Stop** the export as soon as the peer finishes (Pull, attach, or lab work).  
5. Leave **Destructive mode = No** and **Allow bind 0.0.0.0 = No** for daily use.  
6. Prefer **unassigned, unmounted** disks as Host sources.

Step-by-step: [docs/hosting-safety.md](docs/hosting-safety.md).

---

## Discovery (Scan / beacon) — builds that include it

| Piece | Behavior |
|-------|----------|
| **Scan** | Authenticated WebUI only (Pull tab). Probes **private** IPv4 subnets for NBD ports and peer beacons. |
| **Beacon** | Lightweight HTTP while Host exports exist. Rejects non-private clients (`403`). |
| **Cloud** | None |
| **Token** | Not required for v1 lab use; optional hardening later |

Scan does not open Host. Beacon does not stream disk data. Still: on an untrusted LAN, stop Host (and thus the beacon) when idle.

---

## Pull (imaging client)

| Behavior | Safety note |
|----------|-------------|
| `qemu-img convert` from `nbd://…` | User-chosen URL — only pull from hosts you trust |
| Output under `/mnt/…` | Never writes block devices under `/dev/` |
| Does not need Destructive mode | Pull is file-oriented |

A malicious or mistaken `nbd://` can fill a share or pull unexpected content — same class of risk as downloading a large file from a URL you typed.

---

## Uninstall

| Action | Result |
|--------|--------|
| Stop managed Host / Pull jobs | Yes — via plugin pid/state under `/var/run/nbdexport` |
| Global `pkill qemu-nbd` | **No** — does not kill unrelated qemu-nbd |
| Remove emhttp plugin tree | Yes |
| Remove flash plugin config | Yes (export config first if you want settings later) |
| Delete user qcow2/raw under `/mnt/` | **No** |
| Touch Thunderbolt Net / Fabric Routing / `network.cfg` | **No** |

---

## Install / update supply chain

| Track | URL |
|-------|-----|
| **CA / production (`stable`)** | `https://raw.githubusercontent.com/ibigsnet/NBDExport/stable/nbd.plg` |
| **Lab / development (`main`)** | `https://raw.githubusercontent.com/ibigsnet/NBDExport/main/nbd.plg` |

- All plugin `FILE` sources are plain text from the same GitHub branch as PluginURL.  
- No third-party binary blob in the `.plg` beyond what Unraid already provides (`qemu-*` from the OS/VM stack).  
- Version strings are calendar `YYYY.MM.DD` + optional two-letter suffix (Unraid `strcmp` updates).

---

## Operational “good smell” for hosted disks

When **you** are the Host operator:

1. **RO** unless you have a written reason for RW.  
2. **Private bind**, never “all interfaces” on a mixed LAN.  
3. **Stop** after the job; do not leave Host up “for convenience.”  
4. Turn **Destructive** back **Off** after special jobs.  
5. Assume anyone who can TCP-connect can **read** (RO) or **destroy** (RW) that device.

Full checklist: [docs/hosting-safety.md](docs/hosting-safety.md).

---

## Contact

- **Support (CA / Plugins page):** https://forums.unraid.net/topic/200219-plugin-nbd-export-host-disks-over-network-block-device-image-to-qcow2raw/  
- **GitHub issues:** https://github.com/ibigsnet/NBDExport/issues  
- **Project:** https://github.com/ibigsnet/NBDExport  
