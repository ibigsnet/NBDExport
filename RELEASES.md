# Releases

## Version strings (plugin / Unraid)

Unraid plugin updates use **lexicographic `strcmp()`**, not PHP `version_compare()`. Rules (same as Storage Guard / Thunderbolt Net / UnraidFRR):

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
| `/Settings/NetworkSettings` + `ibigsGotoNetTab('Fabric Routing')` | `/Settings/UnraidFRR` |

Canonical JS: **`ibigsGotoNetTab(needle, event)`** (aliases: `nbdGotoNetTab`, `tbnGotoNetTab`, `frrGotoNetTab`).  
NBD itself lives under **Network Services** (`/Settings/NbdExport`) — that path is correct for opening NBD, not a Network Settings tab.

## Install URLs

```text
# Latest (preferred — install.plg mirrors nbdexport.plg)
https://raw.githubusercontent.com/ibigsnet/NbdExport/main/install.plg

# Same body (if raw CDN is healthy)
https://raw.githubusercontent.com/ibigsnet/NbdExport/main/nbdexport.plg
```

Keep **`install.plg` and `nbdexport.plg` identical** on every ship. CA `PluginURL` should track Latest (`install.plg`).

## History

### 2026.08.11ad

- Clearer UX: Start NBD listener (server) vs image job (client); how-to-use.md with scenarios.

### 2026.08.11ac

- Destructive mode (default Off): server + UI guards against accidental writable/array exports; image jobs cannot target block devices.

### 2026.08.11ab

- Settings page layout aligned with UnraidFRR / Thunderbolt Net (forms, help panels, companion strip).

### 2026.08.11aa

- Clarify Unraid product min vs qemu-nbd/qemu-img tools (not Linux kernel version).

### 2026.08.11

- Initial public release: Network Services → NBD UI  
- read-only `qemu-nbd` export with bind IP picker (Thunderbolt first)  
- Background `qemu-img convert` image jobs  
- Docs: when to use, vs NFS/SMB, security, imaging, Thunderbolt/FRR integration  
