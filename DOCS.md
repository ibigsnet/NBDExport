# NBD Export — Documentation

**Network Block Device** export and imaging for Unraid. Host a disk or partition over TCP with `qemu-nbd`, or pull a remote `nbd://` target into **qcow2** / raw with `qemu-img convert`.

| | |
|--|--|
| **UI** | **Settings → Network Services → NBD** (tabs: Status · Host · Pull · Settings) |
| **Install (CA)** | Apps → search **NBD Export** |
| **Install (raw)** | `https://raw.githubusercontent.com/ibigsnet/NbdExport/main/nbdexport.plg` |
| **Support** | [GitHub Issues](https://github.com/ibigsnet/NbdExport/issues) |
| **Source** | [github.com/ibigsnet/NbdExport](https://github.com/ibigsnet/NbdExport) |

`README.md` is only the short Unraid Plugins-list blurb. This file is the full guide.

**Start here for buttons and walkthroughs:** [docs/how-to-use.md](docs/how-to-use.md)

**Optional companions**

- [Thunderbolt Net](https://github.com/ibigsnet/ThunderboltNet) — fast host-to-host underlay; prefer binding NBD to a Thunderbolt IP  
- [Fabric Routing (FRR)](https://github.com/ibigsnet/UnraidFRR) / UnraidFRR — multi-hop routing only; NBD uses ordinary TCP  

Neither is required. This plugin does **not** PHP-require them.

---

## What NBD is (and is not)

| | |
|--|--|
| **NBD** | **Network Block Device** — block I/O (like a hard disk) over TCP |
| **Not NFS / SMB** | Those share **files and folders** |
| **Not iSCSI** | Similar idea (remote block); different stack; this plugin uses **qemu-nbd** |
| **Not PXE** | Firmware netboot is a separate stack; do not point PXE at a qcow2 over NBD |

NBD is for **whole-disk imaging**, **sparse qcow2 capture**, and other **disk-shaped** jobs. For movies, documents, and app data, use SMB or NFS.

Deep scenarios: [docs/when-to-use-nbd.md](docs/when-to-use-nbd.md) · Decision table: [docs/nbd-vs-nfs-smb.md](docs/nbd-vs-nfs-smb.md).

---

## What it does

| Area | Behavior |
|------|----------|
| **Host** | Start/stop `qemu-nbd` on a device, bind IP, and port (default **read-only**) |
| **Bind picker** | Host IPv4 addresses; **Thunderbolt** first when present |
| **Pull** | Background `qemu-img convert` from `nbd://host:port` to a path under `/mnt/` |
| **UI chrome** | Every tab: live hosted disks + orange Destructive banner when ON |
| **Multi-disk host** | Yes — multiple listeners; each needs its own port |
| **Safety** | No default `0.0.0.0`; array/mounted/flash flagged; Enable=No stops hosts |

### Product defaults

| Setting | Default | Why |
|---------|---------|-----|
| Enable | **Yes** | Ready after install |
| Read-only | **Yes** | Imaging must not scribble on source disks |
| Default port | **10809** | Common qemu-nbd default |
| Allow bind 0.0.0.0 | **No** | Basic NBD has no auth |
| Destructive mode | **No** | See [security-and-bind.md](docs/security-and-bind.md) |

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
2. Paste: `https://raw.githubusercontent.com/ibigsnet/NbdExport/main/nbdexport.plg`  
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

1. Private IP up (Thunderbolt Net recommended for multi-TB jobs).  
2. **Host** tab → device → listen IP → port → **Read-only Yes** → host.  
3. Peer: `qemu-img info nbd://<bind-ip>:<port>` then convert, **or** **Pull** on another Unraid.  
4. When finished: **Stop** on the hosted-disks table (top of any tab).

### Pull a remote disk into a qcow2 (client)

1. Peer hosts RO `qemu-nbd` (or **Host** on another Unraid).  
2. **Pull** tab → `nbd://ip:port` → e.g. `/mnt/user/domains/name.qcow2` → start pull.  
3. Job runs in the background (see **Status**); when **Done**, stop the peer’s host.

Full scenarios: [docs/how-to-use.md](docs/how-to-use.md) (including **both Unraids**: Host on the box with the NVMe, Pull on the box with free space) · CLI: [docs/imaging-workflow.md](docs/imaging-workflow.md).

---

## Security

NBD is effectively **raw disk over TCP**.

- Prefer **read-only**  
- Bind to a **specific private IP** (Thunderbolt or LAN) — not the Internet  
- Do not host array/parity disks without understanding the risk  
- Isolation is the access control model for basic qemu-nbd  

Details: [docs/security-and-bind.md](docs/security-and-bind.md).

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
4. Does **not** touch `network.cfg`, Thunderbolt Net, UnraidFRR, qcow2 under `/mnt/`, or export JSON under `/boot/config/nbdexport-config-*.json`.

---

## Documentation index

| Doc | Topic |
|-----|--------|
| [docs/how-to-use.md](docs/how-to-use.md) | **Start here** — if you click…, tabs, walkthroughs |
| [docs/when-to-use-nbd.md](docs/when-to-use-nbd.md) | Why NBD vs SMB/NFS |
| [docs/nbd-vs-nfs-smb.md](docs/nbd-vs-nfs-smb.md) | Files vs disks decision table |
| [docs/security-and-bind.md](docs/security-and-bind.md) | Destructive mode, RO, bind IP |
| [docs/imaging-workflow.md](docs/imaging-workflow.md) | CLI golden path + restore |
| [docs/integration-thunderboltnet.md](docs/integration-thunderboltnet.md) | TB underlay + listening vs NBD |
| [docs/integration-unraidfrr.md](docs/integration-unraidfrr.md) | Fabric Routing / multi-hop (optional) |
| [docs/settings-reference.md](docs/settings-reference.md) | Every control by tab |
| [docs/troubleshooting.md](docs/troubleshooting.md) | Common failures |

---

## Versioning

Unraid plugin updates use **lexicographic** `strcmp`: `YYYY.MM.DD`, then `aa`, `ab`, … same day. **No hyphens.** Same rules as Storage Guard, Thunderbolt Net, and UnraidFRR.
