# Security and bind addresses

NBD is effectively **raw disk over TCP**. Treat it like temporarily plugging a drive into another machine — over the network.

## Contents

- [Destructive mode (like Unassigned Devices)](#destructive-mode-like-unassigned-devices)
- [Rules](#rules)
- [Recommended isolation](#recommended-isolation)
- [What “read-only” protects](#what-read-only-protects)
- [Writable host](#writable-host)

## Destructive mode (like Unassigned Devices)

**Full list of when to turn it on:** [destructive-mode.md](destructive-mode.md)

| Destructive mode | What Host may do |
|------------------|------------------|
| **No** (default) | **Read-only** host of disks that are **not** Unraid array/parity/cache/pool inventory, **not** mounted, **not** the boot device (`/boot`) |
| **Yes** | Only if you need **writable** host, or to host **array/cache/pool**, **mounted**, or **boot** media (Host tab still confirms the device) |

You need Destructive mode **only** for those Host cases. Everyday imaging of an unassigned, unmounted disk does **not** need it.

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
4. Prefer imaging disks that are **not** Unraid array/cache/pool members  

## What “read-only” protects

The peer cannot write through NBD. It does **not** freeze local writers on the source host — unmount or quiesce workloads on the host when you need a consistent image.

## Writable host

Allowed only if you select read-only **No** (and Destructive mode is Yes). Use only for lab experiments you fully control. A mistaken write can destroy a boot disk.
