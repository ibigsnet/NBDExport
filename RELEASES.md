# Releases

## Version strings (plugin / Unraid)

Unraid plugin updates use **lexicographic `strcmp()`**, not PHP `version_compare()`.

| Form | Meaning |
|------|---------|
| `YYYY.MM.DD` | First ship that **calendar day** |
| `YYYY.MM.DDaa` | 2nd ship same day, then `ab` … `az`, `ba`, `bb`, … |

### Calendar day (do not skip)

The date in the version string is the **lab wall-clock calendar day**, not UTC and not “yesterday’s line + 1”.

| Do | Don’t |
|----|--------|
| Read **lab host** date before bumping (`date` on Unraid; timezone **America/Chicago** for this fleet) | Use the agent/CI machine UTC date if it differs from lab |
| Use **today’s** date on that clock | Invent **tomorrow** (`…14` while lab is still the 13th) |
| Same calendar day → next **two-letter** suffix (`aa`, `ab`, …) | Jump the day number to “make room” for more ships |
| If a wrong future date already shipped, **stay on that line** for strcmp and note the mistake in CHANGES — do not rewind | Mint an older date after a newer one is installed (updates will not offer) |

**Historical miss:** bare `2026.08.14` / `14aa` / `14ab` were cut while lab was still **2026-08-13** (continued a day-ahead TBN line instead of checking lab `date`). Same class of bug as keeping letter suffixes on an old day (Storage Guard once had to “roll to calendar date”).

### Other hard rules

- No hyphens in the version string.
- After the bare date, **two-letter** suffixes only — never single `a`–`z` (strcmp treats `"aa"` as **older** than `"z"`).
- Bump **only** `<!ENTITY version "…">` in the `.plg`; asset URLs use `?v=&version;`.
- Add a `###&version;` block under `<CHANGES>` in the same ship.

### Pre-ship version checklist (agents + humans)

1. On lab: `date` → record `YYYY-MM-DD` in lab TZ (America/Chicago).
2. Read current `<!ENTITY version>` on the branch you ship.
3. Same lab date as version prefix → next two-letter suffix only.
4. Lab date newer → first ship that day = bare `YYYY.MM.DD` (if it sorts after current; else `…aa`).
5. Lab date older than a mistaken future version already out → **do not rewind**; continue suffixes on the shipped date.
6. Never set version by “latest string + one day” without looking at the lab clock.


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
