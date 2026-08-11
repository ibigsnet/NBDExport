# Troubleshooting

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

## Slow throughput

- Wi‑Fi is the wrong medium for multi-terabyte images  
- Prefer Thunderbolt host-net or 10G+; raise MTU on **both** ends if appropriate (Thunderbolt Net docs)  
- Trained Thunderbolt rate ≠ TCP; expect less than sticker Gb/s  

## Blank first tab / old “section 3” docs

UI is **tabs** (Status · Host · Pull · Settings), not numbered sections. Update the plugin and hard-refresh. Docs live in this repo under `docs/`.

## Uninstall left something behind

Remove stops hosts and deletes plugin trees. Your **qcow2 files under `/mnt/` are kept**. Manually remove `/var/log/nbdexport` if desired. Export config under `/boot/config/nbdexport-config-*.json` is kept if you saved outside the plugin dir.
