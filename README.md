**NBD Export**

Export a disk or partition as a **Network Block Device** (NBD) and pull remote NBD targets to **qcow2** or **raw** (`.img`) on Unraid — NBD carries blocks; the file format is your choice. **Settings → Network Services → NBD** (tabs: Status · Host · Pull · Settings). Prefer private binds (Thunderbolt Net IPs when available). Read-only by default. Standalone — Thunderbolt Net and Fabric Routing (FRR) are optional companions.

**Security:** read-only Host by default; no `0.0.0.0` bind unless allowed; Destructive mode off for everyday use. See [SECURITY.md](SECURITY.md) and [docs/hosting-safety.md](docs/hosting-safety.md).

## License

GNU General Public License v3.0 or later — copyright **ibigs, LLC** (Author: RifleJock). See [LICENSE](LICENSE) and [SECURITY.md](SECURITY.md).

## Install channel

**Production / CA:** `https://raw.githubusercontent.com/ibigsnet/NBDExport/stable/nbd.plg`  
**Development:** `main`. Ship via merge to `stable`.
