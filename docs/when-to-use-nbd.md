# When to use NBD Export

**NFS and SMB share files and folders. NBD shares a disk (or partition) as a block device** — the peer can seek, image, mount, or convert it as if a drive were plugged in locally (physically), but it reaches the disk over the network.

Use this page to decide whether **Network Block Device** is the right tool.  
For button meanings and step-by-step flows, see **[how-to-use.md](how-to-use.md)** (Host vs Pull tabs).

---

## Contents

- [Common scenarios](#common-scenarios)
- [Image formats (not only qcow2)](#image-formats-not-only-qcow2)
- [1. Disk imaging, migration, and cold backups](#1-disk-imaging-migration-and-cold-backups)
- [2. Private links (Thunderbolt, 10G, LAN — and when Wi‑Fi is fine)](#2-private-links-thunderbolt-10g-lan--and-when-wifi-is-fine)
- [3. Local AI / inference peers](#3-local-ai-inference-peers)
- [4. Random multi-seek access to remote block media](#4-random-multi-seek-access-to-remote-block-media)
- [5. Lab / homelab operations](#5-lab-homelab-operations)
- [6. When **not** to use NBD](#6-when-not-to-use-nbd)
- [One-line summary](#one-line-summary)

---

## Common scenarios

These are the kinds of jobs NBD Export is built for.

**NBD itself is format-agnostic:** the Host publishes **raw blocks** (a disk or partition). What you *store* afterward — **qcow2**, **raw** (often named `.img`), or another `qemu-img` target — is a **file format choice**, not a limit of NBD. qcow2 is the usual default because it sparsifies well and plugs into Unraid VMs; it is not the only option. Details: [Image formats](#image-formats-not-only-qcow2).

Typical path: **Host** the physical disk read-only → **Pull** (or `qemu-img convert`) to a file on Unraid → **Stop** the host → attach the image as a VM disk, archive it, or **write it back to physical media** later.

### Laptop → bootable VM on Unraid (remote via VNC / RDP)

You want the laptop’s **entire OS disk** (Windows, Linux, partitions and all) as a **bootable virtual machine** on Unraid, then use the Unraid web UI **VNC**, **RDP**, or similar to work on it without the laptop open on the desk.

1. Host the laptop disk (or image it on a machine with easy physical access — [Scenario C](how-to-use.md#scenario-c--both-ends-are-unraid-plug-the-nvme-where-its-easy-store-the-qcow2-where-theres-room)).  
2. Pull to e.g. `/mnt/user/domains/laptop.qcow2`.  
3. Create an Unraid **VM**, attach that qcow2 as the disk, boot, install VirtIO drivers if needed.  
4. Connect remotely (VNC console, RDP, etc.).

SMB would only copy *files* off a mounted volume; NBD captures the **whole disk layout** so the guest can boot.

### Gaming / workstation PC becomes Unraid — OS and games move to a VM

You installed **Unraid on a former gaming desktop** and want the old **physical OS + game drive(s)** living on the **array as a bootable VM**, so you can free those NVMe/SATA disks for cache, array, or another machine.

1. Host each physical media disk read-only (unassigned if possible).  
2. Pull each to qcow2 under `/mnt/user/domains/` (or a large share).  
3. Build a VM with GPU passthrough if you still want high-performance gaming on that box.  
4. When the VM is solid, wipe or repurpose the physical media.

Same idea as the laptop path: **physical boot disk → qcow2 → VM**, not “copy the Steam folder over SMB and hope.”

### Cloud / remote gaming VM → physical portable (tablet, mini-PC, travel box)

You have (or had) a **gaming or desktop environment as a VM or disk image** on Unraid and want that system on a **physical device** you carry — tablet dock, travel mini-PC, secondary desktop.

1. Start from the qcow2 (or host a disk that already holds the guest).  
2. On the portable machine (or a temporary host with the target NVMe/SSD plugged in), write the image **to the physical disk** with `qemu-img convert` (or equivalent) from `nbd://…` if Unraid is hosting the source, or convert from the qcow2 file over a fast path.  
3. Boot the physical device from that media.

Direction is the reverse of “bare metal → VM”: **image on Unraid → physical disk**. Prefer a private, stable path; multi-terabyte restores are slow and fragile on weak or congested wireless.

### Someone else’s disk — preserve a copy before recovery work

A friend, client, or lab machine hands you a **failing or unknown physical disk**. Before you run recovery tools, chkdsk-class utilities, or partition editors, you want a **frozen bit-level image** so you can always go back.

1. Host the disk **read-only** (prefer unassigned; Destructive mode only if you must).  
2. Pull to qcow2 on Unraid (roomy array).  
3. Stop the host.  
4. Do recovery against a **working copy** (second convert, or attach the qcow2 to a recovery VM), not against the only original.

NBD gives you the image without relying on the source OS still booting or sharing files.

### Recover on Unraid’s fast array, not on the sick disk

The physical disk is **slow, failing, or IOPS-limited**. You want to run carve tools, filesystem repair, or multi-pass recovery on **Unraid storage** (fast pool/array, lots of RAM, snapshots) instead of hammering the dying media.

1. One **read-only** Host + Pull (or fewest passes possible) to get a qcow2 onto Unraid.  
2. **Stop** the physical host so the failing disk rests.  
3. Point recovery tools at the qcow2 (via VM, `qemu-nbd` loop locally, or convert to raw on fast storage).  
4. Optional: BTRFS snapshots of the archive before aggressive repair — [Scenario E](how-to-use.md#scenario-e--cold-physical-disk-archive-on-unraid-qcow2--btrfs-snapshots).

The expensive I/O lands on healthy Unraid media; the patient disk is read as little as possible.

### Plug the disk where it’s easy; store the image where there’s space

Physical access on a small Unraid / dock / mini-PC; multi-terabyte free space on the rack Unraid. Host on A, Pull on B. Full walkthrough: [how-to-use.md — Scenario C](how-to-use.md#scenario-c--both-ends-are-unraid-plug-the-nvme-where-its-easy-store-the-qcow2-where-theres-room).

### One NVMe slot — prepare a larger drive before you open the chassis

Many thin laptops, gaming tablets, and mini-PCs have **only one internal NVMe slot**. You cannot leave the factory 1 TB installed *and* write a full OS to a second internal 2 TB at the same time — there is no spare bay. You still want a **ready-to-boot larger disk** without doing a slow “install after the swap and hope.”

**Public pattern (prepare offline → swap once):**

1. **Build the system as a disk image on Unraid** — for example install Linux/Windows in an Unraid **VM** to a **qcow2** (or raw `.img`) under `/mnt/user/domains/`, *or* Host + Pull an existing machine’s disk into an image first.  
2. When the **new empty NVMe** arrives, put it in a **USB/Thunderbolt enclosure**, dock, or any Linux/Unraid box that has a free slot — not inside the one-slot device yet.  
3. **Write the image to that physical NVMe** with `qemu-img convert` (qcow2/raw → device), e.g.  
   `qemu-img convert -p -f qcow2 -O raw /mnt/user/domains/ready.qcow2 /dev/nvmeXn1`  
   (triple-check the target device). Prefer a private/fast path if the image file and the dock are on different machines; Host/Pull or file copy both work depending on layout.  
4. Power down, **install the prepared 2 TB into the one-slot device**, boot. Keep the old 1 TB as a spare or archive it with another Host → Pull if you still need a bit-level copy.

**Why NBD fits this story:** the hard part is not “share a folder of installers” — it is **moving whole bootable disks** (partition tables, ESP, OS) between *machines that have a free slot or a dock* and *images on Unraid*. NBD is one way to get physical ↔ image without sneakernet when the disk is plugged in where it is easy. SMB/NFS still win for copying ISO installers or a documents folder.

**Simpler variant:** skip the VM — Host the current internal disk read-only (live USB or another host if the OS cannot export itself), Pull to Unraid, later convert that image onto the larger drive in a dock, then swap. Same idea: **image lives on the NAS; physical write happens where the new media is plugged in.**

Avoid the long path of “install over live NBD into a remote qcow2, then later copy again to hardware” unless you enjoy extra steps. Prefer **image on Unraid → one convert onto the new physical disk in a dock → install once**.

---

## Image formats (not only qcow2)

NBD Export **hosts a block device**. Clients see raw sectors. **File formats are what you convert into (or from) for storage and VMs.**

| Format / artifact | Role with this plugin |
|-------------------|------------------------|
| **Physical `/dev/…`** | What **Host** publishes (whole disk or partition). |
| **qcow2** | Default **Pull** target. Sparse, snapshot-friendly, natural Unraid **VM** disk. Best everyday archive. |
| **raw** (often named **`.img`**) | Full bit image; Pull format option; simple restore target (`convert … -O raw /dev/…`). Larger on disk than sparse qcow2 when the source had empty space. |
| **Other `qemu-img` outputs** (vmdk, vdi, …) | Possible on the **CLI** with `qemu-img convert` from `nbd://…` or from an intermediate qcow2/raw. The Unraid **Pull** tab offers **qcow2** and **raw** only. |
| **`.iso`** | Optical/install **file**, not a whole GPT system disk. Share installers with **SMB/NFS** or attach as a VM CD. Do not expect “Pull to ISO” to replace disk imaging; a disk image and an ISO solve different problems. |

**Mental model:** NBD = *the wire and the remote disk*. qcow2/raw/img = *how you store or hand that disk to a VM or a future physical drive*.

Restore / reverse path examples: [imaging-workflow.md](imaging-workflow.md).

---

## 1. Disk imaging, migration, and cold backups

**Use NBD when** you need a **bit-level (or near bit-level) image** of a physical disk or partition: boot drives, lab rebuilds, a restorable image before reinstall, or moving a bare-metal disk into a VM later.

**Why not only SMB/NFS?** File shares export the *mounted filesystem tree*. They do not cleanly give you:

- Full GPT + ESP + recovery partitions in one object  
- A **seekable** source that `qemu-img convert` can sparsify (skip zeros → smaller qcow2)  
- Offline imaging of a disk that should not be written during capture  

**Pattern:** **Host** the disk **read-only** on a private/fast link → peer runs `qemu-img convert` (or Unraid **Pull** tab) to **qcow2 or raw** → **Stop** the host. Restore later by converting back to a device or attaching the image to a VM.

**Good fit:** multi-terabyte NVMe archives over Thunderbolt host-net or 10/25/40G Ethernet on a temporary lab link.

**Versioned cold archives on Unraid:** Pull a whole disk to one stable qcow2 path, then take **BTRFS snapshots** of that subvolume for restore points. See [how-to-use.md — Scenario E](how-to-use.md#scenario-e--cold-physical-disk-archive-on-unraid-qcow2--btrfs-snapshots).

---

## 2. Private links (Thunderbolt, 10G, LAN — and when Wi‑Fi is fine)

**Use NBD on a path you trust** (private LAN, Thunderbolt host-net, dedicated VLAN — not the open Internet). Bind to a **specific private IP**, not `0.0.0.0` / WAN.

### Link choice

| Path | Role for NBD |
|------|----------------|
| **Thunderbolt / USB4 host-net** | Best default for multi-terabyte Host/Pull. TB4-class is often stickered **40 Gbit/s** and under Linux commonly trains about **20 Gbit/s each way** — still about **twice a 10 Gbit/s NIC** one-way (TCP below line rate). Pair with [Thunderbolt Net](https://github.com/ibigsnet/ThunderboltNet). |
| **10G+ / dedicated Ethernet** | Excellent for large images when you already have it. |
| **Solid home Wi‑Fi (private SSID)** | Fine for **smaller** disks or when you have no faster private path. Stable wireless can finish a Host/Pull; a **spotty** link can still drop mid-convert. |
| **Guest / congested / roaming Wi‑Fi** | Poor for multi-hour multi-terabyte jobs — not because NBD “hates Wi‑Fi,” but because long bulk transfers need **sustained** bandwidth and a connection that stays up. |

**What NBD helps with on Wi‑Fi (and what it doesn’t):** NBD is still ordinary TCP — it does **not** resume a half-finished convert the way rsync can resume many small files. What *does* help on a private wireless path is the job shape: one **seekable** block stream, `qemu-img convert` that can **skip zeros** (less airtime than a raw dump), and a **Host that stays up** so you can re-Pull cleanly if a job fails without re-plugging or re-exporting the disk. Prefer Thunderbolt / 10G+ when the image is multi-terabyte or the link is flaky; use solid private Wi‑Fi when the disk is smaller and the SSID is reliable.

---

## 3. Local AI / inference peers

High-performance mini-PCs and workstations used for **local LLMs and inference** (for example Strix Halo–class APUs, DGX Spark–class systems, or similar GPU/NPU boxes) often sit next to an Unraid storage host on Thunderbolt or 10G.

**Use NBD when** you need **block-oriented bulk data**, not only “browse a share”:

| Workload | Why NBD can help |
|----------|------------------|
| Archive a model-weight or dataset **volume** as a disk image on Unraid | Whole-volume capture without re-walking millions of small files |
| Pull a prepared **qcow2/raw dataset disk** from Unraid onto a peer | Single block stream; durable convert jobs |
| Snapshot a peer data disk before a big toolchain upgrade | read-only host → Pull → known-good image on Unraid |
| Offline copy of a workstation data drive into the lab NAS | Same imaging pattern as §1 |

**Not the primary tool for:** day-to-day “load weights from a CIFS share into an inference runtime.” SMB/NFS is fine for ordinary file access. NBD is for **disk-shaped** moves and archives next to AI boxes, not a replacement for every share path.

Hardware names above are **example peer classes** (fast host, lots of RAM/GPU, often Thunderbolt/USB4 or 10G to the NAS) — not required products.

---

## 4. Random multi-seek access to remote block media

### Why block-over-network exists

Some workloads need a **disk**, not a folder tree: recovery tools, partition editors, VM disks, or multiple readers seeking different regions of a large volume.

A pure file-copy pipeline is sequential and path-oriented. A **block device** lets the OS and applications use normal block I/O and caching. This plugin focuses on **safe imaging and read-only access**, not multi-writer SAN.

---

## 5. Lab / homelab operations

- Capture a disk before reinstall or hardware RMA  
- Build Unraid VMs from physical disks without sneakernet  
- Move a disk image between two Linux hosts when multi-terabyte file copies were flaky  
- Keep “golden” disk images on Unraid (qcow2/raw); write them to new physical media when a dock or free slot is available  
- One-slot devices: prepare the replacement NVMe offline, then swap once  

---

## 6. When **not** to use NBD

| Situation | Prefer instead |
|-----------|----------------|
| Everyday file sharing | **SMB / NFS** |
| Handing out OS **installers** (`.iso`) | SMB/NFS, HTTP, or Unraid VM CD attachment |
| Exposing a disk on `0.0.0.0` / WAN | **Never** for basic NBD (no auth) |
| Production multi-writer VM datastores | Proper shared storage design |
| Offsite backup *policy* (versioning product, cloud sync) | Backup tools; NBD is a **transport for imaging**, not a full backup product |
| “Just copy my documents folder” | SMB/NFS |

---

## One-line summary

**Files and folders → SMB/NFS. Whole disks and partitions over the network (then store as qcow2, raw/`.img`, or convert back to physical) → NBD Export.**
