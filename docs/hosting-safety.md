# Hosting safety checklist

Use this whenever **this Unraid** is the **Host** (publishing a disk/partition as `nbd://…`).  
Pull and Attach are separate: this page is about **offering** a device, not consuming one.

Deep background: [security-and-bind.md](security-and-bind.md) · [destructive-mode.md](destructive-mode.md) · [../SECURITY.md](../SECURITY.md)

---

## Before you click Host

| Check | Preferred answer |
|-------|------------------|
| Do I need **blocks** (not files)? | Yes — otherwise use SMB/NFS |
| Is the disk **unassigned** and **unmounted**? | Yes when possible |
| Is **Destructive mode** still **No**? | Yes for everyday RO unassigned host |
| Is **Read-only** **Yes**? | Yes unless you truly need remote writes |
| Bind IP | Specific **private** IP (Thunderbolt first if present) — not `0.0.0.0` |
| Who can reach that IP:port? | Only machines you trust for full disk read (or write if RW) |
| How long will Host stay up? | Only for the job — then **Stop** |

If any row is “no / unsure,” stop and read [destructive-mode.md](destructive-mode.md) and [security-and-bind.md](security-and-bind.md).

---

## Safe Host pattern (recommended)

1. Plug in or pick an **unassigned** disk you are willing to expose **read-only**.  
2. Confirm it is **not mounted** and **not** array/cache/pool/boot.  
3. Settings: Destructive **No**, Allow bind all **No**.  
4. **Host** tab: device → private/Thunderbolt IP → port (e.g. 10809) → **Read-only Yes** → start.  
5. Status table shows **Listening** (or briefly **Active** while the port settles).  
6. Peer Pulls or attaches; when finished, **Stop** on the hosted-disks table.  
7. Leave Destructive **No**.

This is the path Community Applications reviewers and cautious operators should assume is normal.

---

## High-risk Host patterns (avoid or isolate)

| Pattern | Risk | If you must |
|---------|------|-------------|
| **Writable** host | Peer can destroy the disk | Destructive **Yes** + double confirm; private link only; stop immediately after |
| **Array / cache / pool** member | Live Unraid storage under network readers/writers | Prefer clone/unassigned disk; Destructive only with understanding |
| **Mounted** filesystem | Local + remote consistency issues | Unmount first when possible |
| **Boot device** (`/boot`) | Can brick the server’s config drive | RO only with Destructive; **RW boot is refused** |
| Bind **`0.0.0.0`** | Any interface route may reach NBD | Keep allow_bind_all **No**; use one private IP |
| Host left up for days | Forgotten open disk | Stop when idle; optional discovery beacon only runs while exports are up |
| Internet / guest Wi‑Fi path | Untrusted readers (or writers) | Do not port-forward; use TB/VLAN/VPN as appropriate |

---

## While Host is running

- Treat the **Clients use** URL (`nbd://IP:port`) like a raw disk handle — share it only with the intended peer.  
- Orange **Destructive mode** banner means you opted into elevated risk — finish the job and turn it **Off**.  
- **Active** (blue) means the export process is already running; **Listening** (green) means the port is confirmed open.  
- Do not Host the same disk writable from two places; do not assume NBD multi-writer is safe.

---

## After the job

1. **Stop** all hosted exports (per disk or **Stop all**).  
2. Confirm the top-of-page table shows none hosted.  
3. **Destructive mode = No** → Apply (if you turned it On).  
4. Optional: remove temporary peer firewall rules / cable if that was a one-off lab link.

---

## Read-only vs “safe”

| | |
|--|--|
| **RO protects** | Remote cannot write through NBD |
| **RO does not protect** | Local processes still writing the same disk; network sniffing if the path is untrusted; full **read** of every sector by anyone who can connect |

For a **consistent image**, unmount or quiet the source before Host/Pull when you can.

---

## Related

- [how-to-use.md](how-to-use.md) — UI walkthrough  
- [discovery.md](discovery.md) — Scan / beacon (multi-Unraid)  
- [client-attach.md](client-attach.md) — live VM / nbd-client use of `nbd://`  
