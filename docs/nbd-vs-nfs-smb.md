# NBD vs NFS vs SMB

| Need | Prefer | Why |
|------|--------|-----|
| Share movies, documents, app data | **SMB / NFS** | File semantics, multi-user, Unraid shares |
| Hand out **`.iso` installers** or ordinary folders | **SMB / NFS** | Files, not whole disks |
| Bootable **whole-disk image** / VM disk capture | **NBD** + `qemu-img` | Seekable blocks → **qcow2**, **raw** (`.img`), or other convert targets; preserves GPT/partitions |
| Write a prepared image onto a new NVMe in a dock (one-slot laptop later) | **NBD** and/or local `qemu-img convert` | Disk-shaped move; see [when-to-use](when-to-use-nbd.md#image-formats-not-only-qcow2) |
| Copy a few large files over a fast link | **rsync / SMB** | Simpler; no raw disk exposure |
| Mount remote volume as a **block device** (filesystem tools, recovery) | **NBD** (read-only recommended) | Tools see a disk, not a folder tree |
| Always-on multi-writer shared storage for VMs | **Not v1 NBD** | Use proper SAN/cluster designs |
| Casual LAN file access from a laptop | **SMB** | Auth, discovery, OS integration |

**Formats:** NBD is not “qcow2-only.” Host exports **raw blocks**; Pull defaults to **qcow2** (sparse, VM-friendly) and also supports **raw**. Other `qemu-img` formats are CLI territory.

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
