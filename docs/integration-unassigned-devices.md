# Unassigned Devices integration (opt-in)

**Where:** Unraid **Main → Unassigned Devices**  
(not Unassigned Devices Settings)

**Default: Off.** When enabled, NBD Export shows Host status on that page. The overlay is best-effort: Unassigned Devices owns the page markup and may change without notice.

## Disk row badge

On a disk that is currently Hosted, the Identification cell can show:

| Badge | Meaning |
|-------|---------|
| **NBD RO** | Hosted read-only |
| **NBD RW** | Hosted writable |

Click opens **Settings → Network Services → NBD**. Tooltip includes `nbd://…` when known. Badges appear only while a Host is active (no permanent empty slot on other disks).

## NBD Hosts panel

Below **SMB Shares | NFS Shares | ISO File Shares** (Shares area):

**NBD Hosts (this Unraid)** — local Host exports, not remote mounts.

| Mode | Device | Clients use | Label |
|------|--------|-------------|-------|
| NBD RO / NBD RW | `/dev/…` | `nbd://…` | optional |

## How to enable

1. Install the Unassigned Devices plugin if needed.  
2. **Settings → Network Services → NBD → Settings**  
3. **Unassigned Devices badges** → **Yes** → **Apply**  
4. Refresh the browser, Host a disk, open **Main → Unassigned Devices**.

Set back to **No** anytime.

## How it loads

The badges come from the plugin's own `NBDUDOverlay.page` (`Menu="Buttons"`), the hook Unraid provides for plugins to add script to every WebUI page. It prints nothing unless this setting is **Yes** and Unassigned Devices is installed. The plugin does not edit Unraid's own files; installing this release removes the line older releases added to `HeadInlineJS.php` and the old stock-file backup under `/boot/config/plugins/NBDExport/stock-backup/`.

## Limitations

- Not a formal Unassigned Devices API; no mount/share/preclear controls are added.  
- If Unassigned Devices changes its HTML/AJAX layout, badges may need a plugin update. Host status remains correct under **Network Services → NBD**.  
- Status JSON is only for the logged-in WebUI session.

## Related

- [hosting-safety.md](hosting-safety.md)  
- [security-and-bind.md](security-and-bind.md)  
- [../SECURITY.md](../SECURITY.md)  
