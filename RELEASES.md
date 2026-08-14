# Releases

## Version strings

Unraid plugin updates use lexicographic `strcmp()` (not PHP `version_compare`).

| Form | Meaning |
|------|---------|
| `YYYY.MM.DD` | First ship that calendar day (lab wall clock) |
| `YYYY.MM.DDaa` | Further ships same day (`ab` … `az`, `ba`, …) |

- No hyphens. After the bare date, **two-letter** suffixes only (never single `a`–`z`).
- Bump only `<!ENTITY version "…">` in the `.plg`; assets use `?v=&version;`.
- Add a `###&version;` entry under `<CHANGES>` in the same ship.
- Versions only move forward for existing installs (`strcmp`); do not rewind a mistaken future date.

### Cross-plugin UI links (fleet standard)

| Do | Don’t |
|----|--------|
| `/Settings/NetworkSettings` + `ibigsGotoNetTab('Thunderbolt')` | `/Settings/ThunderboltNet` |
| `/Settings/NetworkSettings` + `ibigsGotoNetTab('Fabric Routing')` | `/Settings/FabricRouting` |

Canonical JS: **`ibigsGotoNetTab(needle, event)`** (aliases: `nbdGotoNetTab`, `tbnGotoNetTab`, `frrGotoNetTab`).  
NBD itself lives under **Network Services** (`/Settings/NBDExport`) — that path is correct for opening NBD, not a Network Settings tab.

## Support

| | |
|--|--|
| **Unraid forum (support)** | https://forums.unraid.net/topic/200219-plugin-nbd-export-host-disks-over-network-block-device-image-to-qcow2raw/ |
| **GitHub** | https://github.com/ibigsnet/NBDExport |
| **CA Support** | Same forum URL in [unraid-templates `plugins/nbd.xml`](https://github.com/ibigsnet/unraid-templates/blob/main/plugins/nbd.xml) |

## Install URLs

| Track | URL |
|-------|-----|
| **Latest (`main`)** | `https://raw.githubusercontent.com/ibigsnet/NBDExport/stable/nbd.plg` |
| **Recommended freeze** | `https://raw.githubusercontent.com/ibigsnet/NBDExport/stable-recommended-2026.08.13aj/nbd.plg` |
| **Pinned version tag** | `https://raw.githubusercontent.com/ibigsnet/NBDExport/vVERSION/nbd.plg` |

Same body is also published as `nbd.plg` (keep identical on every ship). CA `PluginURL` tracks Latest (`nbd.plg`).

### Recommended freeze (2026-08-13)

| | |
|--|--|
| **Label** | **Recommended** (fleet freeze) |
| **Plugin version** | **`2026.08.13aj`** |
| **Tag** | [`stable-recommended-2026.08.13aj`](https://github.com/ibigsnet/NBDExport/releases/tag/stable-recommended-2026.08.13aj) |
| **Also** | `v2026.08.13aj` |
| **Install / rollback** | `https://raw.githubusercontent.com/ibigsnet/NBDExport/stable-recommended-2026.08.13aj/nbd.plg` |

Includes Host/Pull/Settings tabs, Destructive mode, companions (Thunderbolt Net / Fabric Routing), CA-safe PluginURL, Thunderbolt wording. **`main` may move ahead** after this pin.

## History

### 2026.08.11ad

- Clearer UX: Start NBD listener (server) vs image job (client); how-to-use.md with scenarios.

### 2026.08.11ac

- Destructive mode (default Off): server + UI guards against accidental writable/array exports; image jobs cannot target block devices.

### 2026.08.11ab

- Settings page layout aligned with FabricRouting / Thunderbolt Net (forms, help panels, companion strip).

### 2026.08.11aa

- Clarify Unraid product min vs qemu-nbd/qemu-img tools (not Linux kernel version).

### 2026.08.11

- Initial public release: Network Services → NBD UI  
- read-only `qemu-nbd` export with bind IP picker (Thunderbolt first)  
- Background `qemu-img convert` image jobs  
- Docs: when to use, vs NFS/SMB, security, imaging, Thunderbolt/FRR integration  
