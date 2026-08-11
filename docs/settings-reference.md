# Settings reference

**Path:** Settings → Network Services → NBD  
**Config:** `/boot/config/plugins/NbdExport/NbdExport.cfg`

| Control | Default | Notes |
|---------|---------|--------|
| Enable NBD Export | Yes | No stops all exports on Apply |
| Default read-only | Yes | Used as default for new exports |
| Default port | 10809 | 1024–65535 |
| Allow bind 0.0.0.0 | No | Dangerous; basic NBD has no auth |
| Destructive mode | No | UD-style: allow writable and/or array/mounted/flash exports; UI confirm still required |
| Rehydrate on start | No | Reserved; v1 does not auto-export disks |
| Export config | — | Download JSON or write `/boot/config/nbdexport-config-*.json` (outside plugin dir). Uninstall wipes plugin flash state — export first if you care. |
| Import config | — | Path under `/boot/config/` or `/mnt/` only |

## Section 3 — Host a local Unraid disk (NBD server)

Publishes one local **block device** (whole disk or partition — raw blocks including partition table) on the network. Does not copy data until a client connects.

| Field | Notes |
|-------|--------|
| Device | From `lsblk`; array/mounted need Destructive mode |
| Listen on (bind IP) | Thunderbolt IPs listed first |
| Listen port | TCP port for the server |
| Read-only | Strongly recommended Yes |
| Label | Optional note |
| **Host this disk on the network** | Start `qemu-nbd` server |
| **Stop listener** | Kill that process |

## Section 4 — Pull a remote disk into a file (NBD client)

Connects to a disk already hosted on the network (other Unraid or any `qemu-nbd` host) and writes a **file** under Unraid storage. Does not publish a local disk.

| Field | Notes |
|-------|--------|
| NBD URL | `nbd://ip:port` of a running host |
| Output path | File under `/mnt/` or `/tmp/` — never `/dev/…` |
| Format | qcow2 (default) or raw |
| **Pull remote disk to file** | Background `qemu-img convert` |

## Runtime paths

| Path | Purpose |
|------|---------|
| `/var/run/nbdexport/` | pid + state JSON |
| `/var/log/nbdexport/` | export and job logs |
| `/boot/config/plugins/NbdExport/companion.json` | soft companion marker |
