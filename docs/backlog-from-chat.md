# NBD Export — backlog from chat (2026-08-26)

Inventory of items raised in this session arc. Status is relative to tip **`2026.08.26ar`** (testing/main may lag).

Legend: **Done** · **Partial** · **Open** · **Won’t** (decided against)

---

## P0 — still broken / user-visible now

| # | Item | Status | Notes |
|---|------|--------|-------|
| D1 | **Dashboard header stretch** — full-width “NBD Export” bar; Dashboard looks wrong | **Fixing in 26as** | Replaced dual-row `tile-header` with stock single-cell icon+section (like Array/Docker). Verify after update. |
| D2 | **Dashboard slows Main → Dashboard load** | **Fixing in 26as** | No Loading… flash; `requestIdleCallback` defer; no external `ps` scan on dash poll; idle poll 15s / active 5s. |
| D3 | **In-flight jobs keep old wrapper** after update | **Partial** | UI/%/ETA fixes apply after Pause→Update; hist/poll improvements need **new** jobs. Live PLUSH/NIROG converts may still show 0%/no ETA until Retry. |
| D4 | **Browser verify** Dashboard + Status after ships | **Open** | House rule: verify in browser; often shipped without exercising Dashboard end-to-end on a box. |

---

## P1 — discussed, shipped in spirit, still rough

| # | Item | Status | Notes |
|---|------|--------|-------|
| P1 | Progress % / ETA sticky `ETA…` / 0% vs log | **Partial** | `26ar`: max(sidecar,raw,log); hide ETA until move. Soak on live multi-TiB pulls still needed. |
| P2 | Pause/Stop button height mismatch | **Partial** | `26ar` CSS + `inline-flex`. Confirm after hard-refresh. |
| P3 | Status / Dashboard formatting (cards, metrics, sections) | **Partial** | Tip3 shipped Active/Queued/History; Dashboard paths full+wrap as of `26at` (no hard truncate). |
| P4 | Units: GB/TB, Mb/s / MB/s, Gb/s ≥ 1G | **Done** | Shipped; confirm Dashboard uses same helpers. |
| P5 | Failure Reason map + Found a bug? | **Done** | `26an`/`26ao`. |
| P6 | Logs tab + History clear keeps logs | **Done** | `26ap`/`26aq`. |
| P7 | Help prose / Grok tip bleed | **Partial** | History/Logs cleaned `26aq`; Status lead still has some narrative; Dashboard none. |

---

## P2 — approved plan Tips (Status/Dashboard UX)

Approved Tips 1–3 were largely shipped through `26ah`–`26an`. Gaps:

| # | Item | Status | Notes |
|---|------|--------|-------|
| T1 | Tip1 metrics/progress/busy refuse copy | **Done** | Busy refuse + louder message. |
| T2 | Tip2 clear/retry/delete | **Done** | Clear=list; Retry auto same-path delete; Delete image. |
| T3 | Tip3 sections/queue reorder ↑↓ | **Done** | Active/Queued/History; queue_seq. |
| T4 | Purple Pause vs orange Running | **Done** | |
| T5 | Lazy Dashboard (no `<style>` before tbody) | **Partial** | Shell is tbody-only + async JSON, but UX (D1/D2) still bad. |
| T6 | Select failed / checkbox toolbar wording | **Done** | |
| T7 | Full paths on Status cards | **Done** | Dashboard still shortens. |

---

## P3 — explicitly deferred / optional (called out, not built)

| # | Item | Status | Notes |
|---|------|--------|-------|
| O1 | **Free-space vs virtual-size warn** before Pull | **Open** | ENOSPC on `/mnt/user` discussed; JS warn partial; no preflight “need X TiB free”. |
| O2 | Dashboard **toggle** (Settings: show/hide tile) | **Open** | “optional later UI polish / Dashboard toggle”. |
| O3 | Mid-pull **true byte-resume** | **Won’t** (for now) | Locked: Pause only; Stop = delete + re-Pull. |
| O4 | Reattach progress watcher after busy Allow/FORCE upgrade | **Open** | Old wrapper not hot-replaced; new %/ETA for new jobs only. |
| O5 | Auto-prune Logs by age/size | **Open** | Clear is manual only. |
| O6 | Combined rolling `nbdexport-history.log` | **Open** | Chose keep per-job files instead. |
| O7 | Plugin rename away from “NBD Export” | **Won’t** | Keep name; widen descriptors. |

---

## P4 — ops / soak (not code)

| # | Item | Status | Notes |
|---|------|--------|-------|
| S1 | Soak **26ar** (or tip) on NIROG with live dual Pull | **Open** | Pause→Update; confirm %/ETA/buttons/Logs. |
| S2 | Promote NBD tip → **main** after soak | **Partial** | `26aq` was on main; `26ar` testing-only until asked. |
| S3 | Residual orphan cleanup on PLUSH if still writing | **Open** | May be clean after earlier kill. |
| S4 | Forum install URLs `stable`→`main` (user-side) | **Open** | User acknowledged; not agent-owned. |
| S5 | Do not install/update on **HoloX3D** unbidden | **Policy** | Still in force. |

---

## Next action (this pass)

1. **D1 + D2** — rebuild Dashboard tile to stock single-row layout; kill header stretch; cheaper/faster first paint (no Loading flash; lighter poll).
2. Then re-check P1/P2 on a hard-refresh after ship.
3. Park O1 (free-space warn) as next product tip unless you reprioritize.
