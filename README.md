# NBD Export

Unraid plugin: export a disk or partition as a **Network Block Device** (NBD), and pull remote `nbd://` targets to **qcow2** or **raw** (`.img`) under `/mnt/…`.

**UI:** Settings → Network Services → **NBD** (Status · Host · Pull · Settings)

Read-only Host by default. Prefer a private bind address (Thunderbolt Net when available). Thunderbolt Net and Fabric Routing are optional companions—not required.

| | |
|--|--|
| **Install (CA / stable)** | `https://raw.githubusercontent.com/ibigsnet/NBDExport/stable/nbd.plg` |
| **Development** | branch `main` |
| **Docs** | [DOCS.md](DOCS.md) · [docs/](docs/) |
| **Security** | [SECURITY.md](SECURITY.md) · [docs/hosting-safety.md](docs/hosting-safety.md) |
| **Support** | [Unraid forum](https://forums.unraid.net/topic/200219-plugin-nbd-export-host-disks-over-network-block-device-image-to-qcow2raw/) |
| **License** | GNU GPLv3 or later · copyright ibigs, LLC · Author: RifleJock |

See [LICENSE](LICENSE).
