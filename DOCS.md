# NBD Export — Documentation

**Network Block Device** export and imaging for Unraid. Export a disk or partition over TCP with `qemu-nbd`, or pull a remote `nbd://` target into **qcow2** / raw with `qemu-img convert`.

**Install (recommended):** Apps (Community Applications) → search **NBD Export** → Install.

**Manual install:** Plugins → Install Plugin →  
`https://raw.githubusercontent.com/ibigsnet/NbdExport/main/nbdexport.plg`

**Support:** [GitHub Issues](https://github.com/ibigsnet/NbdExport/issues)  
**Source / project:** [github.com/ibigsnet/NbdExport](https://github.com/ibigsnet/NbdExport)  
**CA templates:** [ibigsnet/unraid-templates](https://github.com/ibigsnet/unraid-templates)

`README.md` is only the short Unraid Plugins-list blurb. This file is the full guide.

**UI location:** **Settings → Network Services → NBD** (same menu family as NFS and SMB).

**Related plugins (optional):**

- [Thunderbolt Net](https://github.com/ibigsnet/ThunderboltNet) — fast host-to-host underlay; prefer binding NBD to a Thunderbolt IP  
- [UnraidFRR](https://github.com/ibigsnet/UnraidFRR) — routing only; NBD follows whatever L3 path you already have  

Neither is required. This plugin does **not** PHP-require them.

---

## What NBD is (and is not)

| | |
|--|--|
| **NBD** | **Network Block Device** — forwards **block I/O** (like a hard disk) over TCP |
| **Not NFS / SMB** | Those share **files and folders** |
| **Not iSCSI** | Similar idea (remote block); different stack; this plugin uses **qemu-nbd** |

**Remember:** NFS/SMB share files. NBD shares a **disk** (or partition). The peer can seek, image, or convert it as if a drive were attached over the network.

That is why NBD is better for **whole-disk imaging**, **sparse qcow2 capture**, and other **disk-shaped** jobs. For movies, documents, and app data, use SMB or NFS.

Deep scenarios: [docs/when-to-use-nbd.md](docs/when-to-use-nbd.md) · Decision table: [docs/nbd-vs-nfs-smb.md](docs/nbd-vs-nfs-smb.md).

---

## What it does

| Area | Behavior |
|------|----------|
| **Export** | Start/stop `qemu-nbd` on a chosen device, bind IP, and port (default **read-only**) |
| **Bind picker** | Lists host IPv4 addresses; **Thunderbolt** interfaces first |
| **Image job** | Background `qemu-img convert` from `nbd://host:port` to a path under `/mnt/` |
| **Status** | Active exports, job list, tool detection (`qemu-nbd` / `qemu-img`) |
| **Safety** | No default `0.0.0.0`; array/mounted disks flagged; Enable=No stops exports |

### Product defaults

| Setting | Default | Why |
|---------|---------|-----|
| Enable | **Yes** | Ready after install |
| Read-only | **Yes** | Imaging must not scribble on source disks |
| Default port | **10809** | Common qemu-nbd default |
| Allow bind 0.0.0.0 | **No** | Basic NBD has no auth |
| Rehydrate on array start | **No** | Do not auto-export disks after reboot |

### What it does *not* do

| Not in scope (v1) | Notes |
|-------------------|--------|
| Replace SMB/NFS for file sharing | Use Network Services → SMB / NFS |
| Multi-writer shared SAN / always-on VM datastore | Dangerous without fencing |
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
2. Paste:  
   `https://raw.githubusercontent.com/ibigsnet/NbdExport/main/nbdexport.plg`  
3. Hard-refresh → **Network Services → NBD**.

Requires **Unraid product 6.12+** (plugin `min=` / CA MinVer — not a Linux kernel version).  
Also needs **`qemu-nbd` and `qemu-img`** on the running system (normally present when the Unraid **VM** features/tools are available). Check the Status table on the NBD page; missing tools are a package/VM-stack issue, not “upgrade Unraid for kernel 7.x.”

---

## Quick start

**“Start NBD listener”** = run `qemu-nbd` (server) on an IP:port for one disk.  
**“Start image job”** = run `qemu-img convert` (client) from `nbd://…` into a file.  
**Apply** only saves settings — it does not start a listener.

### Offer a disk from this Unraid (listener / server)

1. Private IP up (Thunderbolt Net recommended for multi-TB jobs).  
2. **Network Services → NBD** → device → listen IP → port → **Read-only Yes** → **Start NBD listener**.  
3. Peer: `qemu-img info nbd://<bind-ip>:<port>` then convert, **or** use Image job on another Unraid.  
4. When finished: **Stop listener**.

### Pull a remote disk into a qcow2 on this Unraid (client)

1. Peer starts RO `qemu-nbd` (or **Start NBD listener** on another Unraid).  
2. Here: **Image job** → `nbd://ip:port` → `/mnt/cache/…/name.qcow2` → **Start image job (pull to file)**.  
3. Job runs in the background; when **Done**, stop the peer’s listener.

Full scenarios: [docs/how-to-use.md](docs/how-to-use.md) · CLI: [docs/imaging-workflow.md](docs/imaging-workflow.md).

---

## Security

NBD is effectively **raw disk over TCP**.

- Prefer **read-only**  
- Bind to a **specific private IP** (Thunderbolt or LAN) — not the Internet  
- Do not export array/parity disks without understanding the risk  
- Isolation is the access control model for basic NBD  

Details: [docs/security-and-bind.md](docs/security-and-bind.md).

---

## Uninstall (clean removal)

1. **Plugins** → NBD Export → **Remove**.  
2. The remove script **stops exports**, clears run state, and deletes emhttp + flash config.  
3. Does **not** touch `network.cfg`, Thunderbolt Net, UnraidFRR, or your qcow2 files under `/mnt/`.

---

## Documentation index

| Doc | Topic |
|-----|--------|
| [docs/when-to-use-nbd.md](docs/when-to-use-nbd.md) | Scenarios: imaging, fast links, AI peers, multi-seek heritage |
| [docs/nbd-vs-nfs-smb.md](docs/nbd-vs-nfs-smb.md) | Files vs disks decision table |
| [docs/security-and-bind.md](docs/security-and-bind.md) | RO, bind IP, warnings |
| [docs/imaging-workflow.md](docs/imaging-workflow.md) | Export + convert + restore |
| [docs/integration-thunderboltnet.md](docs/integration-thunderboltnet.md) | TB underlay + listening vs NBD |
| [docs/integration-unraidfrr.md](docs/integration-unraidfrr.md) | Multi-hop (optional) |
| [docs/settings-reference.md](docs/settings-reference.md) | Every control |
| [docs/troubleshooting.md](docs/troubleshooting.md) | Common failures |

---

## Versioning

Unraid plugin updates use **lexicographic** `strcmp`: `YYYY.MM.DD`, then `aa`, `ab`, … same day. **No hyphens.** Same rules as Storage Guard, Thunderbolt Net, and UnraidFRR.
