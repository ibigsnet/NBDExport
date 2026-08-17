## 2026.08.17aa

- **Audit harden:** UD HeadInline inject only when overlay enabled; marker + stock backup; install no longer always patches layout.

# Changelog — NBDExport

User-facing history for this plugin. The `.plg` file (Community Applications / Plugins page) shows only the **most recent releases**; this file is the complete record.

**Install channels:** production/CA uses branch `stable`; lab uses `main`. See [RELEASES.md](RELEASES.md).

---

###2026.08.16aa
- **Install/upgrade hygiene:** prepare always `removepkg`s prior `NBDExport-*` / legacy
  `NbdExport-*` packages and wipes emhttp plugin dirs before the new `.txz`. Prevents mixed
  leftover files across `YYYY.MM.DDxx` package names.

###2026.08.15ai
- Companions footer: card-style Thunderbolt Net + Multi-hop (FRR) wording aligned with Thunderbolt Net (Not installed / CA or raw .plg).

###2026.08.15ah
- Uninstall: document full wipe of plugin flash tree; do not touch Unraid plugins-removed; leave user export JSON under /boot/config/nbdexport-config-*.json.

###2026.08.15ad
- Changelog: Plugins page shows recent entries only; full history on GitHub <code>CHANGELOG.md</code>.

###2026.08.15aa
- **Pull:** Scan network → <strong>Stop scanning</strong> (distinct color) while a scan is in progress.

###2026.08.14az
- **Host:** Listen port shows default <strong>10809</strong> in blue when the field is empty (Unraid placeholder).

###2026.08.14ay
- **Host:** multi-network bind via checkboxes (one <code>qemu-nbd</code> per selected IP, same disk/port).

###2026.08.14ax
- **Docs:** public README/docs hygiene — no lab hostnames; private notes stay local.

###2026.08.14aw
- **Pull:** warn (does not block) when output extension mismatches format
  (e.g. qcow2 + <code>.img</code>, raw + <code>.qcow2</code>); live hint + confirm.

###2026.08.14av
- **UD overlay:** no empty reserved slots on unhosted disks (stock layout restored);
  badges only on active Hosts, absolutely positioned to avoid column reflow.

###2026.08.14au
- **UD overlay:** fixed-width disk-row NBD RO/RW slots (no table flicker/shift on UD
  refresh); update in place instead of remove/re-add.

###2026.08.14at
- **UI:** live status updates badges in place (Active→Failed/Stopped) without full page
  reload — Stop/Cancel controls stay usable while jobs finish or die.

###2026.08.14as
- **UI:** writable caveat banner only when Destructive is Off and RW hosts remain;
  emergency stop button stacked under the text (not inline).

###2026.08.14ar
- **Fix:** auto-refresh on Pull fail / Host stop — server snapshot + Status tab watcher (was missing). qemu-img deaths show <strong>Failed</strong> not Idle.

###2026.08.14aq
- **UD overlay:** reliable Main → Unassigned Devices integration — disk-row
  <strong>NBD RO/RW</strong> pills plus an <strong>NBD Hosts</strong> panel under
  SMB/NFS/ISO shares (local Host exports, not mounts). Opt-in still required.

###2026.08.14ap
- **Security UX:** emergency <strong>Stop all writable hosts</strong> (and Stop all) on every tab
  while RW is listening. Destructive Off still allows already-running writable Hosts
  (documented caveat); settings flash warns if RW is still up.

###2026.08.14ao
- **UI:** auto-refresh NBD pages when a Host goes Stopped/stale or a Pull job finishes /
  fails (including qemu-img errors that used to look “Idle”). Polls live status while
  exports/jobs are active.

###2026.08.14an
- **Fix:** Unassigned Devices badges only annotated the first disk row (JS row-dedupe bug);
  <strong>NBD RO</strong>/<strong>NBD RW</strong> now appear on the correct hosted device
  (e.g. Dev 2 / nvme1n1).

###2026.08.14am
- **Opt-in Unassigned Devices badges:** Settings → show small <strong>NBD RO</strong> /
  <strong>NBD RW</strong> lettering on Main → Unassigned Devices for hosted disks.
  Default Off. Best-effort DOM overlay (UD owns that page). See
  <code>docs/integration-unassigned-devices.md</code>.

###2026.08.14al
- **Security docs:** expanded <code>SECURITY.md</code> (CA review / threat model),
  <code>docs/hosting-safety.md</code> Host checklist, bind isolation guide.

###2026.08.14ak
- **UI:** host export blue badge is <strong>Active</strong> (was “Starting…”) so a live
  process is not mistaken for still waiting to begin.

###2026.08.14aj
- **Scan fix:** private <strong>routes</strong> (not only local iface prefixes), optional
  <code>scan_extra_subnets</code>, and remembered peer IPs for cross-subnet discovery.

###2026.08.14ai
- **Discovery:** Pull tab <strong>Scan network</strong> — private LAN probe for NBD ports (10809+) and
  peer beacons (10808). Host auto-starts a private-only JSON beacon while exports are up.
- Docs: <code>docs/discovery.md</code>, <code>docs/client-attach.md</code> (live VM / nbd-client patterns).

###2026.08.14ah
- **Support:** Unraid forum topic is the primary support URL (Plugins / CA Support).
- **Fix:** ship non-empty <code>DOCS.md</code> (empty blob on main caused <code>plugin install</code> zero-length failure).

###2026.08.14ag
- **Rename:** product **NBD Export**, plugin id **NBDExport**, PluginURL `nbd.plg` (was NbdExport / install.plg).
- UI menu title stays **NBD** under Network Services. CA Name: NBD Export.
- Migrate flash/emhttp from legacy `NbdExport` paths on install; remove old plg basenames.

###2026.08.14ab
- **Lab channel:** On branch `main`, PluginURL + raw FILE sources point at `main` (lab uninstall/reinstall testing).
- Branch `stable` remains the CA/production pin Production channel is branch `stable` (CA PluginURL).

###2026.08.14aa
- **License:** GNU GPLv3 or later (copyright ibigs, LLC; Author: RifleJock).
- **Release channel:** PluginURL + raw sources on branch `stable` (install.plg remains Latest URL).
- SECURITY.md: RO default, no bind-all, destructive off, managed-pid uninstall.

###2026.08.13ae
- Fleet standard ibigsGotoNetTab (same as af).

###2026.08.13ad
- Companion Network Settings tab links; `install.plg` as Latest after empty CDN on bare `nbdexport.plg`.

###2026.08.13ac
- Companion tab links; pluginURL `?v=` attempt.

###2026.08.13ab
- Companion tab links; restore DOCS after empty-file mishap.

###2026.08.13aa
- Companion tab-link fix; plg body restored.

###2026.08.13
- (superseded) companion tab-link fix.

###2026.08.11bm
- UI: drop redundant hosted-disks lead line; empty state is enough.
- UI: remove meta “Always visible” note from hosted-disks header; public copy only.
- Docs: Destructive mode wording — NBD block protocol, not imaging-only; mounted case less qcow2-centric.
- Docs/UI: Destructive case 2 = array/parity/cache/pools; case 4 = Unraid boot device (USB or disk holding /boot).
- Docs: destructive-mode.md — four Host cases only, bullet points.
- Docs: NBD is not qcow2-only — raw/.img and other qemu-img targets; formats table.
- Docs: one NVMe slot scenario — prepare image on Unraid, write new drive in a dock, swap once.
- Docs: restore how-to-use.md (full scenarios A–F; prior empty file on main).
- Docs: Wi‑Fi balance — solid private Wi‑Fi OK for smaller jobs; single stream + sparse qcow2 + re-Pull while host stays up; still ordinary TCP (no special resume).
- Docs: when-to-use — common scenarios (laptop→VM, gaming PC→array, recovery, reverse to physical).
- Docs: when-to-use opener — local physical disk feel, over the network.
- Docs: rename “If you click this…” to “What each control does”.
- Docs: Contents/TOC on large pages (how-to-use, DOCS, destructive-mode, …).
- Docs: destructive-mode.md — explicit when to enable Destructive mode.
- Docs/UI: spell out read-only (not RO); table entry not row — avoid RO/row confusion.
- Docs: Scenario E — clear BTRFS snapshot recipe only (public tone).
- Docs: quirky Thunderbolt vs 10G speed notes (Thunderbolt 4 ~20G each way ≈ 2× 10G NIC) in how-to / when-to / integration.
- Docs: Scenario E — cold physical-disk qcow2 on Unraid + honest BTRFS snapshot versioning.
- Docs: Scenario C wording — Host where disk plugs in (may lack free space); Pull qcow2 onto roomy Unraid/array.
- Docs: Host wording — raw blocks visible over the network.
- Docs: disambiguate Thunderbolt — Thunderbolt vs multi-terabyte (spell out both).
- Docs: Scenario C expanded — Host NVMe on easy-access Unraid, Pull qcow2 on the box with space.
- Docs: how-to-use tables rewritten for left→right reading; UI map matches tabs (not old section 3/4).
- Wording: Destructive confirm clarifies writable = peer writes selected Unraid disk; array/mounted/flash = host in-use/critical devices.
- Shared chrome: hosted disks + orange Destructive banner on every tab; NBD blurb footer; CLI dropdown on Status.
- Tabs: header-only parent (no blank first tab, like Network Settings/SMB); center tab strip; text tabs.
- UI tabs (Unraid xmenu): Status · Host · Pull · Settings — like Network Settings / SMB strip.
- Multi-disk hosting documented (already supported; free port prefill); CLI under Status as collapsible.

###2026.08.11ak
- Pull output placeholder: `/mnt/user/domains/disk.qcow2` (Unraid VM share default) instead of `/mnt/cache/images/…`.

###2026.08.11aj
- Export config: download JSON or save under /boot/config/ (outside plugin dir); short note that uninstall wipes plugin flash state; optional import from path.
- Keeps auto last-used + named presets on flash as before.

###2026.08.11ai
- Section titles: host local disk/partition on network vs pull remote NBD into a file under /mnt.

###2026.08.11ah
- Section 2 colored empty state + status legend; remember last host/pull fields; named presets save/restore on flash.

###2026.08.11ag
- Wording: section 3 host local Unraid disk/partition on network; section 4 pull remote hosted disk into a file under /mnt.

###2026.08.11af
- Uninstall: stop managed pids only (no global pkill qemu-nbd); clear /var/log/nbdexport and case-variant paths.

###2026.08.11ae
- UI: numbered sections with full-width dividers; action bars under each block so listener vs image job are visually separate.

###2026.08.11ad
- UX/docs: “Start NBD listener” vs image job (server/client); how-to-use guide with scenarios; clearer helpers and status wording.

###2026.08.11ac
- Safety: Destructive mode (default Off) like Unassigned Devices — blocks writable and array/mounted/flash exports; UI confirms; image jobs refuse /dev output.

###2026.08.11ab
- UI: layout unified with FabricRouting/Thunderbolt Net (dl/dt/dd, inline_help, companion strip, status table, Apply/Done).

###2026.08.11aa
- Docs/UI: Unraid product min 6.12 vs qemu tools (not kernel); clearer Status missing-tool text.

###2026.08.11
- Initial public release: Network Services → NBD, RO qemu-nbd export, image job (nbd:// → qcow2/raw), bind IP picker (Thunderbolt first), docs (when to use, vs NFS/SMB, security).
- Soft companions: Thunderbolt Net (prefer Thunderbolt bind), FabricRouting (docs only). Clean uninstall stops exports; does not touch network.cfg.
