# Imaging workflow

Golden path used by this plugin (`qemu-nbd` + `qemu-img convert`).

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

`qemu-img convert` from a **seekable** block source can skip zeros → **sparse qcow2**. Streaming the entire raw device transfers empty regions too and is harder to resume cleanly.

## Stop host

Always stop `qemu-nbd` (plugin **Stop** on the hosted-disks table, or kill) when finished. Client exit does **not** always stop the server.

## Restore later (destructive)

```bash
qemu-img convert -p -f qcow2 -O raw example.qcow2 /dev/nvmeXn1
```

Double-check the target device letter/number before running.

## qcow2 rationale

| Goal | Format |
|------|--------|
| Boot as Unraid VM later | **qcow2** under `/mnt/user/domains/` (or cache) |
| Restore to physical NVMe | convert to raw on the device |
| Sparse storage on cache/array | qcow2 from convert |

**Note:** Network **PXE** boot of a qcow2 is **not** supported by this plugin. Use Unraid **VMs** with the qcow2, or convert back to a physical disk.
