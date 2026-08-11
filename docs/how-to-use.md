# How to use NBD Export

Settings path: **Settings → Network Services → NBD**

---

## Contents

- [Mental model](#mental-model)
- [What each control does](#what-each-control-does)
- [Tabs (what each screen is for)](#tabs-what-each-screen-is-for)
- [Common mix-ups](#common-mix-ups)
- [Safe defaults checklist](#safe-defaults-checklist)
- [Scenario A — Peer has the disk; Unraid saves a qcow2](#scenario-a-peer-has-the-disk-unraid-saves-a-qcow2)
- [Scenario B — This Unraid has the disk; peer pulls](#scenario-b-this-unraid-has-the-disk-peer-pulls)
- [Scenario C — Both ends are Unraid (plug the NVMe where it’s easy; store the qcow2 where there’s room)](#scenario-c-both-ends-are-unraid-plug-the-nvme-where-its-easy-store-the-qcow2-where-theres-room)
- [Scenario D — Large images over Thunderbolt (or other fast private paths)](#scenario-d-large-images-over-thunderbolt-or-other-fast-private-paths)
- [Scenario E — Cold physical-disk archive on Unraid (qcow2 + BTRFS snapshots)](#scenario-e-cold-physical-disk-archive-on-unraid-qcow2-btrfs-snapshots)
- [Scenario F — What not to do](#scenario-f-what-not-to-do)
- [After you’re done](#after-youre-done)
- [Related docs](#related-docs)

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

## What each control does

Main actions in the NBD UI (and what they run). Columns: **control → process → effect**.

| Control | Process | Effect |
|---------|---------|--------|
| **Host** tab → **Host disk/partition on network** | `qemu-nbd` listens on the bind IP:port | This Unraid is the **server**. One local `/dev/…` (whole disk or partition, including the partition table) is offered as raw blocks visible over the network. Nothing is copied until a client connects. |
| **Stop** (on a hosted disk in the live list) | That `qemu-nbd` process exits | Server off for that disk; the port closes. |
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

1. **Destructive mode = No** (default) — only turn **Yes** for the cases in [destructive-mode.md](destructive-mode.md) (writable host, or array / mounted / flash source)  
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

4. On Unraid: **Stop** that hosted disk  

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
  Host tab → read-only qemu-nbd on private IP  ───►  Pull tab → nbd://A-ip:port
  Publishes raw blocks only                      → /mnt/user/domains/…/disk.qcow2
  Stop host when B finishes                    Image file lives on B forever
```

| Machine | Role | What it has | What it does **not** need |
|---------|------|-------------|---------------------------|
| **A** | **Host** (server) | Easy NVMe/disk access (hot-swap, desk mini-PC, external dock) | Free space for the full qcow2 — A only serves blocks over NBD |
| **B** | **Pull** (client) | Free space on array, cache, or user share (e.g. `domains`) | Physical access to the NVMe — B never sees the bare drive |

So: **local Unraid holds the physical disk but can be short on free space; remote Unraid (or the one with the big array) receives the qcow2 over the network.**

### Steps

1. **Network:** A and B must reach each other on a **private** path — not the open Internet / WAN. Prefer [Thunderbolt Net](https://github.com/ibigsnet/ThunderboltNet) or 10G+ for multi-terabyte pulls; a solid private LAN (including stable home Wi‑Fi for smaller disks) also works. Bind NBD to that private (or Thunderbolt) IP.  
   Thunderbolt 4 host-net is often stickered **40 Gbit/s** and commonly trains about **20 Gbit/s each way** under Linux — still roughly **twice a 10 Gbit/s NIC** one-way for bulk copy (TCP below line rate). See [when-to-use — link choice](when-to-use-nbd.md#2-private-links-thunderbolt-10g-lan--and-when-wifi-is-fine).  
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
4. **Unraid A — Stop** that hosted disk when B is **Done** (or sooner if you abort).  
5. Optional: on B, `qemu-img check /mnt/user/domains/….qcow2`, then attach as a VM disk or archive.

### Tips

- On A, free space only needs to cover normal Unraid operation; the **qcow2 is written entirely on B**.  
- Multi-terabyte images: prefer Thunderbolt host-net or 10G+ wired. Stable private Wi‑Fi is fine for **smaller** jobs (single block stream + sparse qcow2; re-Pull if the host is still up). Spotty wireless still fails mid-convert — ordinary TCP, no special resume.  
- If the NVMe is already an Unraid array member or mounted on A, Destructive mode is required even for read-only — prefer an **unassigned** disk for this workflow.  
- Leave Destructive mode **Off** and Read-only **Yes** for cold imaging.

---

## Scenario D — Large images over Thunderbolt (or other fast private paths)

1. Install [Thunderbolt Net](https://github.com/ibigsnet/ThunderboltNet) so both sides have **Thunderbolt host-net IPs** (addresses on `thunderboltN` / tbn tabs), **or** use another private high-bandwidth path.  
2. **Host:** bind NBD to that private address (not br0/WAN).  
3. **Pull** (or a peer) uses `nbd://<that-ip>:port`.  
4. Match the path to the job size: multi-terabyte → Thunderbolt / 10G+ when you can; smaller disks can use a solid private LAN including Wi‑Fi.

Thunderbolt 4 is often stickered **40 Gbit/s** and under Linux host-net commonly trains about **20 Gbit/s each direction** — still about **2× a 10 Gbit/s NIC** one-way for bulk copy (TCP below line rate). One Thunderbolt cable between peers is a strong default for whole-disk NBD pulls.

Thunderbolt Net “Unraid services / listening” (SMB/NFS/web on the Thunderbolt IP) is **independent** of NBD.

---

## Scenario E — Cold physical-disk archive on Unraid (qcow2 + BTRFS snapshots)

**Goal:** Keep a whole physical disk as a **qcow2 file** on Unraid (restore later or attach as a VM disk), and keep **named restore points** over time without always storing a full second multi-terabyte copy.

NBD Export only creates the image. **BTRFS snapshots** on the storage side hold the history.

### First archive

1. **Host** the physical disk read-only (this Unraid or another — often Scenario C).  
2. Prefer a **BTRFS pool** (or BTRFS subvolume) for the archive share if you plan snapshots.  
3. **Pull** to a **stable path**, e.g. `/mnt/user/disk-archives/workstation.qcow2` (example — pick a share on the roomy pool).  
4. Destructive mode **Off** when the source is unassigned / not mounted.  
5. **Stop** the host when the job finishes.  
6. Check: `qemu-img check /path/to/workstation.qcow2`.

You now have a disk-shaped cold archive. Ordinary files still belong on SMB/NFS.

### Later versions (same disk, point-in-time history)

Keep **one live image path** and snapshot the **subvolume** (or share’s BTRFS dataset) after each good pull:

1. After step 6 above, take a **BTRFS snapshot** of that subvolume (Unraid UI, `btrfs subvolume snapshot`, or your backup tool). Name it by date if you like.  
2. Next time you re-image the same physical disk: **Pull again to the same live path** (`workstation.qcow2`).  
3. Snapshot again after a successful check.  
4. **Prune** old snapshots on a schedule so free space returns.

Unchanged parts of the previous image stay shared via copy-on-write; you mainly pay storage for what changed (and for any full rewrite of large regions). Sparse qcow2 from `qemu-img convert` also keeps empty disk regions small on the first and later pulls.

NBD does not create snapshots for you — after each good Pull, snapshot on the BTRFS side.

---

## Scenario F — What not to do

| Don’t | Do instead |
|-------|------------|
| Leave a writable NBD on the LAN “for convenience” | read-only + stop when done |
| Host array parity “to see if it works” | Unassigned disk; Destructive mode only if you must |
| Pull output path `/dev/sda` | Always a **file** under `/mnt/` |
| Bind `0.0.0.0` with WAN exposure | Specific private IP only |
| Expect hosting in Windows Explorer as a share | Use SMB for files; NBD is for block tools / qemu-img |

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
| [when-to-use-nbd.md](when-to-use-nbd.md) | Why NBD vs SMB/NFS · common scenarios (laptop→VM, recovery, gaming PC→array) |
| [imaging-workflow.md](imaging-workflow.md) | CLI golden path + restore |
| [security-and-bind.md](security-and-bind.md) | Destructive mode, bind IP |
| [nbd-vs-nfs-smb.md](nbd-vs-nfs-smb.md) | Files vs disks decision |
| [../DOCS.md](../DOCS.md) | Install, overview, uninstall |
