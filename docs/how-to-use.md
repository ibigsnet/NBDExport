# How to use NBD Export

This guide explains **what the buttons do**, how the two roles work, and step-by-step scenarios.

Settings path: **Settings → Network Services → NBD**

---

## Mental model (two roles)

NBD is **not** a file share and **not** a background “service” like SMB that stays on forever by default.

```text
┌──────────────────────────────┐     TCP      ┌────────────────────────────────┐
│  Section 3 — HOST (server)   │  nbd://IP:p  │  Section 4 — PULL (client)     │
│  This Unraid publishes a     │ ───────────► │  This Unraid (or a peer) saves  │
│  local /dev disk or partition│              │  that disk as a FILE under /mnt │
│  “Host this disk on the net” │              │  “Pull remote disk to file”     │
│  = qemu-nbd listening        │              │  = qemu-img convert → qcow2     │
└──────────────────────────────┘              └────────────────────────────────┘
```

| UI action | What actually runs | Role |
|-----------|-------------------|------|
| **Host this disk on the network** (section 3) | `qemu-nbd` on IP:port | **Server** — publishes one Unraid `/dev/…` (whole disk or partition **blocks**, including partition table) |
| **Stop listener** | Stops that `qemu-nbd` process | Server off — port closes |
| **Pull remote disk to file** (section 4) | Background `qemu-img convert` from `nbd://…` | **Client** — saves a remote hosted disk as a **file** under `/mnt/…` |
| **Apply** (Settings) | Saves config only | Does **not** start hosting or pulling |

**Enable NBD Export = Yes** only allows the plugin to run. Nothing is hosted until section 3; nothing is saved to a file until section 4.

---

## What “host a local disk” is *not*

| Not this | Why |
|----------|-----|
| Starting Unraid’s SMB/NFS service | Different protocols; file shares |
| Mounting a share on the Dashboard | NBD does not create a share name |
| Permanently “adding” a disk to Unraid | Temporary process until you Stop |
| Imaging the disk by itself | Imaging is a **separate** client step (section 4 or peer CLI) |

Until a client connects, the host just **waits** on the port (like a minimal disk server).

---

## Safe defaults checklist

1. **Destructive mode = No** (default)  
2. **Read-only = Yes** when hosting  
3. **Bind IP** = Thunderbolt or private LAN (not `0.0.0.0`)  
4. Disk is **unassigned / not array / not mounted** when possible  
5. When finished: **Stop** the host in section 2  

---

## Scenario A — Peer Linux has a disk; Unraid saves a qcow2 (most common lab path)

**Goal:** Copy a whole disk from a desktop/laptop into Unraid over a fast private link.

### On the peer (host the disk)

Option 1 — peer CLI (any Linux with qemu-nbd):

```bash
qemu-nbd --read-only --persistent --shared=2 \
  --bind=<PEER_TB_OR_LAN_IP> --port=10809 --format=raw \
  /dev/nvme0n1
```

Option 2 — if the peer is also Unraid with this plugin: **section 3 → Host this disk on the network** there.

### On this Unraid (pull to a file)

1. Network Services → NBD → **section 4**  
2. NBD URL: `nbd://<peer-ip>:10809`  
3. Output: `/mnt/cache/…/name.qcow2` (must be a **file**, never `/dev/…`)  
4. Format: qcow2 → **Pull remote disk to file**  
5. When **Done**, peer stops hosting  

### Why this pattern

`qemu-img convert` from a **seekable** NBD source can skip zeros → smaller sparse qcow2. Good for multi-TB system disks.

---

## Scenario B — This Unraid has the disk; peer pulls the image

**Goal:** Unassigned disk on Unraid; another machine runs convert.

1. On Unraid **section 3**: Device, bind IP, port, **Read-only = Yes** → **Host this disk on the network**  
2. Section 2 shows it **Listening** with `nbd://IP:port`  
3. On the peer:

```bash
qemu-img info nbd://<unraid-ip>:port
qemu-img convert -p -f raw -O qcow2 -t writeback -W \
  nbd://<unraid-ip>:port /path/to/out.qcow2
```

4. On Unraid section 2: **Stop listener**

---

## Scenario C — Both ends are Unraid

1. **Source Unraid section 3:** Host the disk (RO).  
2. **Destination Unraid section 4:** Pull `nbd://source-ip:port` → `/mnt/cache/…/disk.qcow2`  
3. Source section 2: Stop when the job finishes  

Same plugin, swapped roles.

---

## Scenario D — Thunderbolt bulk imaging

1. [Thunderbolt Net](https://github.com/ibigsnet/ThunderboltNet) so both sides have TB IPs.  
2. Section 3: bind the host to the **Thunderbolt** address.  
3. Section 4 (or peer) pulls over that IP.  
4. Do **not** use Wi‑Fi for multi-TB images.

Thunderbolt “Unraid services / listening” (SMB/NFS/web) is **independent** of NBD.

---

## Scenario E — Local AI / dataset disk archive

**Goal:** Archive a whole data volume as one qcow2 on Unraid.

1. RO host on the volume (peer section 3 / qemu-nbd, or this Unraid).  
2. Section 4 pull to `/mnt/cache/…` or a large share.  
3. Keep Destructive mode **Off** unless you knowingly need array/mounted devices.  
4. Stop hosting; verify with `qemu-img check`.

Day-to-day model files still belong on **SMB/NFS**.

---

## Scenario F — What not to do

| Don’t | Do instead |
|-------|------------|
| Leave a writable NBD on the LAN “for convenience” | RO + stop when done |
| Export array parity “to see if it works” | Unassigned disk; Destructive mode only if you must |
| Section 4 output `/dev/sda` | Always a **file** under `/mnt/` |
| Bind `0.0.0.0` with WAN exposure | Specific private IP only |
| Expect hosting to show up in Windows Explorer as a share | Use SMB for files; NBD is for block tools / qemu-img |

---

## UI map (numbered sections)

| Section | Meaning |
|---------|---------|
| **1 · Plugin settings** | Enable, defaults, Destructive mode — **Apply** saves only |
| **2 · Disks currently hosted** | Live servers on this Unraid — **Stop** when done |
| **3 · Host a local Unraid disk** | Publish local `/dev` blocks on the network (server) |
| **4 · Pull a remote disk to a file** | Save someone else’s NBD host into `/mnt/…` (client) |
| **5 · CLI reference** | Same flows without the UI |

---

## After you’re done

1. Confirm pull job **Done** / `qemu-img check` if needed  
2. **Stop** hosting on the export host (section 2)  
3. Set **Destructive mode = No** if you turned it on  
4. Optional: put serial/model in the filename or a sidecar text file  

---

## Related docs

| Doc | Topic |
|-----|--------|
| [when-to-use-nbd.md](when-to-use-nbd.md) | Why NBD vs SMB/NFS; scenarios overview |
| [imaging-workflow.md](imaging-workflow.md) | CLI golden path + restore |
| [security-and-bind.md](security-and-bind.md) | Destructive mode, bind IP |
| [nbd-vs-nfs-smb.md](nbd-vs-nfs-smb.md) | Decision table |
| [../DOCS.md](../DOCS.md) | Install, overview, uninstall |
