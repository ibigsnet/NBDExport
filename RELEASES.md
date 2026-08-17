# NBD Export — install & releases

## Install

### Community Applications (recommended)

1. Unraid **Apps** → search **NBD Export**
2. **Install** or **Update**
3. Hard-refresh the browser, then **Settings → Network Services → NBD**

CA is fed from [unraid-templates](https://github.com/ibigsnet/unraid-templates). Updates may lag a short time after a GitHub push.

### Manual install (raw plugin URL)

**Plugins → Install Plugin** → paste a **raw** URL ending in `.plg`:

| Channel | Use when | URL |
|---------|----------|-----|
| **Production (`stable`)** | Normal install / CA channel | `https://raw.githubusercontent.com/ibigsnet/NBDExport/stable/nbd.plg` |
| **Lab (`main`)** | Newest development tree | `https://raw.githubusercontent.com/ibigsnet/NBDExport/main/nbd.plg` |
| **Recommended freeze** | Known-good pin | `https://raw.githubusercontent.com/ibigsnet/NBDExport/stable-recommended-2026.08.17aa/nbd.plg` |
| **Pinned version** | Install or roll back to a fixed tag | `https://raw.githubusercontent.com/ibigsnet/NBDExport/vVERSION/nbd.plg` |

- **`stable`** — what CA installs; production updates.
- **`main`** — lab only; can be ahead of CA.
- **Tags / freezes** — exact trees that never change.

### Recommended freeze

| | |
|--|--|
| **Version** | **2026.08.17aa** |
| **Tag** | [`stable-recommended-2026.08.17aa`](https://github.com/ibigsnet/NBDExport/releases/tag/stable-recommended-2026.08.17aa) (also `v2026.08.17aa`) |
| **Install** | `https://raw.githubusercontent.com/ibigsnet/NBDExport/stable-recommended-2026.08.17aa/nbd.plg` |

Host / Pull / Settings tabs, Destructive mode default Off, Thunderbolt-first bind list.

### After install

- **Host** a disk (read-only by default) on one or more private bind IPs.
- **Pull** or attach as a client with `nbd://IP:port`.
- Docs: [DOCS.md](DOCS.md) · [security and bind](docs/security-and-bind.md)

### Roll back

Paste a freeze or `vVERSION` raw `.plg` URL under **Plugins → Install Plugin**, then hard-refresh.

---

## Version numbers

Plugin versions look like `2026.08.14ay` (date + two-letter suffix). Unraid compares them as plain strings for “update available.”

User-facing history is also in the plugin **Plugins** page changelog and [GitHub Releases](https://github.com/ibigsnet/NBDExport/releases) when published.

---

## Links

| | |
|--|--|
| **GitHub** | https://github.com/ibigsnet/NBDExport |
| **Forum support** | https://forums.unraid.net/topic/200219-plugin-nbd-export-host-disks-over-network-block-device-image-to-qcow2raw/ |
| **Docs** | [DOCS.md](DOCS.md) · [docs/](docs/) |
| **Thunderbolt Net** (optional underlay) | https://github.com/ibigsnet/ThunderboltNet |
