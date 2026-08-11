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

## New export

| Field | Notes |
|-------|--------|
| Device | From `lsblk`; array/mounted flagged |
| Bind IP | Thunderbolt IPs listed first |
| Port | TCP listen port |
| Read-only | Strongly recommended Yes |
| Label | Optional note |

## Image job

| Field | Notes |
|-------|--------|
| NBD URL | `nbd://ip:port` |
| Output path | Must start with `/mnt/` or `/tmp/` |
| Format | qcow2 (default) or raw |

## Runtime paths

| Path | Purpose |
|------|---------|
| `/var/run/nbdexport/` | pid + state JSON |
| `/var/log/nbdexport/` | export and job logs |
| `/boot/config/plugins/NbdExport/companion.json` | soft companion marker |
