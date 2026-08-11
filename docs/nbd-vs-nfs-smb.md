# NBD vs NFS vs SMB

| Need | Prefer | Why |
|------|--------|-----|
| Share movies, documents, app data | **SMB / NFS** | File semantics, multi-user, Unraid shares |
| Bootable **whole-disk image** / VM disk capture | **NBD** + `qemu-img` | Seekable block source → sparse qcow2; preserves GPT/partitions |
| Copy a few large files over a fast link | **rsync / SMB** | Simpler; no raw disk exposure |
| Mount remote volume as a **block device** (filesystem tools, recovery) | **NBD** (read-only recommended) | Tools see a disk, not a folder tree |
| Always-on multi-writer shared storage for VMs | **Not v1 NBD** | Use proper SAN/cluster designs |
| Casual LAN file access from a laptop | **SMB** | Auth, discovery, OS integration |

## Unraid menu map

```text
Settings → Network Services
  NFS  — share directories (files)
  SMB  — share directories (files)
  NBD  — export a block device over TCP
```

## Thunderbolt Net “Unraid services on this link”

Listening **Yes** in Thunderbolt Net adds the Thunderbolt interface to `network-extra.cfg` so **SMB / NFS / SSH / web UI** can bind on that IP.

**NBD is separate:** `qemu-nbd --bind=IP` is its own process. Enable listening if you want file/web services on the Thunderbolt IP; use **Network Services → NBD** to export a disk on the same IP.
