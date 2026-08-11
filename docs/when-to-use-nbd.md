# When to use NBD Export

**NFS and SMB share files and folders. NBD shares a disk (or partition) as a block device** — the peer can seek, image, mount, or convert it as if a drive were plugged in over the network.

Use this page to decide whether **Network Block Device** is the right tool.  
For button meanings and step-by-step flows, see **[how-to-use.md](how-to-use.md)** (Host vs Pull tabs).

---

## Contents

- [1. Disk imaging, migration, and cold backups](#1-disk-imaging-migration-and-cold-backups)
- [2. Fast private links (Thunderbolt / USB4 host-net, or 10G+)](#2-fast-private-links-thunderbolt-usb4-host-net-or-10g)
- [3. Local AI / inference peers](#3-local-ai-inference-peers)
- [4. Random multi-seek access to remote block media](#4-random-multi-seek-access-to-remote-block-media)
- [5. Lab / homelab operations](#5-lab-homelab-operations)
- [6. When **not** to use NBD](#6-when-not-to-use-nbd)
- [One-line summary](#one-line-summary)

## 1. Disk imaging, migration, and cold backups

**Use NBD when** you need a **bit-level (or near bit-level) image** of a physical disk or partition: boot drives, lab rebuilds, a restorable qcow2 before reinstall, or moving a bare-metal disk into a VM later.

**Why not only SMB/NFS?** File shares export the *mounted filesystem tree*. They do not cleanly give you:

- Full GPT + ESP + recovery partitions in one object  
- A **seekable** source that `qemu-img convert` can sparsify (skip zeros → smaller qcow2)  
- Offline imaging of a disk that should not be written during capture  

**Pattern:** **Host** the disk **read-only** on a private/fast link → peer runs `qemu-img convert` (or Unraid **Pull** tab) to qcow2/raw → **Stop** the host. Restore later by converting back to a device or attaching the qcow2 to a VM.

**Good fit:** multi-terabyte NVMe archives over Thunderbolt host-net or 10/25/40G Ethernet on a temporary lab link.

**Versioned cold archives on Unraid:** Pull a whole disk to one stable qcow2 path, then take **BTRFS snapshots** of that subvolume for restore points. See [how-to-use.md — Scenario E](how-to-use.md#scenario-e--cold-physical-disk-archive-on-unraid-qcow2--btrfs-snapshots).

**Both ends Unraid (common):** plug the NVMe into the Unraid with **easy physical access** and **Host** it read-only there (that box may lack free space for a multi-terabyte qcow2). **Pull** on the roomy Unraid (big array / pool) so the **qcow2 file is written there** over the network — no need to open the rack or install the NVMe on the big server. Same plugin, swapped roles — see [how-to-use.md — Scenario C](how-to-use.md#scenario-c--both-ends-are-unraid-plug-the-nvme-where-its-easy-store-the-qcow2-where-theres-room).

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
| Snapshot a peer data disk before a big toolchain upgrade | read-only host → Pull/image → known-good image on Unraid |
| Offline copy of a workstation data drive into the lab NAS | Same imaging pattern as §1 |

**Not the primary tool for:** day-to-day “load weights from a CIFS share into an inference runtime.” SMB/NFS is fine for ordinary file access. NBD is for **disk-shaped** moves and archives next to AI boxes, not a replacement for every share path.

Hardware names above are **example peer classes** (fast host, lots of RAM/GPU, often Thunderbolt/USB4 or 10G to the NAS) — not required products.

---

## 4. Random multi-seek access to remote block media

### Why block-over-network exists

Long before consumer NAS file shares dominated, some systems exposed **storage as block devices over the network** so applications could perform **many concurrent seeks** into large media sets. Examples of that *class of problem* include:

- GIS and mapping stacks that needed several topology or layer datasets “online” at once  
- Multi-spindle or tape-backed archives where software, not a single file copy, drove I/O  
- Workloads that were **seek-bound** and **parallel**: different clients or threads hit different regions or volumes simultaneously  

A pure file-copy pipeline is sequential and path-oriented. A **block device** lets the OS and applications use normal block I/O, caching, and multiple readers against the same exported volume (with care).

### Modern analogues

| Historical class of problem | Modern analogue |
|-----------------------------|-----------------|
| Multiple processes seeking different layers of large spatial datasets | Multiple tools reading different regions of a large raw/qcow dataset or multi-partition disk image |
| Parallel access into tape/disk array “volumes” | Parallel **read-only** readers against an NBD export (e.g. one job imaging, another inspecting) — with shared-client limits |
| Application expects a **disk**, not a folder tree | Partition recovery, disk-map tools, VM disk attach, offline clone-class tooling over the network |
| High-latency media needed careful multi-stream scheduling | Fast links reduce pain, but **seekable block export** still beats “tar the world over SMB” for disk-shaped work |

**Honesty for this plugin:** classic multi-writer shared block SAN is **not** the v1 goal. Prefer **read-only export** and controlled readers for inspect/image. Multi-writer without fencing is dangerous. The multi-seek history explains *why block export exists*; the product focuses on **safe imaging and read-only access**.

---

## 5. Lab / homelab operations

- Capture a disk before reinstall or hardware RMA  
- Build Unraid VMs from physical disks without a USB dock sneakernet  
- Move a disk image between two Linux hosts when multi-terabyte SMB streams were flaky  
- Keep read-only “golden” images on Unraid cache; re-export only when needed  

---

## 6. When **not** to use NBD

| Situation | Prefer instead |
|-----------|----------------|
| Everyday file sharing | **SMB / NFS** |
| Exposing a disk on `0.0.0.0` / WAN | **Never** for basic NBD (no auth) |
| Production multi-writer VM datastores | Proper shared storage design |
| Offsite backup *policy* (versioning, encryption product) | Backup tools; NBD is a **transport for imaging**, not a backup product |

---

## One-line summary

**Files and folders → SMB/NFS. Whole disks, partitions, and seekable images → NBD Export.**
