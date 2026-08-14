# Security and bind addresses

NBD is effectively **raw disk over TCP**. Treat Host like temporarily plugging a drive into another machine — over the network.

**CA / reviewer summary:** [../SECURITY.md](../SECURITY.md)  
**Every Host job:** [hosting-safety.md](hosting-safety.md)  
**Destructive mode when required:** [destructive-mode.md](destructive-mode.md)

## Contents

- [Destructive mode (like Unassigned Devices)](#destructive-mode-like-unassigned-devices)
- [Rules](#rules)
- [Bind address (the real access control)](#bind-address-the-real-access-control)
- [Recommended isolation](#recommended-isolation)
- [What “read-only” protects](#what-read-only-protects)
- [Writable host](#writable-host)
- [Discovery / Scan](#discovery--scan)
- [What the plugin never does](#what-the-plugin-never-does)

## Destructive mode (like Unassigned Devices)

**Full list of when to turn it on:** [destructive-mode.md](destructive-mode.md)

| Destructive mode | What Host may do |
|------------------|------------------|
| **No** (default) | **Read-only** host of disks that are **not** Unraid array/parity/cache/pool inventory, **not** mounted, **not** the boot device (`/boot`) |
| **Yes** | Only if you need **writable** host, or to host **array/cache/pool**, **mounted**, or **boot** media (Host tab still confirms the device) |

You need Destructive mode **only** for those Host cases. Everyday **read-only** NBD host of an unassigned, unmounted disk does **not** need it.

Server-side enforcement refuses blocked combinations even if the UI is bypassed.  
Pull jobs **never** write to `/dev/…` block devices (file under `/mnt/` only).

When Destructive mode is ON, every NBD tab shows an **orange banner**. You may turn it **back to No** after starting a special Host — that only blocks *new* elevated Hosts; **live writable exports keep listening** until you Stop them (or use **Stop all writable hosts**). See [destructive-mode.md](destructive-mode.md).

## Rules

| Rule | Default in this plugin |
|------|-------------------------|
| Read-only host | **Yes** (`qemu-nbd --read-only`) |
| Destructive mode | **No** |
| Bind address | Specific host IP — **Thunderbolt first** when present |
| Bind `0.0.0.0` | **Disabled** unless Allow bind 0.0.0.0 = Yes |
| Array / mounted / flash | Blocked unless Destructive mode = Yes (+ UI confirm) |
| Writable host | Blocked unless Destructive mode = Yes (+ double UI confirm) |
| Writable **boot** device | **Always refused** (even with Destructive On) |
| Pull output to `/dev/…` | **Always refused** |
| Authentication | **None** in basic qemu-nbd — rely on network isolation |
| Auto re-export on array start | **No** (`rehydrate_on_start` default off) |

## Bind address (the real access control)

Basic **qemu-nbd has no password**. Whoever can open a TCP connection to the bind IP and port can speak NBD.

| Bind choice | Meaning |
|-------------|---------|
| Thunderbolt / dedicated copper IP | Best — small, trusted fabric |
| Specific private LAN IP | Good if that VLAN/LAN is trusted |
| Wi‑Fi IP on a mixed home LAN | Higher exposure — prefer short Host windows |
| `0.0.0.0` (all interfaces) | **Dangerous** on multi-homed hosts — disabled by default |

The Host tab lists candidate IPs with Thunderbolt private addresses preferred.

## Recommended isolation

1. **Thunderbolt host-net** or a **dedicated VLAN / copper** between trusted machines  
2. Firewall that does **not** expose the NBD port (default **10809**) or beacon (**10808**) to the Internet or guest Wi‑Fi  
3. **Stop** the host as soon as the pull or attach job finishes  
4. Prefer hosting disks that are **not** Unraid array/cache/pool members  
5. Keep **Allow bind 0.0.0.0 = No** unless you are on a fully controlled lab segment and understand the blast radius  

## What “read-only” protects

The peer cannot write through NBD. It does **not**:

- Encrypt traffic on the wire  
- Hide the disk from anyone who can connect  
- Freeze local writers on the source host — unmount or quiesce when you need a consistent image  

## Writable host

Allowed only if Read-only = **No** and Destructive mode = **Yes**, with explicit confirmations.

Use only for lab experiments you fully control. A mistaken write can destroy a boot disk, VM image, or archive.

**Red in the UI means elevated risk** — same mental model as Unassigned Devices destructive actions. Prefer RO.

## Discovery / Scan

On builds that include network discovery:

| Piece | Security note |
|-------|----------------|
| **Scan** | Runs only from the **logged-in** WebUI; private subnets only |
| **Beacon** | Metadata only (hostname, version, export list); **private clients only**; no disk contents |
| **While idle** | No beacon if no managed Host export is up |

See [discovery.md](discovery.md). Discovery never replaces bind hygiene for the NBD data port.

## What the plugin never does

| | |
|--|--|
| Telemetry / phone-home | No |
| Edit `network.cfg` / br0 management | No |
| Reformat disks or change array membership | No |
| Kill unrelated `qemu-nbd` on uninstall | No — managed pid files only |
| Require payment / license server | No (optional donate links in CA template only) |
