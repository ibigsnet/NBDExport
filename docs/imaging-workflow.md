# Imaging workflow

Golden path used by this plugin (`qemu-nbd` + `qemu-img convert`).

## Prerequisites

1. IP connectivity between export host and Unraid (Thunderbolt or Ethernet).  
2. Source disk not heavily written; unmount filesystems on the export host if mounted.  
3. Destination has free space ≥ expected qcow size (+ margin).  
4. `qemu-nbd` and `qemu-img` available on the relevant hosts.

## Export (server)

```bash
qemu-nbd --read-only --persistent --shared=2 \
  --bind=<PRIVATE_IP> --port=10809 --format=raw \
  /dev/nvme0n1
```

Or use **Network Services → NBD → Start export** on Unraid when the disk is local.

Verify:

```bash
ss -lntp | grep 10809
qemu-img info nbd://<PRIVATE_IP>:10809
```

## Image (client / Unraid)

```bash
qemu-img convert -p -f raw -O qcow2 -t writeback -W \
  nbd://<PRIVATE_IP>:10809 \
  /mnt/cache/images/example.qcow2
qemu-img check /mnt/cache/images/example.qcow2
```

Or use **Image job** on the NBD page (background; survives closing the browser).

### Why convert beats a raw TCP pipe

`qemu-img convert` from a **seekable** block source can skip zeros → **sparse qcow2**. Streaming the entire raw device transfers empty regions too and is harder to resume cleanly.

## Stop export

Always stop `qemu-nbd` (plugin **Stop**, or kill/systemctl) when finished. Client exit does **not** always stop the server.

## Restore later (destructive)

```bash
qemu-img convert -p -f qcow2 -O raw example.qcow2 /dev/nvmeXn1
```

Double-check the target device letter/number before running.

## qcow2 rationale

| Goal | Format |
|------|--------|
| Boot as Unraid VM later | **qcow2** |
| Restore to physical NVMe | convert to raw on the device |
| Sparse storage on cache/array | qcow2 from convert |
