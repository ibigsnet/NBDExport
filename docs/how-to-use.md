# How to use NBD Export

This guide explains **what the buttons do**, how the two roles work, and step-by-step scenarios.

Settings path: **Settings → Network Services → NBD**

---

## Mental model (two roles)

NBD is **not** a file share and **not** a background “service” like SMB that stays on forever by default.

```text
┌─────────────────────┐         TCP          ┌──────────────────────┐
│  EXPORT host        │  nbd://IP:port       │  IMAGE / client host │
│  (offers a disk)    │ ──────────────────►  │  (reads the disk)    │
│                     │                      │                      │
│  “Start NBD listener”                      │  “Start image job”   │
│  = qemu-nbd listening                      │  = qemu-img convert  │
│  on IP:port for /dev/xxx                   │  into a file on disk │
└─────────────────────┘                      └──────────────────────┘
```

| UI action | What actually runs | Role |
|-----------|-------------------|------|
| **Start NBD listener** | `qemu-nbd` process, bound to an IP and port | **Server** — shares one block device over TCP |
| **Stop listener** | Stops that `qemu-nbd` process | Server off — port closes |
| **Start image job** | Background `qemu-img convert` from `nbd://…` | **Client** — copies the remote disk into a **file** (qcow2/raw) |
| **Apply** (Settings) | Saves config only | Does **not** start a listener by itself |

**Enable NBD Export = Yes** only means the plugin is allowed to start listeners/jobs. It does **not** export any disk until you click **Start NBD listener**.

---

## What “Start NBD listener” is *not*

| Not this | Why |
|----------|-----|
| Starting Unraid’s SMB/NFS service | Different protocols; file shares |
| Mounting a share on the Dashboard | NBD does not create a share name |
| Permanently “adding” a disk to Unraid | Temporary process until you Stop |
| Imaging the disk by itself | Imaging is a **separate** client step (image job or peer CLI) |

Until a client connects, the listener just **waits** on the port (like a minimal disk server).

---

## Safe defaults checklist

1. **Destructive mode = No** (default)  
2. **Read-only = Yes** for the listener  
3. **Bind IP** = Thunderbolt or private LAN (not `0.0.0.0`)  
4. Disk is **unassigned / not array / not mounted** when possible  
5. When finished imaging: **Stop listener**

---

## Scenario A — Peer Linux has a disk; Unraid saves a qcow2 (most common lab path)

**Goal:** Copy a whole disk from a desktop/laptop into Unraid over a fast private link.

### On the peer (export host)

Option 1 — peer CLI (any Linux with qemu-nbd):

```bash
qemu-nbd --read-only --persistent --shared=2 \
  --bind=<PEER_TB_OR_LAN_IP> --port=10809 --format=raw \
  /dev/nvme0n1
```

Option 2 — if the peer is also Unraid with this plugin: **Start NBD listener** there.

### On this Unraid (image client)

1. Network Services → NBD  
2. **Image job** section:  
   - NBD URL: `nbd://<peer-ip>:10809`  
   - Output: `/mnt/cache/…/name.qcow2` (must be a **file**, never `/dev/…`)  
   - Format: qcow2  
3. **Start image job** — runs in the background (browser can close)  
4. When **Done**, tell the peer to stop their listener (or Stop on their Unraid)

### Why this pattern

`qemu-img convert` from a **seekable** NBD source can skip zeros → smaller sparse qcow2. Good for multi-TB Windows/Linux system disks.

---

## Scenario B — This Unraid has the disk; peer pulls the image

**Goal:** Unassigned disk (or RO export) on Unraid; another machine runs convert.

1. On Unraid: pick **Device**, **Bind IP**, port, **Read-only = Yes**  
2. Click **Start NBD listener**  
3. Status table shows **Up** and `nbd://IP:port`  
4. On the peer:

```bash
qemu-img info nbd://<unraid-ip>:port
qemu-img convert -p -f raw -O qcow2 -t writeback -W \
  nbd://<unraid-ip>:port /path/to/out.qcow2
```

5. On Unraid: **Stop listener**

---

## Scenario C — Both ends are Unraid

1. **Source Unraid:** Start NBD listener on the disk (RO).  
2. **Destination Unraid:** Image job → `nbd://source-ip:port` → `/mnt/cache/…/disk.qcow2`  
3. Source: Stop listener when the job finishes  

Same plugins, same buttons, swapped roles.

---

## Scenario D — Thunderbolt bulk imaging

1. Install/configure [Thunderbolt Net](https://github.com/ibigsnet/ThunderboltNet) so both sides have TB IPs (e.g. `10.255.0.1` / `.2`).  
2. Bind the NBD listener to the **Thunderbolt** address (listed first in Bind IP when present).  
3. Run Scenario A or B over that IP.  
4. Do **not** use Wi‑Fi for multi-TB images.

Listening **Yes** under Thunderbolt Net (SMB/NFS/web on TB) is **independent** of NBD. NBD always binds its own port.

---

## Scenario E — Local AI / dataset disk archive

**Goal:** Archive a whole data volume (or peer workstation disk) as one qcow2 on Unraid before reinstall or model-farm cleanup.

1. RO NBD listener on the volume (peer or Unraid).  
2. Image job to `/mnt/cache/…` or a large share.  
3. Keep Destructive mode **Off** unless the volume is array-backed and you know the risk.  
4. Stop listener; store/verify with `qemu-img check`.

Day-to-day model file access still belongs on **SMB/NFS**.

---

## Scenario F — What not to do

| Don’t | Do instead |
|-------|------------|
| Leave a writable NBD on the LAN “for convenience” | RO + stop when done |
| Export array parity “to see if it works” | Unassigned disk or RO with Destructive mode only if you must |
| Image job output `/dev/sda` | Always a **file** path under `/mnt/` |
| Bind `0.0.0.0` on a home LAN with WAN exposure | Specific private IP only |
| Expect Start listener to create a share in Windows Explorer | Use SMB for files; NBD is for block tools / qemu-img |

---

## UI map (button meanings)

### Settings

| Control | Meaning |
|---------|---------|
| Enable NBD Export | Master allow/deny for starting listeners and jobs |
| Default read-only | Pre-select for new listeners |
| Default port | Suggested TCP port (e.g. 10809) |
| Allow bind 0.0.0.0 | Dangerous; usually leave No |
| Destructive mode | Unlock writable / array / mounted exports (UD-style) |
| **Apply** | Save settings only |

### Active NBD listeners

Table of running `qemu-nbd` processes: device, bind, URL, RO flag.  
**Stop listener** ends that process.

### Start NBD listener (export a disk)

Creates a **new** `qemu-nbd` server for one device.  
Does not copy data by itself — clients must connect.

### Image job

**Client** that reads `nbd://…` and writes a file.  
Survives closing the browser. Does not start a listener.

---

## After you’re done

1. Confirm image job **Done** / `qemu-img check` if needed  
2. **Stop listener** on the export host  
3. Set **Destructive mode = No** if you turned it on  
4. Optional: note serial/model in the filename or a sidecar text file  

---

## Related docs

| Doc | Topic |
|-----|--------|
| [when-to-use-nbd.md](when-to-use-nbd.md) | Why NBD vs SMB/NFS; scenarios overview |
| [imaging-workflow.md](imaging-workflow.md) | CLI golden path + restore |
| [security-and-bind.md](security-and-bind.md) | Destructive mode, bind IP |
| [nbd-vs-nfs-smb.md](nbd-vs-nfs-smb.md) | Decision table |
| [../DOCS.md](../DOCS.md) | Install, overview, uninstall |
