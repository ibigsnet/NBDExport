# Releases

## Version strings (plugin / Unraid)

Unraid plugin updates use **lexicographic `strcmp()`**, not PHP `version_compare()`. Rules (same as Storage Guard / Thunderbolt Net / FabricRouting):

| Form | Meaning |
|------|---------|
| `YYYY.MM.DD` | First ship that calendar day |
| `YYYY.MM.DDaa` | 2nd ship same day, then `ab` … `az`, `ba`, `bb`, … |

**Hard rules:**

- No hyphens.  
- After the bare date, **two-letter** suffixes only — never single `a`–`z`.  
- Bump only `<!ENTITY version "…">` in `nbdexport.plg` / `install.plg` (keep both identical).  
- Add a `###&version;` block under `<CHANGES>` in the same ship.

### Cross-plugin UI links (fleet standard)

| Do | Don’t |
|----|--------|
| `/Settings/NetworkSettings` + `ibigsGotoNetTab('Thunderbolt')` | `/Settings/ThunderboltNet` |
| `/Settings/NetworkSettings` + `ibigsGotoNetTab('Fabric Routing')` | `/Settings/FabricRouting` |

Canonical JS: **`ibigsGotoNetTab(needle, event)`** (aliases: `nbdGotoNetTab`, `tbnGotoNetTab`, `frrGotoNetTab`).  
NBD itself lives under **Network Services** (`/Settings/NbdExport`) — that path is correct for opening NBD, not a Network Settings tab.

## Install URLs

| Track | URL |
|-------|-----|
| **Latest (`main`)** | `https://raw.githubusercontent.com/ibigsnet/NbdExport/stable/install.plg` |
| **Recommended freeze** | `https://raw.githubusercontent.com/ibigsnet/NbdExport/stable-recommended-2026.08.13aj/install.plg` |
| **Pinned version tag** | `https://raw.githubusercontent.com/ibigsnet/NbdExport/vVERSION/install.plg` |

Same body is also published as `nbdexport.plg` (keep identical on every ship). CA `PluginURL` tracks Latest (`install.plg`).

### Recommended freeze (2026-08-13)

| | |
|--|--|
| **Label** | **Recommended** (fleet freeze) |
| **Plugin version** | **`2026.08.13aj`** |
| **Tag** | [`stable-recommended-2026.08.13aj`](https://github.com/ibigsnet/NbdExport/releases/tag/stable-recommended-2026.08.13aj) |
| **Also** | `v2026.08.13aj` |
| **Install / rollback** | `https://raw.githubusercontent.com/ibigsnet/NbdExport/stable-recommended-2026.08.13aj/install.plg` |

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
