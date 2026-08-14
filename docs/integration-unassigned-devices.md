# Unassigned Devices integration (opt-in)

**Where:** Unraid **Main → Unassigned Devices** tab  
(URL like `http://tower/Main/UnassignedDevices` — the same place you see Dev 1 / Dev 2 disks, **not** UD Settings.)

**Default: Off.** When enabled, NBD Export overlays status on that page only (best-effort DOM; UD owns the page).

### 1) Disk row badge (Identification column)

On a disk that is currently **Hosted**:

`Samsung_… (nvme1n1)` **`NBD RO`** or **`NBD RW`**

| Badge | Meaning |
|-------|---------|
| **NBD RO** | Hosted **read-only** (green pill) |
| **NBD RW** | Hosted **writable** (red pill) |

Click → **Settings → Network Services → NBD**. Tooltip includes `nbd://…`.

### 2) NBD Hosts panel (under SMB / NFS / ISO)

Below the **SMB Shares | NFS Shares | ISO File Shares** block (Shares switch area), a section:

**NBD Hosts (this Unraid · not SMB/NFS mounts)**

| Mode | Device | Clients use | Label | |
|------|--------|-------------|-------|--|
| NBD RO / **NBD RW** | `/dev/…` | `nbd://…` | optional | Open NBD |

These are **local Host exports**, not remote mounts. They are listed next to the share area so they are easy to find when the Shares view is on; the disk-row badge stays visible on the Unassigned Disks table either way.

---

## How to enable

1. Install **Unassigned Devices** (community plugin) if you use it.  
2. **Settings → Network Services → NBD → Settings**  
3. **Unassigned Devices badges** → **Yes** → **Apply**  
4. Hard-refresh the browser (**Ctrl+Shift+R**).  
5. Host a disk, then open **Main → Unassigned Devices** (Main section of the WebUI).

Turn back to **No** anytime. Uninstall removes the soft page hook.

---

## What this is (and is not)

| Is | Is not |
|----|--------|
| Opt-in config flag (`ud_status_overlay`) | On by default |
| Small lettering next to the serial/device column | A new UD column API |
| Best-effort **DOM overlay** after UD paints/refreshes | A formal integration contract with Limetech/UD |
| Status only (RO/RW + link to NBD) | Mount / share / preclear / start-stop Host from UD |
| Private to your WebUI session (status JSON uses the logged-in session) | Network discovery or extra listeners |

### Important caveat

**Unassigned Devices owns Main → Unassigned Devices.** That page’s HTML, AJAX refresh, and class names can change on UD updates without notice. This overlay may stop matching rows until NBD Export is updated. That is expected for third-party page manipulation — not a supported UD feature.

If badges disappear after a UD update, status remains correct under **Network Services → NBD**. You can disable the option and report the UD version on the forum if you care about re-tuning selectors.

---

## Implementation notes (reviewers)

| Piece | Role |
|-------|------|
| `ud_status_overlay` in `NBDExport.cfg` | Opt-in (default `no`) |
| Soft include in Unraid `HeadInlineJS.php` | Only loads JS on Unassigned Devices URI when opt-in |
| `include/nbd-ud-head.php` | Gate: opt-in + UD installed + URI |
| `include/nbd-ud-overlay.js` | MutationObserver + poll; injects `.nbd-ud-badge` |
| `include/nbd-ud-status.php` | JSON list of live Host exports |
| Plugin remove | Strips HeadInlineJS marker lines |

No Unassigned Devices PHP is patched. No preclear-style hardcode inside UD.

---

## Related

- [hosting-safety.md](hosting-safety.md)  
- [security-and-bind.md](security-and-bind.md)  
- [../SECURITY.md](../SECURITY.md)  
