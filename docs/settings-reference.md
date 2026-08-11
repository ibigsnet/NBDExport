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

## Start NBD listener (server)

Starts `qemu-nbd` so this host **listens** and offers one disk. Does not copy data until a client connects.

| Field | Notes |
|-------|--------|
| Device | From `lsblk`; array/mounted need Destructive mode |
| Listen on (bind IP) | Thunderbolt IPs listed first |
| Listen port | TCP port for the server |
| Read-only | Strongly recommended Yes |
| Label | Optional note |
| **Start NBD listener** | Start server process |
| **Stop listener** | Kill that process |

## Image job (client)

Pulls from an existing `nbd://` listener into a **file**. Does not start a listener.

| Field | Notes |
|-------|--------|
| NBD URL | `nbd://ip:port` of a running listener |
| Output path | File under `/mnt/` or `/tmp/` — never `/dev/…` |
| Format | qcow2 (default) or raw |
| **Start image job** | Background `qemu-img convert` |

## Runtime paths

| Path | Purpose |
|------|---------|
| `/var/run/nbdexport/` | pid + state JSON |
| `/var/log/nbdexport/` | export and job logs |
| `/boot/config/plugins/NbdExport/companion.json` | soft companion marker |
