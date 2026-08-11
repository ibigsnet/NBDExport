# Security and bind addresses

NBD is effectively **raw disk over TCP**. Treat it like temporarily plugging a drive into another machine — over the network.

## Destructive mode (like Unassigned Devices)

| Destructive mode | What Host may do |
|------------------|------------------|
| **No** (default) | **Read-only** host of disks that are **not** Unraid array/parity, **not** mounted, **not** the flash device |
| **Yes** | Unlocks (Host tab still confirms the device): **(1)** writable host — peer can write the Unraid disk you select; **(2)** host of in-use/critical disks — array/parity, mounted filesystems, or Unraid flash |

Server-side enforcement refuses blocked combinations even if the UI is bypassed.  
Pull jobs **never** write to `/dev/…` block devices (file under `/mnt/` only).

When Destructive mode is ON, every NBD tab shows an **orange banner**. Turn it **back to No** when you finish a special job.

## Rules

| Rule | Default in this plugin |
|------|-------------------------|
| Read-only host | **Yes** (`qemu-nbd --read-only`) |
| Destructive mode | **No** |
| Bind address | Specific host IP — **Thunderbolt first** when present |
| Bind `0.0.0.0` | **Disabled** unless Allow bind 0.0.0.0 = Yes |
| Array / mounted / flash | Blocked unless Destructive mode = Yes (+ UI confirm) |
| Writable host | Blocked unless Destructive mode = Yes (+ double UI confirm) |
| Pull output to `/dev/…` | **Always refused** |
| Authentication | **None** in basic qemu-nbd — rely on network isolation |

## Recommended isolation

1. **Thunderbolt host-net** or a **dedicated VLAN** between trusted machines  
2. Firewall that does not expose the NBD port to the Internet or guest Wi‑Fi  
3. **Stop** the host as soon as the pull finishes  
4. Prefer imaging disks that are **not** Unraid array members  

## What “read-only” protects

The peer cannot write through NBD. It does **not** freeze local writers on the source host — unmount or quiesce workloads on the host when you need a consistent image.

## Writable host

Allowed only if you select read-only **No** (and Destructive mode is Yes). Use only for lab experiments you fully control. A mistaken write can destroy a boot disk.
