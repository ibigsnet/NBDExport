# When to use NBD Export

**NFS and SMB share files and folders. NBD shares a disk (or partition) as a block device** — the peer can seek, image, mount, or convert it as if a drive were plugged in locally (physically), but it reaches the disk over the network.

Use this page to decide whether **Network Block Device** is the right tool.  
For button meanings and step-by-step flows, see **[how-to-use.md](how-to-use.md)** (Host vs Pull tabs).

---

## Contents

- [Common scenarios](#common-scenarios)
- [1. Disk imaging, migration, and cold backups](#1-disk-imaging-migration-and-cold-backups)
- [2. Fast private links (Thunderbolt / USB4 host-net, or 10G+)](#2-fast-private-links-thunderbolt-usb4-host-net-or-10g)
- [3. Local AI / inference peers](#3-local-ai-inference-peers)
- [4. Random multi-seek access to remote block media](#4-random-multi-seek-access-to-remote-block-media)
- [5. Lab / homelab operations](#5-lab-homelab-operations)
- [6. When **not** to use NBD](#6-when-not-to-use-nbd)
- [One-line summary](#one-line-summary)

---

## Common scenarios

These are the kinds of jobs NBD Export is built for. Typical path: **Host** the physical disk read-only → **Pull** to a qcow2 on Unraid → **Stop** the host → attach the qcow2 as a VM disk, archive it, or convert back to physical later.

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

Direction is the reverse of “bare metal → VM”: **image on Unraid → physical disk**. Use a private/fast link; multi-terabyte restores are not a Wi‑Fi job.

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

---

## 1. Disk imaging, migration, and cold backups

**Use NBD when** you need a **bit-level (or near bit-level) image** of a physical disk or partition: boot drives, lab rebuilds, a restorable qcow2 before reinstall, or moving a bare-metal disk into a VM later.

**Why not only SMB/NFS?** File shares export the *mounted filesystem tree*. They do not cleanly give you:

- Full GPT + ESP + recovery partitions in one object  
- A **seekable** source that `qemu-img convert` can sparsify (skip zeros → smaller qcow2)  
- Offline imaging of a disk that should not be written during capture  

**Pattern:** **Host** the disk **read-only** on a private/fast link → peer runs `qemu-img convert` (or Unraid **Pull** tab) to qcow2/raw → **Stop** the host. Restore later by converting back to a device or attaching the qcow2 to a VM.

**Good fit:** multi-terabyte NVMe archives over Thunderbolt host-net or 10/25/40G Ethernet on a temporary lab link.

**Versioned cold archives on Unraid:** Pull a whole disk to one stable qcow2 path, then take **BTRFS snapshots** of that subvolume for restore points. See [how-to-use.md — Scenario E](how-to-use.md#scenario-e--cold-physical-disk-archive-on-unraid-qcow2--btrfs-snapshots).

---

## 2. Fast private links (Thunderbolt / USB4 host-net, or 10G+)

**Use NBD when** the path is **high bandwidth and trusted** (direct Thunderbolt cable, dedicated VLAN — not the open Internet). Multi-terabyte images are impractical on Wi‑Fi; they become realistic on Thunderbolt host networking or fast wired underlay.

**Guidance:** bind NBD to a **Thunderbolt** or other **private** IP. Pair with [Thunderbolt Net](https://github.com/ibigsnet/ThunderboltNet) when you use Thunderbolt/USB4 host-to-host networking.

Thunderbolt 4 host-net is often stickered **40 Gbit/s** and under Linux commonly trains about **20 Gbit/s each way** — still about **twice a 10 Gbit/s NIC** one-way for bulk imaging (TCP below line rate; still far above Wi‑Fi).

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
- Keep “golden” disk images on Unraid; re-host only when needed  

---

## 6. When **not** to use NBD

| Situation | Prefer instead |
|-----------|----------------|
| Everyday file sharing | **SMB / NFS** |
| Exposing a disk on `0.0.0.0` / WAN | **Never** for basic NBD (no auth) |
| Production multi-writer VM datastores | Proper shared storage design |
| Offsite backup *policy* (versioning product, cloud sync) | Backup tools; NBD is a **transport for imaging**, not a full backup product |
| “Just copy my documents folder” | SMB/NFS |

---

## One-line summary

**Files and folders → SMB/NFS. Whole disks, partitions, bootable systems, and seekable images → NBD Export.**
