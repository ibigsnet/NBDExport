# NBD Export — Documentation

**Network Block Device** export and imaging for Unraid. Host a disk or partition over TCP with `qemu-nbd`, or pull a remote `nbd://` target into **qcow2** / raw with `qemu-img convert`.

| | |
|--|--|
| **UI** | **Settings → Network Services → NBD** (tabs: Status · Host · Pull · Help · Settings) |
| **Install (CA)** | Apps → search **NBD Export** |
| **Install (raw)** | `https://raw.githubusercontent.com/ibigsnet/NBDExport/main/nbd.plg` |
| **Support** | [Unraid forum thread](https://forums.unraid.net/topic/200219-plugin-nbd-export-host-disks-over-network-block-device-image-to-qcow2raw/) · [GitHub Issues](https://github.com/ibigsnet/NBDExport/issues) |
| **Source** | [github.com/ibigsnet/NBDExport](https://github.com/ibigsnet/NBDExport) |
| **Support development** | [Patreon](https://www.patreon.com/cw/IBIGSNet) · [PayPal](https://www.paypal.com/paypalme/RifleJock) |

`README.md` is only the short Unraid Plugins-list blurb. This file is the full guide.

**Start here for buttons and walkthroughs:** [docs/how-to-use.md](docs/how-to-use.md)

**Optional companions**

- [Thunderbolt Net](https://github.com/ibigsnet/ThunderboltNet) — fast host-to-host underlay; prefer binding NBD to a Thunderbolt IP  
- [Fabric Routing (FRR)](https://github.com/ibigsnet/FabricRouting)  / Fabric Routing — multi-hop routing only; NBD uses ordinary TCP  

Neither is required. This plugin does **not** PHP-require them.

---

## Contents

- [What NBD is (and is not)](#what-nbd-is-and-is-not)
- [What it does](#what-it-does)
- [Install / update](#install-update)
- [Quick start](#quick-start)
- [Security](#security)
- [Config backup (export)](#config-backup-export)
- [Uninstall (clean removal)](#uninstall-clean-removal)
- [Documentation index](#documentation-index)
- [Versioning](#versioning)

## What NBD is (and is not)

| | |
|--|--|
| **NBD** | **Network Block Device** — block I/O (like a hard disk) over TCP |
| **Not NFS / SMB** | Those share **files and folders** |
| **Not iSCSI** | Similar idea (remote block); different stack; this plugin uses **qemu-nbd** |
| **Not PXE** | Firmware netboot is a separate stack; do not point PXE at a qcow2 over NBD |

NBD is for **whole-disk imaging** and other **disk-shaped** jobs: Host publishes raw blocks; store or move them as **qcow2**, **raw** (`.img`), or convert back to physical media — not qcow2-only. For movies, documents, installers (`.iso`), and app data, use SMB or NFS.

Deep scenarios: [docs/when-to-use-nbd.md](docs/when-to-use-nbd.md) · Decision table: [docs/nbd-vs-nfs-smb.md](docs/nbd-vs-nfs-smb.md).

---

## What it does

| Area | Behavior |
|------|----------|
| **Host** | Start/stop `qemu-nbd` on a device, bind IP, and port (default **read-only**) |
| **Bind picker** | Host IPv4 addresses; **Thunderbolt** first when present |
| **Pull** | Background `qemu-img convert` from `nbd://host:port` to a path under `/mnt/` |
| **Scan** | Pull tab — private LAN discovery of NBD ports + peer beacons ([discovery.md](docs/discovery.md)) |
| **Attach / Client** | Live use of `nbd://` (VM disk / nbd-client) — docs today ([client-attach.md](docs/client-attach.md)) |
| **UI chrome** | Every tab: live hosted disks + orange Destructive banner when ON |
| **Multi-disk host** | Yes — multiple listeners; each needs its own port |
| **Safety** | No default `0.0.0.0`; array/mounted/flash flagged; Enable=No stops hosts |
| **UD badges (opt-in)** | Optional small **NBD RO/RW** lettering on Main → Unassigned Devices — off by default |

### Product defaults

| Setting | Default | Why |
|---------|---------|-----|
| Enable | **Yes** | Ready after install |
| Read-only | **Yes** | Imaging must not scribble on source disks |
| Default port | **10809** | Common qemu-nbd default |
| Allow bind 0.0.0.0 | **No** | Basic NBD has no auth |
| Destructive mode | **No** | Only for writable host or array/cache/pool/mounted/boot — [destructive-mode.md](docs/destructive-mode.md) |

### What it does *not* do

| Not in scope | Notes |
|--------------|--------|
| Replace SMB/NFS for file sharing | Use Network Services → SMB / NFS |
| Multi-writer shared SAN / always-on VM datastore | Dangerous without fencing |
| PXE / iPXE netboot server | Different stack; use VMs or dedicated netboot tools |
| TLS / nbdkit filters | Possible later |
| Edit `network.cfg` or Thunderbolt Net listening | NBD binds its own socket |
| Require Thunderbolt or FRR | Soft integration only |

---

## Install / update

### Option A — Community Applications

1. **Apps** → search **NBD** or **NBD Export**.  
2. **Install** or **Update**.  
3. Hard-refresh (**Ctrl+Shift+R**).  
4. Open **Settings → Network Services → NBD**.

### Option B — raw `.plg` URL

1. **Plugins → Install Plugin**.  
2. Paste: `https://raw.githubusercontent.com/ibigsnet/NBDExport/main/nbd.plg`  
3. Hard-refresh → **Network Services → NBD**.

Requires **Unraid product 6.12+** (plugin `min=` / CA MinVer — not a Linux kernel version).  
Also needs **`qemu-nbd` and `qemu-img`** (normally present with Unraid **VM** tools). Check **Status** tab; missing tools are a package/VM-stack issue, not “upgrade Unraid for kernel 7.x.”

---

## Quick start

| Job | Tab | Meaning |
|-----|-----|---------|
| Publish a local disk | **Host** | `qemu-nbd` server on IP:port |
| Save a remote disk to a file | **Pull** | `qemu-img convert` from `nbd://…` |
| Save settings only | **Settings → Apply** | Does not start Host or Pull |

### Offer a disk from this Unraid (server)

1. Private IP up between peers. [Thunderbolt Net](https://github.com/ibigsnet/ThunderboltNet) recommended for multi-terabyte jobs (Thunderbolt 4-class host-net is often ~20 Gbit/s each way under Linux — still ~2× a 10G NIC one-way). Solid private Wi‑Fi is fine for smaller disks; see [when-to-use — link choice](docs/when-to-use-nbd.md#2-private-links-thunderbolt-10g-lan--and-when-wifi-is-fine).  
2. **Host** tab → device → listen IP (prefer private/Thunderbolt) → port → **Read-only Yes** → host.  
3. Peer: `qemu-img info nbd://<bind-ip>:<port>` then convert, **or** **Pull** on another Unraid.  
4. When finished: **Stop** on the hosted-disks table (top of any tab).

### Pull a remote disk into a qcow2 (client)

1. Peer hosts read-only `qemu-nbd` (or **Host** on another Unraid).  
2. **Pull** tab → `nbd://ip:port` → e.g. `/mnt/user/domains/name.qcow2` → start pull.  
3. Job runs in the background (see **Status**); when **Done**, stop the peer’s host.

Full scenarios: [docs/how-to-use.md](docs/how-to-use.md) (including **both Unraids**: Host on the box with the NVMe, Pull on the box with free space) · CLI: [docs/imaging-workflow.md](docs/imaging-workflow.md).

---

## Security

NBD is effectively **raw disk over TCP**. Basic qemu-nbd has **no password** — isolation is the access control.

| Rule of thumb | |
|---------------|--|
| Prefer **read-only** Host | Default On |
| Bind a **specific private IP** | Thunderbolt first when present — not the Internet |
| **Stop** when the job ends | Do not leave Host up “for later” |
| Everyday source | Unassigned, unmounted disk; Destructive **Off** |

| Doc | Audience |
|-----|----------|
| [SECURITY.md](SECURITY.md) | **CA review**, threat model, defaults, uninstall |
| [docs/hosting-safety.md](docs/hosting-safety.md) | Operator checklist every Host job |
| [docs/security-and-bind.md](docs/security-and-bind.md) | Bind IP, isolation, RO vs RW |
| [docs/destructive-mode.md](docs/destructive-mode.md) | When Destructive mode is required |

---

## Config backup (export)

Settings, last-used host/pull fields, and named presets live on flash under the plugin. **Uninstall deletes that tree.**

- **Settings** tab → **Export config**
  - **Download JSON…** — browser save anywhere  
  - **Save to flash (`/boot/config/`)** — outside the plugin dir (survives remove)  
- **Import** restores from a path under `/boot/config/` or `/mnt/`

Image files under `/mnt/` are never part of this export.

---

## Uninstall (clean removal)

1. Optional: **Export config** if you want settings/presets later.  
2. **Plugins** → NBD Export → **Remove**.  
3. Stops hosts, clears run state, deletes emhttp + flash plugin config.  
4. Does **not** touch `network.cfg`, Thunderbolt Net, Fabric Routing, qcow2 under `/mnt/`, or export JSON under `/boot/config/nbdexport-config-*.json`.

---

## Documentation index

| Doc | Topic |
|-----|--------|
| [docs/how-to-use.md](docs/how-to-use.md) | **Start here** — if you click…, tabs, walkthroughs |
| [docs/when-to-use-nbd.md](docs/when-to-use-nbd.md) | Why NBD vs SMB/NFS |
| [docs/nbd-vs-nfs-smb.md](docs/nbd-vs-nfs-smb.md) | Files vs disks decision table |
| [docs/destructive-mode.md](docs/destructive-mode.md) | **When** to enable Destructive mode |
| [docs/security-and-bind.md](docs/security-and-bind.md) | Bind IP, isolation, read-only |
| [docs/hosting-safety.md](docs/hosting-safety.md) | Host checklist (publish safely) |
| [docs/integration-unassigned-devices.md](docs/integration-unassigned-devices.md) | Opt-in UD status badges |
| [SECURITY.md](SECURITY.md) | Threat model + CA review notes |
| [docs/imaging-workflow.md](docs/imaging-workflow.md) | CLI golden path + restore |
| [docs/integration-thunderboltnet.md](docs/integration-thunderboltnet.md) | Thunderbolt underlay + listening vs NBD |
| [docs/integration-fabricrouting.md](docs/integration-fabricrouting.md) | Fabric Routing / multi-hop (optional) |
| [docs/settings-reference.md](docs/settings-reference.md) | Every control by tab |
| [docs/troubleshooting.md](docs/troubleshooting.md) | Common failures |

---

## Versioning

Unraid plugin updates use **lexicographic** `strcmp`: `YYYY.MM.DD`, then `aa`, `ab`, … same day. **No hyphens.** Same rules as Storage Guard, Thunderbolt Net, and Fabric Routing.
