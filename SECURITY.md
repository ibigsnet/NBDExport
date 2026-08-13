# Security / CA review notes — NBD Export

Copyright (c) 2026 ibigs, LLC · Author: RifleJock · License: GPL-3.0-or-later

## Privilege model

- Plugin runs as root (Unraid plugin model).
- Can start `qemu-nbd` against block devices and run image jobs (`qemu-img`).
- Does **not** modify `network.cfg`, Thunderbolt Net, or Fabric Routing.
- Does **not** reformat disks or change array membership.

## Defaults (safe until the user opts in)

| Setting | Default | Meaning |
|---------|---------|---------|
| `default_read_only` | **yes** | Host exports are RO |
| `allow_bind_all` | **no** | No `0.0.0.0` without explicit allow |
| `destructive_mode` | **no** | No RW host / array-mounted sources |
| `rehydrate_on_start` | **no** | No auto re-export on array start |

NBD has no built-in auth — treat bind IP as the primary isolation control. Prefer Thunderbolt or private LAN IPs.

## Uninstall

- Stops **managed** exports/jobs only (pid files under `/var/run/nbdexport` — not global `pkill qemu-nbd`).
- Removes emhttp tree and flash plugin state.
- Leaves user image files under `/mnt/` untouched.

## Install / update supply chain

- PluginURL (Latest): `https://raw.githubusercontent.com/ibigsnet/NbdExport/stable/install.plg`
- FILE sources: GitHub `stable` branch
- Development on `main`; store users use `stable`

## What to read (5 minutes)

1. `install.plg` — install / Method=remove
2. `default.cfg` — defaults above
3. `include/nbd-lib.php` — export flags, bind, destructive gates
4. `docs/security-and-bind.md`, `docs/destructive-mode.md`
5. This file

## Contact

- Support: GitHub issues (forum thread when published)
- Project: https://github.com/ibigsnet/NbdExport
