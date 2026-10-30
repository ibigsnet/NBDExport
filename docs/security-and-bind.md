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
- [Spot-check RO vs RW (optional)](#spot-check-ro-vs-rw-optional)
- [Discovery / Scan](#discovery--scan)
- [What the plugin never does](#what-the-plugin-never-does)

## Destructive mode (like Unassigned Devices)

**Full list of when to turn it on:** [destructive-mode.md](destructive-mode.md)

| Destructive mode | What Host may do |
|------------------|------------------|
| **No** (default) | **Read-only** host of disks (and partitions) that are **not** Unraid array/parity/cache/pool inventory or a partition of such a disk, **not** mounted, **not** held by dm/md/bcache, **not** the boot device (`/boot`) |
| **Yes** | Only if you need **writable** host, or to host **array/cache/pool**, **mounted**, or **boot** media (Host tab still confirms the device) |

You need Destructive mode **only** for those Host cases. Everyday **read-only** NBD host of an unassigned, unmounted disk does **not** need it.

Server-side enforcement refuses blocked combinations even if the UI is bypassed.  
Pull jobs **never** write to `/dev/…` block devices: the output must be a regular file (or absent) in an existing folder on a mounted disk/pool under `/mnt/`; symlinks and the RAM root filesystem are refused.

When Destructive mode is ON, every NBD tab shows an **orange banner**. You may turn it **back to No** after starting a special Host — that only blocks *new* elevated Hosts; **live writable exports keep listening** until you Stop them (or use **Stop all writable hosts**). See [destructive-mode.md](destructive-mode.md).

## Rules

| Rule | Default in this plugin |
|------|-------------------------|
| Read-only host | **Yes** (`qemu-nbd --read-only`) |
| Destructive mode | **No** |
| Bind address | One of **this server's own** IPs — **Thunderbolt first** when present |
| Bind all interfaces (`0.0.0.0`, `::`, `::ffff:0.0.0.0`) | **Disabled** unless Allow bind 0.0.0.0 = Yes (+ confirm) |
| Public (non-private) bind IP | **Disabled** unless Allow public bind IPs = Yes (+ confirm); shown greyed out with a warning. RFC1918, link-local and the shared range `100.64.0.0/10` (carrier-grade NAT, Tailscale) are not public |
| Bind value | Must be an IP listed on an interface of this server (not a hostname, not another host's IP) |
| Array / mounted / flash | Blocked unless Destructive mode = Yes (+ UI confirm) |
| Writable host | Blocked unless Destructive mode = Yes (+ double UI confirm); one bind IP, one client (`--shared=1`), and the device may not be hosted elsewhere at the same time |
| Writable **boot** device | **Always refused** (even with Destructive On) |
| Pull output to `/dev/…`, a symlink, or RAM | **Always refused** |
| Pull output that already exists | Refused unless Overwrite = Yes; replaced only when the new Pull succeeds |
| Authentication | **None** in basic qemu-nbd — rely on network isolation |
| Auto re-export on array start / reboot | **Never** — Hosts are started by hand only |

## Bind address (the real access control)

Basic **qemu-nbd has no password**. Whoever can open a TCP connection to the bind IP and port can speak NBD.

| Bind choice | Meaning |
|-------------|---------|
| Thunderbolt / dedicated copper IP | Best — small, trusted fabric |
| Specific private LAN IP | Good if that VLAN/LAN is trusted |
| Tailscale / `100.64.0.0/10` IP | Reachable by every device on that tailnet or carrier NAT — treat like a LAN |
| **Multiple checkboxes** | Host the same disk on several networks (one `qemu-nbd` per IP, same port) — only check networks that should reach this disk |
| Wi‑Fi IP on a mixed home LAN | Higher exposure — prefer short Host windows |
| `0.0.0.0` / `::` (all interfaces) | **Dangerous** on multi-homed hosts — disabled by default |
| Public IP of this server | **Internet-reachable** unless a firewall blocks it — disabled by default |

The Host tab lists candidate IPs as **checkboxes** (Thunderbolt private first). Check only the networks where clients should connect; each selected IP gets its own listener (`nbd://that-ip:port`).

## Recommended isolation

1. **Thunderbolt host-net** or a **dedicated VLAN / copper** between trusted machines  
2. Firewall that does **not** expose the NBD port (default **10809**) or beacon (**10808**) to the Internet or guest Wi‑Fi  
3. **Stop** the host as soon as the pull or attach job finishes  
4. Prefer hosting disks that are **not** Unraid array/cache/pool members  
5. Keep **Allow bind 0.0.0.0 = No** unless you are on a fully controlled lab segment and understand the blast radius  

## What “read-only” protects

The peer cannot write through NBD. The Host runs `qemu-nbd --read-only`. Clients that try to write should fail; the exact **message depends on the client**.

### What clients typically report (RO Host)

Verified with **qemu-img** / **qemu-io** against a plugin Host (Read-only **Yes**), private bind, e.g. `nbd://HOST:10810`:

| Client action | Typical result |
|---------------|----------------|
| `qemu-img info nbd://…` | **Succeeds** — size/format visible |
| `qemu-io -r -f raw nbd://… -c 'read …'` | **Succeeds** — reads allowed |
| `qemu-io -r … -c 'write …'` | **Fails** — e.g. `Block node is read-only` |
| `qemu-io` **without** `-r` (open for write) | **Fails at open** — e.g. `Could not open image: Permission denied` |
| `qemu-img convert` **from** RO `nbd://` (Pull / imaging) | **Succeeds** — convert only **reads** the source |

So RO is not “invisible” — it is **readable, not writable**. Open-for-write and write I/O are rejected; the string may be **Permission denied**, **read-only**, or an I/O error depending on tool and flags.

### What read-only does *not* do

- Encrypt traffic on the wire  
- Hide the disk from anyone who can connect (they can still **read** every sector)  
- Freeze local writers on the source host — unmount or quiesce when you need a consistent image  
- Authenticate the peer — isolation is still bind IP + network path  

## Writable host

Allowed only if Read-only = **No** and Destructive mode = **Yes**, with explicit confirmations.

On a **writable** Host, clients that open `nbd://…` for write can **read and write** (e.g. `qemu-io` `write` succeeds). Prefer RO unless the peer must modify the disk in place.

**Red in the UI means elevated risk** — same mental model as Unassigned Devices destructive actions. Prefer RO.

### Spot-check RO vs RW (optional)

From a second machine that can reach the bind IP:

```bash
# Read-only export (expect info + read OK; write fails)
qemu-img info nbd://HOST:RO_PORT
qemu-io -r -f raw nbd://HOST:RO_PORT -c 'read -v 0 16'
qemu-io -f raw nbd://HOST:RO_PORT -c 'write -P 0xaa 0 512'
# → open or write error (Permission denied / Block node is read-only)

# Writable export (expect write OK — only on a disk you accept changing)
qemu-img info nbd://HOST:RW_PORT
qemu-io -f raw nbd://HOST:RW_PORT -c 'write -P 0x00 OFFSET 512'
```

Use a **safe offset** (or a disposable disk) for any real write test. See [troubleshooting.md](troubleshooting.md#ro-host-still-allows-writes).

## Discovery / Scan

On builds that include network discovery:

| Piece | Security note |
|-------|----------------|
| **Scan** | Button on Pull; POST + csrf; only the private LAN(s) you tick; default is beacons on 10808 |
| **Beacon** | Metadata only (hostname, version, export list); listens only on private Host bind IPs (never `0.0.0.0`); **private clients only**, Docker bridge subnets refused; no disk contents |
| **Peer replies** | Untrusted: URLs rebuilt from the scanned IP + integer port, max 16 exports and 64 KB per peer, text capped and escaped |
| **While idle** | No beacon if no managed Host export is up |

See [discovery.md](discovery.md). Discovery never replaces bind hygiene for the NBD data port.

## What the plugin never does

| | |
|--|--|
| Telemetry / phone-home | No |
| Edit `network.cfg` / br0 management | No |
| Reformat disks or change array membership | No |
| Kill a process it did not start | No — only recorded PIDs, after checking `/proc/<pid>/cmdline` (no `pkill` patterns) |
| Patch Unraid's own WebUI files | No (the opt-in UD badges load from the plugin's own page hook) |
| Require payment / license server | No (optional donate links in CA template only) |
