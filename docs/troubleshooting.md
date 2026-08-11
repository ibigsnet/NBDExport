# Troubleshooting


## Contents

- [qemu-nbd / qemu-img missing](#qemu-nbd-qemu-img-missing)
- [Host fails immediately](#host-fails-immediately)
- [Peer cannot connect](#peer-cannot-connect)
- [Pull job stuck / failed](#pull-job-stuck-failed)
- [Slow throughput](#slow-throughput)
- [Blank first tab / old “section 3” docs](#blank-first-tab-old-section-3-docs)
- [Uninstall left something behind](#uninstall-left-something-behind)

## qemu-nbd / qemu-img missing

These are **userspace tools** (usually with Unraid’s VM stack), not a Linux kernel version gate and not “Unraid 7.2+.”  
Enable/install VM-related components so `qemu-nbd` and `qemu-img` appear under `/usr/bin`. The **Status** tab shows detected paths.

## Host fails immediately

- Check bind IP is assigned (`ip -4 addr`)  
- Port already in use → pick another port (multi-disk needs unique ports)  
- Device path missing (VFIO-bound disks are invisible to the host)  
- Destructive mode Off while selecting array/mounted/flash or writable  
- See `/var/log/nbdexport/*.log`

## Peer cannot connect

- Firewall / wrong bind IP (host only listens on that address)  
- No route to bind IP (bring up Thunderbolt Net or correct VLAN)  
- Host stopped or crashed  

Probe: `qemu-img info nbd://IP:PORT`

## Pull job stuck / failed

- Host not running or wrong URL  
- Destination disk full  
- Path not under `/mnt/` (or `/tmp/`)  
- Check job log on **Status** or `/var/log/nbdexport/job-*.log`

## Slow throughput or failed mid-pull

- Multi-terabyte jobs need **sustained** bandwidth and a link that stays up for hours — Thunderbolt host-net or 10G+ when you can  
- Solid private Wi‑Fi can work for smaller disks (single stream + sparse convert; re-Pull while the host is still up). Spotty/congested wireless often fails mid-`qemu-img convert` — ordinary TCP, no special resume  
- Prefer Thunderbolt host-net or 10G+ wired; raise MTU on **both** ends if appropriate (Thunderbolt Net docs)  
- Trained Thunderbolt rate ≠ full TCP — expect less than the sticker number  
- Reality check: Thunderbolt 4–class host-net often trains ~**20 Gbit/s each way** (not 40 each way), roughly **2× a 10 Gbit/s NIC** one-way — if you expected that class of speed, check bind IP (guest Wi‑Fi/br0 by mistake), cable, or CPU/storage on the Pull side  

## Blank first tab / old “section 3” docs

UI is **tabs** (Status · Host · Pull · Settings), not numbered sections. Update the plugin and hard-refresh. Docs live in this repo under `docs/`.

## Uninstall left something behind

Remove stops hosts and deletes plugin trees. Your **qcow2 files under `/mnt/` are kept**. Manually remove `/var/log/nbdexport` if desired. Export config under `/boot/config/nbdexport-config-*.json` is kept if you saved outside the plugin dir.
