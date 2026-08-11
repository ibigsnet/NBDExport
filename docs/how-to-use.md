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
| **Host** tab → **Host disk/partition on network** | `qemu-nbd` listens on the bind IP:port | This Unraid is the **server**. One local `/dev/…` (whole disk or partition, including the partition table) is offered as raw blocks. Nothing is copied until a client connects. |
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

## Scenario C — Both ends are Unraid (source has the NVMe; destination has the space)

**Classic lab pattern:** one box is easy to open (hot-swap bay, external NVMe enclosure, mini-PC you can sit next to). Another box has the free space for a multi-terabyte qcow2. You **do not** need the space on the machine that holds the physical disk.

```text
  Unraid A (source)                         Unraid B (destination)
  ─────────────────                         ──────────────────────
  Physical NVMe plugged in                  Large array/cache free
  Host tab → RO qemu-nbd on private IP ───► Pull tab → nbd://A-ip:port
  (little free space is fine)                 → /mnt/user/domains/…/disk.qcow2
  Stop host when B finishes                 Job can run while you close the browser
```

### Why this works well

| Machine | Role | Why |
|---------|------|-----|
| **A — easy NVMe access** | **Host** (server) | Plug the disk in, publish blocks, unplug when done. Disk capacity on A does not limit the image size. |
| **B — has space** | **Pull** (client) | Writes sparse qcow2 under `/mnt/…` (e.g. `/mnt/user/domains/` or a large share). |

Same plugin on both; only the **role** swaps.

### Steps

1. **Network:** A and B can reach each other on a **private** path (Thunderbolt Net recommended for multi-terabyte images; or a dedicated LAN). Prefer binding NBD to that private IP, not WAN.  
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
   - Output: e.g. `/mnt/user/domains/nvme-serial-or-name.qcow2` (must be a **file** under `/mnt/…`, never `/dev/…`).  
   - Format: **qcow2**.  
   - Start pull; watch **Status** for Running → Done.  
4. **Unraid A — Stop** the host row when B is **Done** (or sooner if you abort).  
5. Optional: on B, `qemu-img check /mnt/user/domains/….qcow2`, then attach as a VM disk or archive.

### Tips

- A only needs enough free space for Unraid itself; the **image lands on B**.  
- Multi-terabyte images: use Thunderbolt or 10G+; Wi‑Fi is the wrong medium.  
- If the NVMe is already an Unraid array member or mounted, Destructive mode is required even for RO — prefer an **unassigned** disk for this workflow.  
- Leave Destructive mode **Off** and Read-only **Yes** for cold imaging.

---

## Scenario D — Large images over Thunderbolt (not Wi‑Fi)

1. Install [Thunderbolt Net](https://github.com/ibigsnet/ThunderboltNet) so both sides have **Thunderbolt host-net IPs** (addresses on `thunderboltN` / tbn tabs).  
2. **Host:** bind NBD to that **Thunderbolt** address (not br0/WAN, not Wi‑Fi).  
3. **Pull** (or a peer) uses `nbd://<thunderbolt-ip>:port`.  
4. For **multi-terabyte** images, use Thunderbolt or 10G+ wired — **not** Wi‑Fi.

Thunderbolt Net “Unraid services / listening” (SMB/NFS/web on the Thunderbolt IP) is **independent** of NBD.

---

## Scenario E — Archive a data volume as qcow2

1. RO host on the volume (Host tab / peer qemu-nbd).  
2. Pull to `/mnt/user/domains/…` or a large share.  
3. Keep Destructive mode **Off** unless you knowingly need array/mounted devices.  
4. Stop hosting; verify with `qemu-img check`.

Day-to-day model files still belong on **SMB/NFS**.

---

## Scenario F — What not to do

| Don’t | Do instead |
|-------|------------|
| Leave a writable NBD on the LAN “for convenience” | RO + stop when done |
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
| [when-to-use-nbd.md](when-to-use-nbd.md) | Why NBD vs SMB/NFS |
| [imaging-workflow.md](imaging-workflow.md) | CLI golden path + restore |
| [security-and-bind.md](security-and-bind.md) | Destructive mode, bind IP |
| [nbd-vs-nfs-smb.md](nbd-vs-nfs-smb.md) | Files vs disks decision |
| [../DOCS.md](../DOCS.md) | Install, overview, uninstall |
