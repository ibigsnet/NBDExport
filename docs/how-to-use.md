# How to use NBD Export

Settings path: **Settings → Network Services → NBD**

---

## Mental model

NBD is **not** a file share (SMB/NFS) and does **not** stay on forever like a share service.

There are only two jobs:

1. **Host (server)** — this Unraid publishes a local disk (`/dev/…`) so others can read it over TCP.  
2. **Pull (client)** — this Unraid (or another machine) **saves** a hosted disk into a **file** under `/mnt/…` (qcow2/raw).

```text
  HOST (server)                         PULL (client)
  ─────────────                         ─────────────
  Pick a local /dev disk                Point at nbd://IP:port
  Start qemu-nbd on IP:port    ──────►  qemu-img convert → /mnt/.../disk.qcow2
  Peer can image those blocks           When done, stop the host
```

Hosting alone does **not** create a file. Pulling alone needs someone already hosting.

---

## If you click this… (read each row left → right)

Each **row** is one control. Read **across** the row.

| If you click… | What runs under the hood | What that means for you |
|---------------|--------------------------|-------------------------|
| **Host** tab → **Host disk/partition on network** | `qemu-nbd` listens on the bind IP:port | This Unraid is the **server**. One local `/dev/…` (whole disk or partition, including the partition table) is offered as raw blocks visible over the network. Nothing is copied until a client connects. |
| **Stop** (on a live host row) | That `qemu-nbd` process exits | Server off for that disk; the port closes. |
| **Pull** tab → **Pull remote disk → file** | Background `qemu-img convert` from `nbd://…` | This Unraid is the **client**. A remote hosted disk is written to a **file** under `/mnt/…` (never to `/dev/…`). |
| **Settings** → **Apply** | Writes plugin config only | Does **not** start hosting and does **not** start a pull. |

**Enable NBD Export = Yes** (Settings) only allows the plugin to run. You still use **Host** or **Pull** for real work.

---

## Tabs (what each screen is for)

| Tab | Purpose |
|-----|---------|
| **Status** | Tools check, pull-job list, collapsible CLI. Live hosts also appear at the top of **every** tab. |
| **Host** | Publish a local Unraid disk (server). Multi-disk: host again with another free port. |
| **Pull** | Image a remote `nbd://…` into a file under `/mnt/…` (client). |
| **Settings** | Enable plugin, defaults, Destructive mode, export/import config. |

At the top of every tab you always see **disks currently hosted** (and an orange banner if Destructive mode is ON).

---

## Common mix-ups

These are **not** what NBD does:

| You might think… | Actually… |
|------------------|-----------|
| “Host” starts SMB/NFS | No — different protocols. Use SMB/NFS for folders of files. |
| It mounts a share on the Dashboard | No share name is created. Clients use `nbd://IP:port` or the Pull tab. |
| The disk is permanently added to Unraid | No — temporary process until you **Stop**. |
| Hosting also images the disk | No — imaging is a separate **Pull** (or peer `qemu-img`) step. |

Until a client connects, the host just **waits** on the port (minimal disk server).

---

## Safe defaults checklist

1. **Destructive mode = No** (default)  
2. **Read-only = Yes** when hosting  
3. **Bind IP** = Thunderbolt or private LAN (not `0.0.0.0`)  
4. Prefer an **unassigned / not array / not mounted** disk  
5. When finished: **Stop** the host (top of any tab, or Status)

---

## Scenario A — Peer has the disk; Unraid saves a qcow2

**Goal:** Whole disk from a desktop/laptop → file on Unraid (most common lab path).

### On the peer (host)

Option 1 — peer CLI:

```bash
qemu-nbd --read-only --persistent --shared=2 \
  --bind=<PEER_THUNDERBOLT_OR_LAN_IP> --port=10809 --format=raw \
  /dev/nvme0n1
```

Option 2 — peer is also Unraid with this plugin: **Host** tab → host that disk.

### On this Unraid (pull)

1. **Pull** tab  
2. NBD URL: `nbd://<peer-ip>:10809`  
3. Output: e.g. `/mnt/user/domains/name.qcow2` (a **file**, never `/dev/…`)  
4. Format: qcow2 → start pull  
5. When **Done**, stop hosting on the peer  

**Why:** `qemu-img convert` from a seekable NBD source can skip zeros → smaller sparse qcow2.

---

## Scenario B — This Unraid has the disk; peer pulls

1. **Host** tab: device, bind IP, port, **Read-only = Yes** → host  
2. Top of page shows **Listening** and `nbd://IP:port`  
3. On the peer:

```bash
qemu-img info nbd://<unraid-ip>:port
qemu-img convert -p -f raw -O qcow2 -t writeback -W \
  nbd://<unraid-ip>:port /path/to/out.qcow2
```

4. On Unraid: **Stop** that host row  

---

## Scenario C — Both ends are Unraid (plug the NVMe where it’s easy; store the qcow2 where there’s room)

**Problem this solves**

- You have a **physical disk** (e.g. bare NVMe, laptop drive, external enclosure) you can plug into **one** Unraid easily.  
- That machine may **not** have multi-terabyte free space on its array/cache for a qcow2 dump.  
- Your **big Unraid** (rack, main array, lots of free space) is awkward to open, or you don’t want to shut it down just to install a temporary NVMe.

**Pattern:** the easy-access box only **hosts** the physical disk (server). The roomy box **pulls** over the network and **writes the qcow2 file** onto its own storage (array, pool, domains share, etc.). Same NBD Export plugin on both; only the role changes.

```text
  Unraid A (easy physical access)              Unraid B (roomy storage)
  ──────────────────────────────              ─────────────────────────
  Plug in the NVMe / disk here                 Array / pool / NAS capacity
  May be low on free space                     Free space for multi-TB qcow2
  Host tab → RO qemu-nbd on private IP  ───►  Pull tab → nbd://A-ip:port
  Publishes raw blocks only                      → /mnt/user/domains/…/disk.qcow2
  Stop host when B finishes                    Image file lives on B forever
```

| Machine | Role | What it has | What it does **not** need |
|---------|------|-------------|---------------------------|
| **A** | **Host** (server) | Easy NVMe/disk access (hot-swap, desk mini-PC, external dock) | Free space for the full qcow2 — A only serves blocks over NBD |
| **B** | **Pull** (client) | Free space on array, cache, or user share (e.g. `domains`) | Physical access to the NVMe — B never sees the bare drive |

So: **local Unraid holds the physical disk but can be short on free space; remote Unraid (or the one with the big array) receives the qcow2 over the network.**

### Steps

1. **Network:** A and B must reach each other on a **private** path — not the open Internet / WAN. Prefer [Thunderbolt Net](https://github.com/ibigsnet/ThunderboltNet) for multi-terabyte pulls; a dedicated LAN or 10G+ also works. Bind NBD to that private (or Thunderbolt) IP.  
   *Why Thunderbolt for big images?* A Thunderbolt 4–class host-net path is often marketed as **40 Gbit/s**, but under Linux you commonly see about **20 Gbit/s each way** (simplex lanes / full-duplex style use). That is still roughly **twice a 10 Gbit/s NIC** in one direction — and far beyond Wi‑Fi for dumping a whole NVMe. Trained rate ≠ full TCP, but the gap vs 10G is still why a TB cable next to the desk is worth it for NBD.  
2. **Unraid A — Host**  
   - Destructive mode **Off** when possible (unassigned / not mounted / not array).  
   - Device = the whole physical disk (or the partition you need).  
   - Bind IP = A’s private or Thunderbolt address.  
   - Port = `10809` (or free).  
   - **Read-only = Yes**.  
   - **Host disk/partition on network**.  
   - Confirm the top-of-page table shows **Listening** and note `nbd://A-ip:port`.  
3. **Unraid B — Pull**  
   - NBD URL: `nbd://A-ip:port` (same as A shows).  
   - Output: e.g. `/mnt/user/domains/nvme-serial-or-name.qcow2` (a **file** on B’s array/share under `/mnt/…`, never `/dev/…`).  
   - Format: **qcow2**.  
   - Start pull; watch **Status** for Running → Done.  
4. **Unraid A — Stop** the host row when B is **Done** (or sooner if you abort).  
5. Optional: on B, `qemu-img check /mnt/user/domains/….qcow2`, then attach as a VM disk or archive.

### Tips

- On A, free space only needs to cover normal Unraid operation; the **qcow2 is written entirely on B**.  
- Multi-terabyte images: Thunderbolt host-net or 10G+ wired; **Wi‑Fi is the wrong medium**.  
- If the NVMe is already an Unraid array member or mounted on A, Destructive mode is required even for RO — prefer an **unassigned** disk for this workflow.  
- Leave Destructive mode **Off** and Read-only **Yes** for cold imaging.

---

## Scenario D — Large images over Thunderbolt (not Wi‑Fi)

1. Install [Thunderbolt Net](https://github.com/ibigsnet/ThunderboltNet) so both sides have **Thunderbolt host-net IPs** (addresses on `thunderboltN` / tbn tabs).  
2. **Host:** bind NBD to that **Thunderbolt** address (not br0/WAN, not Wi‑Fi).  
3. **Pull** (or a peer) uses `nbd://<thunderbolt-ip>:port`.  
4. For **multi-terabyte** images, stay on that path — **not** Wi‑Fi.

**Speed gut-check (why bother with Thunderbolt Net):** Thunderbolt 4 ports are often stickered **40 Gbit/s**. On Linux host-net you frequently train about **20 Gbit/s each direction** (not 40 each way). That is still in the same ballpark as **~2× a 10 Gbit/s Ethernet NIC** for one-way bulk copy — before you even compare cable chaos (one TB cable between peers vs switch, DAC, etc.). Real TCP will be lower than the trained line rate; it is still the comfortable home for NBD dumps of whole disks.

Thunderbolt Net “Unraid services / listening” (SMB/NFS/web on the Thunderbolt IP) is **independent** of NBD.

---

## Scenario E — Cold physical-disk archive on Unraid (qcow2 + optional BTRFS versions)

**Goal:** Keep a **backup image of a whole physical disk** on Unraid (for restore later, or attach as a VM disk), and optionally keep **several point-in-time versions** without storing N full independent multi-terabyte copies if the filesystem can help.

This is **not** gibberish — it is a real pattern — with one important accuracy note about **how** space savings work.

### What NBD is for here

1. **Host** the physical disk RO (this Unraid or another — often Scenario C).  
2. **Pull** to a qcow2 on Unraid storage, e.g. `/mnt/user/domains/workstation-nvme.qcow2` or a dedicated share on a large pool/array.  
3. **Stop** the host when the job finishes.  
4. Verify: `qemu-img check …`.

You now have a **file-shaped** copy of the disk on Unraid. Day-to-day files still belong on SMB/NFS; this is for **disk-shaped** cold archive.

### Versioning / “little overhead of differences” — what actually works

| Approach | Space-efficient for successive versions? | Notes |
|----------|------------------------------------------|--------|
| **BTRFS snapshot of the subvolume/share** that holds the qcow2 (after each good pull) | **Yes, often** | Classic COW: snapshot freezes the *previous* qcow2 extents; later overwrites only pay for **changed** filesystem blocks. Best when you **update the same file path** (or manage one active image + snapshots), not when every pull creates a brand-new unrelated filename with a full rewrite. |
| **Two separate full Pulls** to `disk-2026-01.qcow2` and `disk-2026-02.qcow2` | **Usually no** | Two independent full converts ≈ two full sizes unless you run **dedupe** later or use special tools. BTRFS does not magically share extents between two freshly written large files. |
| **qcow2 internal / external snapshots** (base + overlay) | **Yes, for VM-style deltas** | QEMU’s model (backing file + overlay). Fits “boot this image in a VM and track changes”; less natural for “re-image a bare NVMe from scratch every month” unless you design the chain carefully. |
| **Expect Unraid “array parity” to version qcow2s** | **No** | Array parity is not a per-file COW history of your images. |

**Practical recipe many labs use**

1. Prefer a **BTRFS pool** (or BTRFS subvolume) for the archive share if you want filesystem snapshots.  
2. First time: Pull → e.g. `/mnt/cache_btrfs/disk-archives/workstation.qcow2` (path is an example).  
3. After a good check: take a **BTRFS snapshot** of that subvolume/share (Unraid UI / `btrfs subvolume snapshot` / your backup tool).  
4. Next month: Pull **again** (overwrite the live `workstation.qcow2`, or write then replace carefully). The **snapshot** keeps the old point-in-time; COW means unchanged parts of the old image are not fully duplicated for the snapshot’s view.  
5. Prune old snapshots on a schedule so free space returns.

**Honest limits**

- A **full** re-image that rewrites almost every block of the qcow2 still costs a lot of new space until old snapshots are deleted.  
- NBD Export does **not** implement incremental NBD or automatic BTRFS snapshots — it only delivers the **image file**. Snapshots/versioning are **storage-layer** (or qcow2-layer) follow-ups.  
- Sparse qcow2 from `qemu-img convert` already helps **empty** disk regions; that is separate from BTRFS snapshot savings between versions.

### Minimal steps (single archive, no snapshot yet)

1. RO **Host** of the physical disk.  
2. **Pull** to `/mnt/user/domains/…` or a large BTRFS share.  
3. Destructive mode **Off** unless you knowingly need array/mounted source devices.  
4. **Stop** host; `qemu-img check`.  
5. Optional: BTRFS snapshot of the archive subvolume for a named restore point.

---

## Scenario F — What not to do

| Don’t | Do instead |
|-------|------------|
| Leave a writable NBD on the LAN “for convenience” | RO + stop when done |
| Host array parity “to see if it works” | Unassigned disk; Destructive mode only if you must |
| Pull output path `/dev/sda` | Always a **file** under `/mnt/` |
| Bind `0.0.0.0` with WAN exposure | Specific private IP only |
| Expect hosting in Windows Explorer as a share | Use SMB for files; NBD is for block tools / qemu-img |
| Expect two full Pulls to two qcow2 names to auto-share space on BTRFS | Use **snapshots** of one image path, or qcow2 backing chains, or dedupe tools |

---

## After you’re done

1. Confirm pull job **Done** / `qemu-img check` if needed  
2. **Stop** any host still listening  
3. Set **Destructive mode = No** if you turned it on  
4. Optional: put serial/model in the filename or a sidecar note  

---

## Related docs

| Doc | Topic |
|-----|--------|
| [when-to-use-nbd.md](when-to-use-nbd.md) | Why NBD vs SMB/NFS |
| [imaging-workflow.md](imaging-workflow.md) | CLI golden path + restore |
| [security-and-bind.md](security-and-bind.md) | Destructive mode, bind IP |
| [nbd-vs-nfs-smb.md](nbd-vs-nfs-smb.md) | Files vs disks decision |
| [../DOCS.md](../DOCS.md) | Install, overview, uninstall |
