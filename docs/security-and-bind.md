# Security and bind addresses

NBD is effectively **raw disk over TCP**. Treat it like temporarily plugging a drive into another machine — over the network.

## Destructive mode (like Unassigned Devices)

| Destructive mode | Allowed |
|------------------|---------|
| **No** (default) | **Read-only** exports of disks that are **not** Unraid array/parity members, **not** mounted, **not** the flash device |
| **Yes** | Writable exports; array / mounted / flash device exports (still prompts in the browser) |

Server-side enforcement refuses blocked combinations even if the UI is bypassed.  
Image jobs **never** write to `/dev/…` block devices (file under `/mnt/` only).

Turn Destructive mode **back to No** when you finish a special job.

## Rules

| Rule | Default in this plugin |
|------|-------------------------|
| Read-only export | **Yes** (`qemu-nbd --read-only`) |
| Destructive mode | **No** |
| Bind address | Specific host IP — **Thunderbolt first** when present |
| Bind `0.0.0.0` | **Disabled** unless you set Allow bind 0.0.0.0 = Yes |
| Array / mounted disks | Blocked unless Destructive mode = Yes (+ UI confirm) |
| Writable export | Blocked unless Destructive mode = Yes (+ double UI confirm) |
| Image output to `/dev/…` | **Always refused** |
| Authentication | **None** in basic qemu-nbd — rely on network isolation |

## Recommended isolation

1. **Thunderbolt host-net** or a **dedicated VLAN** between trusted machines  
2. Firewall that does not expose the NBD port to the Internet or guest Wi‑Fi  
3. Stop the export as soon as the image job finishes  
4. Prefer imaging disks that are **not** Unraid array members  

## What “read-only” protects

The peer cannot write through NBD. It does **not** freeze local writers on the source host — unmount or quiesce workloads on the export host when you need a consistent image.

## Writable exports

Allowed only if you select read-only **No**. Use only for lab experiments you fully control. A mistaken write can destroy a boot disk.
