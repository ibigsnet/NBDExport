# Security — NBD Export

Copyright (c) 2026 ibigs, LLC · Author: RifleJock · License: GPL-3.0-or-later

NBD is raw block I/O over TCP — treat Host like temporarily plugging a drive into another machine, over the network.

| | |
|--|--|
| **Bind / isolation** | [docs/security-and-bind.md](docs/security-and-bind.md) |
| **Destructive mode** | [docs/destructive-mode.md](docs/destructive-mode.md) |
| **Hosting checklist** | [docs/hosting-safety.md](docs/hosting-safety.md) |
| **Support** | [Unraid forum](https://forums.unraid.net/topic/200219-plugin-nbd-export-host-disks-over-network-block-device-image-to-qcow2raw/) |

---

## Safe until you opt in

| Expectation | Behavior |
|-------------|---------|
| Defaults | **Read-only** Host; **Destructive mode Off**; **no all-interfaces bind** (`0.0.0.0` / `::`) and **no public bind IP** unless allowed; **no auto re-export** on array start |
| Idle install | No Host listener until you start one; no telemetry |
| Network surface | Only what you start: `qemu-nbd` on one of this server's own IPs and a port; discovery beacon only while Hosts on a private IP are up |
| Scope | Root (Unraid plugin model). Starts `qemu-nbd` / `qemu-img`. Does **not** edit `network.cfg`, array membership, or reformat disks |
| Gates | Destructive / RW / array-mounted / boot rules for **new** Hosts; live exports keep running until Stop |
| Uninstall | Stops **managed** Pulls, then Hosts, then the beacon (recorded PIDs, process checked); leaves user images under `/mnt/` |

---

## Threat model

### What NBD is

- Peers see **sectors**, not “a share with permissions.”  
- Basic **qemu-nbd has no authentication / TLS** in this plugin.  
- **Access control = network path + bind IP + stop when done.**

### Trust boundary

| Trusted | Untrusted (do not Host toward these) |
|---------|--------------------------------------|
| Machines on an isolated Thunderbolt / copper fabric you control | The public Internet |
| A VLAN or private LAN segment dedicated to lab work | Guest / IoT / untrusted Wi‑Fi clients |
| Logged-in Unraid WebUI users (same as any root plugin) | Random LAN clients that can reach an all-interfaces or public bind |

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
| May start **`qemu-img convert`** | Pull jobs; output is a regular file in an existing folder on a mounted disk/pool under `/mnt/` (never `/dev/…`, a symlink, or RAM) |
| WebUI actions | Via Unraid `update.php` + plugin include (same auth as other Settings plugins), POST + CSRF token. GET endpoints (status polls, Dashboard tile, UD badges) only read state |
| State changes | One lock (`/var/run/nbdexport/state.lock`) around every Host/Pull transition; state files written via temp + rename |

### Explicit non-goals (blast radius)

| Does **not** | |
|--------------|--|
| Edit `/boot/config/network.cfg` | Management LAN stays Unraid’s |
| Change array / pool membership | No “add disk” / wipe |
| Reformat or partition disks | |
| Install unrelated packages at boot | Tools are Unraid VM stack (`qemu-nbd` / `qemu-img`) |
| Require Thunderbolt Net or Fabric Routing | Soft companions only |
| Phone home / analytics / ads | No external calls for “license check” or telemetry |
| Patch Unraid's own files | Never. Optional **opt-in** badges on Main → Unassigned Devices load from the plugin's own `NBDUDOverlay.page` (`Menu="Buttons"`, Unraid's page hook; default **Off**). Install removes the line older releases added to `HeadInlineJS.php` — [docs/integration-unassigned-devices.md](docs/integration-unassigned-devices.md) |

---

## Defaults (safe until the user opts in)

| Setting | Default | Why |
|---------|---------|-----|
| `enabled` | **yes** | UI available; does not start listeners by itself |
| `default_read_only` | **yes** | Host exports use `qemu-nbd --read-only` |
| `default_port` | **10809** | Common qemu-nbd default (not a privileged port) |
| `allow_bind_all` | **no** | Blocks all-interfaces binds (`0.0.0.0`, `::`, `::ffff:0.0.0.0`) until explicitly allowed |
| `allow_public_bind` | **no** | Blocks Host on a public (non-private) address of this server |
| `destructive_mode` | **no** | Blocks RW host and array/mounted/boot sources |
| `ud_status_overlay` | **no** | No DOM badges on Unassigned Devices until the user opts in |

Live settings: `/boot/config/plugins/NBDExport/NBDExport.cfg`.

---

## Host enforcement

UI confirmations are **not** the only gate — the server enforces the same rules.

| Rule | Enforced |
|------|----------|
| Bind must be an address of this server | Yes — the IP must be listed on one of its interfaces (no hostnames, no foreign IPs) |
| All-interfaces bind (`0.0.0.0`, `::`) | Only if `allow_bind_all=yes` (+ confirm) |
| Public bind IP | Only if `allow_public_bind=yes` (+ confirm) |
| Writable host | One bind IP and one client (`--shared=1`); the device may not be hosted anywhere else at the same time |
| Writable host | Requires `destructive_mode=yes` + confirm |
| Array / parity / cache / pool / `md*` (whole disk **or any partition of it**) | Requires Destructive even if read-only |
| Mounted filesystem on device or any child | Requires Destructive |
| Device claimed by another kernel device (dm, md, bcache) | Requires Destructive |
| Device that backs `/boot` | Requires Destructive for RO; **writable boot device always refused** |
| Risky or RW start without `confirm` | Refused (server) |
| Pull output under `/dev/…`, a symlink, a non-regular file, or on the RAM root filesystem | Always refused |
| Pull output that already exists | Refused unless Overwrite = Yes |

Destructive mode is modeled after **Unassigned Devices** destructive mode: rare, intentional, orange banner while On. Details: [docs/destructive-mode.md](docs/destructive-mode.md).

---

## Network surface while Host is running

| Listener | When | Auth | Payload / data |
|----------|------|------|----------------|
| **`qemu-nbd`** (default port **10809+**) | User started a Host export | **None** (protocol) | Raw disk blocks on that device |
| **Discovery beacon** (port **10808**) | While managed Host exports on a private IP are up; one listener per private bind IP, never `0.0.0.0` | None; **private client IPs only**, Docker bridge subnets refused | JSON: hostname, version, export URLs/labels — **not** disk contents |

### Isolation recommendations (Host)

1. Bind to a **specific private IP** (Thunderbolt fabric IP first when present). Public IPs are greyed out unless Settings allows them.  
2. Prefer a **dedicated link or VLAN** between trusted machines.  
3. Do **not** forward NBD ports through a consumer router to the Internet.  
4. **Stop** the export as soon as the peer finishes (Pull, attach, or lab work).  
5. Leave **Destructive mode = No**, **Allow bind 0.0.0.0 = No** and **Allow public bind IPs = No** for daily use.  
6. Prefer **unassigned, unmounted** disks as Host sources.

Step-by-step: [docs/hosting-safety.md](docs/hosting-safety.md).

---

## Discovery (Scan / beacon) — builds that include it

| Piece | Behavior |
|-------|----------|
| **Scan** | Pull tab **button** (not automatic). POST + csrf. You pick which private LAN(s). Default is beacons on **10808**; optional NBD ports. Peer replies are untrusted: the URL is rebuilt from the scanned IP and an integer port, at most 16 exports per peer and 64 KB per reply are read, size probes use `qemu-img --image-opts driver=raw,file.driver=nbd`, and text is length-capped and escaped. |
| **Beacon** | Lightweight HTTP while Host exports exist. Listens only on the private Host bind IP(s), never `0.0.0.0`. Rejects non-private clients and Docker bridge subnets (`403`). |
| **Cloud** | None |
| **Token** | Not required for basic LAN use; optional hardening later |

Scan does not open Host. Beacon does not stream disk data. Still: on an untrusted LAN, stop Host (and thus the beacon) when idle.

---

## Pull (imaging client)

| Behavior | Safety note |
|----------|-------------|
| `qemu-img convert` from `nbd://…` | User-chosen URL — only pull from hosts you trust. Source is always read as `raw` (no format probing) |
| Output under `/mnt/…` | Regular file in an existing folder on a mounted disk/pool; never `/dev/`, a symlink, or RAM; an existing file only with Overwrite = Yes |
| `OUT.partial` | The convert writes `name.partial` and renames it to `name` only on success; a failed or stopped Pull deletes the partial |
| Does not need Destructive mode | Pull is file-oriented |

A malicious or mistaken `nbd://` can fill a share or pull unexpected content — same class of risk as downloading a large file from a URL you typed.

---

## Uninstall

| Action | Result |
|--------|--------|
| Stop managed Pull jobs, then Hosts, then the beacon | Yes — recorded PIDs under `/var/run/nbdexport`, each checked against `/proc/<pid>/cmdline` before a signal |
| `pkill` by pattern | **No** — never kills a process it did not start (no `pkill qemu-nbd`, no `php -S` pattern) |
| Remove emhttp plugin tree (incl. legacy names) | Yes |
| Remove flash plugin config | Yes (export config first if you want settings later) |
| Delete user qcow2/raw under `/mnt/` | **No** |
| Touch Thunderbolt Net / Fabric Routing / `network.cfg` | **No** |

---

## Install channel

Production / Community Applications: GitHub branch **`main`**.  
WIP / possibly untested builds: branch **`testing`**.  
Uses Unraid’s existing `qemu-nbd` / `qemu-img` (no extra binary payload in the plugin).

---

## Operational checklist

When **you** are the Host operator:

1. **RO** unless you have a written reason for RW.  
2. **Private bind**, never “all interfaces” on a mixed LAN.  
3. **Stop** after the job; do not leave Host up “for convenience.”  
4. Turn **Destructive** back **Off** after special jobs.  
5. Assume anyone who can TCP-connect can **read** (RO) or **destroy** (RW) that device.

Full checklist: [docs/hosting-safety.md](docs/hosting-safety.md).

---

## Contact

- **Support:** https://forums.unraid.net/topic/200219-plugin-nbd-export-host-disks-over-network-block-device-image-to-qcow2raw/  

- **GitHub issues:** https://github.com/ibigsnet/NBDExport/issues  
- **Project:** https://github.com/ibigsnet/NBDExport  
