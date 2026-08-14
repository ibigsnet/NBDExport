# When to enable Destructive mode

**Path:** Settings → Network Services → NBD → **Settings** → **Destructive mode**

Default is **No**. Leave it Off unless you intentionally need one of the four Host cases below.

Destructive mode only affects **Host** — publishing a local Unraid disk (or partition) as an **NBD** endpoint so a peer can use it as a **block device over the network**. **Pull** never needs Destructive mode.

While **On**, every NBD tab shows an **orange banner**. Set it back to **No** when finished.

---

## What Host is doing

**NBD** (Network Block Device) is a protocol: peers see **raw sectors**, not a folder tree. That is useful for more than saving a qcow2 — anything that needs a **disk** over the wire (tools that open a block device, remote partition work, seekable access, convert/archive jobs, lab attach, and similar).

Destructive mode does **not** change the protocol. It only unlocks Host of disks Unraid treats as **in use / critical**, or a **writable** export.

---

## Leave it Off (the usual case)

Host **read-only**, disk **not** in Unraid storage inventory, **not mounted**, **not** the Unraid boot device — Destructive mode stays **No**.

Typical safe source: an **unassigned** NVMe/SSD you plugged in so a peer can reach that disk over NBD.

---

## The only times to enable it

Turn Destructive mode **On** only for one of these four Host situations:

### 1. Writable host (Read-only = No)

- Peer can **write** blocks to the Unraid disk over NBD (not only read them)  
- Blocked unless Destructive mode is On  
- Prefer **read-only** Host when the peer only needs to read, image, or inspect  
- Writable is for rare cases where a remote tool must change the disk **in place**  

### 2. Unraid storage disk (array, parity, cache, or pool)

- Array data disks, parity, **cache**, and **named pools** (Unraid disk inventory — not only “the array”)  
- Also `md*` devices  
- Even **read-only** Host is blocked without Destructive mode  
- Prefer an unassigned disk; live storage under load can be inconsistent or hard on the array/pool  

### 3. Mounted disk

- Any filesystem from that device still mounted (e.g. under `/mnt/…`)  
- Host (read-only or writable) is blocked without Destructive mode  
- Unmount first when you can — Unraid and a remote NBD client both touching the same live filesystem is a consistency risk  
- If the peer only needs **files**, use **SMB/NFS** on the mount; use NBD when they need the **block device** itself  

### 4. Unraid boot device (usually the USB flash)

- Whatever device currently holds **`/boot`** (Unraid’s OS/config drive)  
- Almost always the **USB flash**; if Unraid boots from a disk/partition instead, **that** media is treated the same  
- Host is blocked without Destructive mode  
- Writable host of the boot device is **refused** entirely (even with Destructive mode On)  
- Almost never needed for routine work  

---

## After a special job

1. Finish work on the Host (and any Pull or peer client)  
2. **Stop** any host still listening (or use the emergency buttons below)  
3. Optionally **Destructive mode = No** → **Apply** (hides the elevated Host options)

### Important: Destructive Off does **not** stop live hosts

Destructive mode is a **gate for starting** Hosts, not a kill switch for ones already up.

| Action | Effect on a **writable** host already Listening |
|--------|--------------------------------------------------|
| Destructive **Off** → Apply | Host **keeps** listening writable |
| Destructive **On** again | Still listening (no change) |
| **Stop** on that row | Stops that host |
| **Stop all writable hosts** | Stops every RW host; RO stays up |
| **Stop all hosted disks** | Stops every host |
| **Enable NBD Export = No** → Apply | Stops **all** hosts |

So you can: turn Destructive **On** → Host writable → turn Destructive **Off** (UI locked down again) → leave the writable export running until you intentionally stop it. That is intentional.

**Caveat:** with Destructive Off, the WebUI will not let you *start* another writable (or array/mounted/boot) host — but anything already Listening remains exposed until stopped. Watch the red banner and **Writable** badges on every NBD tab.

### Emergency shutoff switches

On every NBD tab, while hosts are up:

| Control | Stops |
|---------|--------|
| **Stop all writable hosts** (red) | Only **writable** exports — security kill-switch for RW |
| **Stop all hosted disks** | **All** Hosts (RO and RW) |

Nuclear: **Enable NBD Export = No** → Apply (same as stop all, plus blocks new Hosts until re-enabled).

---

## Related

- [when-to-use-nbd.md](when-to-use-nbd.md) — why block-over-network vs file shares  
- [security-and-bind.md](security-and-bind.md)  
- [how-to-use.md](how-to-use.md)  
- [settings-reference.md](settings-reference.md)  
