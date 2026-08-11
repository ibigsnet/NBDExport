# Troubleshooting

## qemu-nbd / qemu-img missing

Enable Unraid’s VM-related packages so `qemu-nbd` and `qemu-img` appear under `/usr/bin`. The Status table on the NBD page shows detected paths.

## Export fails immediately

- Check bind IP is assigned (`ip -4 addr`)  
- Port already in use → pick another port  
- Device path missing (VFIO-bound disks are invisible to the host)  
- See `/var/log/nbdexport/*.log`

## Peer cannot connect

- Firewall / wrong bind IP (export only listens on that address)  
- No route to bind IP (bring up Thunderbolt Net or correct VLAN)  
- Export stopped or crashed  

Probe: `qemu-img info nbd://IP:PORT`

## Image job stuck / failed

- Export not running or wrong URL  
- Destination disk full  
- Path not under `/mnt/`  
- Check job log tail on the NBD page or `/var/log/nbdexport/job-*.log`

## Slow throughput

- Wi‑Fi is the wrong medium for multi-TB images  
- Prefer Thunderbolt host-net or 10G+; raise MTU on **both** ends if appropriate (Thunderbolt Net docs)  
- Trained TB rate ≠ TCP; expect less than sticker Gb/s  

## Uninstall left something behind

Remove stops exports and deletes plugin trees. Your **qcow2 files under `/mnt/` are kept**. Manually remove `/var/log/nbdexport` if desired.
