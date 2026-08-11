# Imaging workflow

Golden path used by this plugin (`qemu-nbd` + `qemu-img convert`).

## Contents

- [Prerequisites](#prerequisites)
- [Host (server)](#host-server)
- [Pull (client / Unraid)](#pull-client-unraid)
- [Stop host](#stop-host)
- [Restore later (destructive)](#restore-later-destructive)
- [Formats (qcow2, raw / `.img`, and more)](#formats-qcow2-raw--img-and-more)

## Prerequisites

1. IP connectivity between host and client (Thunderbolt or Ethernet).  
2. Source disk not heavily written; unmount filesystems on the export host if mounted.  
3. Destination has free space ≥ expected qcow size (+ margin).  
4. `qemu-nbd` and `qemu-img` available on the relevant hosts.

## Host (server)

```bash
qemu-nbd --read-only --persistent --shared=2 \
  --bind=<PRIVATE_IP> --port=10809 --format=raw \
  /dev/nvme0n1
```

Or on Unraid: **Network Services → NBD → Host** tab → **Host disk/partition on network**.

Verify:

```bash
ss -lntp | grep 10809
qemu-img info nbd://<PRIVATE_IP>:10809
```

## Pull (client / Unraid)

```bash
qemu-img convert -p -f raw -O qcow2 -t writeback -W \
  nbd://<PRIVATE_IP>:10809 \
  /mnt/user/domains/example.qcow2
qemu-img check /mnt/user/domains/example.qcow2
```

Or on Unraid: **Pull** tab → NBD URL + output path under `/mnt/…` (background job; survives closing the browser). See **Status** for job list.

### Why convert beats a raw TCP pipe

`qemu-img convert` from a **seekable** block source can skip zeros when the output format supports sparseness (especially **qcow2**). Streaming the entire raw device transfers empty regions too and is harder to resume cleanly.

### Pull to raw (full `.img`-style file)

```bash
qemu-img convert -p -f raw -O raw -t writeback -W \
  nbd://<PRIVATE_IP>:10809 \
  /mnt/user/domains/example.img
```

Unraid **Pull** tab: choose format **raw** (same idea; path can end in `.img` or `.raw`).

### Other formats via CLI

The Pull UI supports **qcow2** and **raw**. On a machine with `qemu-img`, you can convert from the same NBD URL (or from a finished qcow2) to other outputs, for example:

```bash
qemu-img convert -p -f raw -O vmdk nbd://<PRIVATE_IP>:10809 /path/out.vmdk
```

**.iso** files are install/optical images — share them as **files** (SMB/NFS) or attach as a VM CD. They are not a substitute for a whole-disk NBD image of a boot drive.

## Stop host

Always stop `qemu-nbd` (plugin **Stop** on the hosted-disks table, or kill) when finished. Client exit does **not** always stop the server.

## Restore later (destructive)

Write an archive **onto a physical disk** (wrong device = data loss — verify with `lsblk` first):

```bash
# From qcow2 on Unraid / NAS storage:
qemu-img convert -p -f qcow2 -O raw example.qcow2 /dev/nvmeXn1

# From a raw/.img file:
qemu-img convert -p -f raw -O raw example.img /dev/nvmeXn1
```

Typical use: new larger NVMe in a **USB/Thunderbolt dock**, image prepared on Unraid, one convert, then install the NVMe into a **one-slot** laptop/tablet. See [when-to-use — one NVMe slot](when-to-use-nbd.md#one-nvme-slot--prepare-a-larger-drive-before-you-open-the-chassis).

## Formats (qcow2, raw / `.img`, and more)

| Goal | Format |
|------|--------|
| Everyday archive + Unraid **VM** disk | **qcow2** under `/mnt/user/domains/` (or similar) |
| Bit-identical file, simple tooling | **raw** (filename often `.img`) |
| Restore to physical NVMe/SATA | `qemu-img convert … -O raw /dev/…` |
| Sparse storage on cache/array | Prefer **qcow2** from convert |
| Foreign hypervisors | CLI convert to vmdk/vdi/etc. from qcow2/raw or live `nbd://` |

**NBD carries blocks.** qcow2/raw/img are **storage and interchange** choices after (or before) that.

**Note:** Network **PXE** boot of a disk image over NBD is **not** supported by this plugin. Use Unraid **VMs** with the image, or convert back to a physical disk.
