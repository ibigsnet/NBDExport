# Unassigned Devices integration (opt-in)

**Default: Off.** When enabled, NBD Export shows small status lettering on disks that are **actively Hosted** while you are on **Main → Unassigned Devices**.

| Badge | Meaning |
|-------|---------|
| **NBD RO** | This device (or a partition on it) is hosted **read-only** |
| **NBD RW** | Hosted **writable** (red, bold) |

Click the badge → **Settings → Network Services → NBD**. Tooltip includes `nbd://…` when known.

---

## How to enable

1. Install **Unassigned Devices** (community plugin) if you use it.  
2. **Settings → Network Services → NBD → Settings**  
3. **Unassigned Devices badges** → **Yes** → **Apply**  
4. Hard-refresh the browser (**Ctrl+Shift+R**).  
5. Host a disk, then open **Main → Unassigned Devices**.

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
