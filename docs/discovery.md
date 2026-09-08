# NBD Export — network discovery (multi-host)

Find **NBD listeners** and optional **NBD Export peer beacons** on your **private LAN**, without pasting `nbd://…` by hand. Same product shape as Thunderbolt Net fabric reports: **opt-in advertise**, **private path**, **not cloud**.

- **Not** SMB/NFS share browsing (Unassigned Devices does that).  
- **Not** a public Internet scanner.  
- **Scanner** runs on the Unraid that opens **Pull → Scan network** (authenticated WebUI).  
- **Advertise** runs on the Unraid that is **Hosting** (lightweight beacon while exports are up).

---

## Modes (product map)

| Mode | Tab | Role |
|------|-----|------|
| **Host** | Host | Publish `/dev/…` as `nbd://IP:port` |
| **Pull** | Pull | Image `nbd://` → file under `/mnt/` |
| **Attach / Client** | Docs + CLI today; UI later | Live use of `nbd://` (e.g. VM disk) — [client-attach.md](client-attach.md) |
| **Discover** | Pull → **Scan network** | Find peers/exports on private subnets |

“Push” is not a separate wire protocol: **source Hosts**, **destination Pulls or Attaches**.

---

## What Scan does

Scan never runs on page load. **Pull → Scan network** is a button. You tick which **local private LAN(s)** to probe (Thunderbolt is ticked by default when present; the default-route/management LAN is left off if another private LAN exists). Paste an `nbd://` URL instead if you do not want to scan.

1. **POST + csrf_token** only. The server accepts only CIDRs that are on this box (local private /24) or in optional `scan_extra_subnets`. It does not sweep every route.
2. **Default probe:** plugin **beacons on TCP 10808**. Optional checkbox also probes NBD ports **10809–10812**.
3. Beacon JSON (hostname, version, labels, URLs) is **HTML-escaped** before it is shown.
4. Remembered peer IPs are re-probed only if they sit on a LAN you ticked.
5. UI: pick a row → **Use** fills the Pull **NBD URL** field.

Scan is **best-effort** and bounded (timeouts, max hosts per subnet) so the WebUI does not hang.

### Cross-LAN tip

If the scanner only has a fabric IP (e.g. `192.168.254.4`) and reaches the export host via **NAT/default gateway**, there may be **no** `192.168.1.0/24` route entry. Either:

- add a route: `ip route add 192.168.1.0/24 via <gateway>`, or  
- set `scan_extra_subnets="192.168.1.0/24"` in `/boot/config/plugins/NBDExport/NBDExport.cfg`, or  
- paste the peer once (after first hit, Scan re-probes remembered peers).

---

## What Advertise / beacon does

While at least one **managed** Host export is listening:

| | |
|--|--|
| **Port** | **10808/tcp** (default NBD port − 1) |
| **Bind** | Export bind IPs (private); not a WAN service by design |
| **Payload** | `plugin`, `version`, `hostname`, `exports[{url,port,bind,read_only,label,device_name}]` |
| **Auth** | No Unraid login (so peers can scan without each other’s root password). **Private remotes only** in the beacon process. |
| **Lifecycle** | Started with first export; stopped when last managed export stops |

Payload does **not** include array data, passwords, or full disk contents — only how to connect.

---

## Security

| Control | Default / rule |
|---------|----------------|
| Scan targets | Private IPv4 /24s you tick (local ifaces; optional cfg extra) |
| Scan start | Button + POST + csrf_token (GET does not scan) |
| Beacon answers | Private client IPs only; listener prefers the Host bind IP |
| NBD itself | Still **no** protocol auth — isolation is **bind IP** + RO default |
| Token (optional later) | Shared secret on beacon/scan — not required for basic LAN use |
| Cloud | None |

Treat open Host + writable export as sensitive even on LAN. Prefer Thunderbolt / isolated copper / VLAN.

---

## Setup (two Unraid hosts)

1. Install **NBD Export** on both.  
2. **Host:** Host tab → export disk, bind a private IP, port `10809` (or next free port).  
3. Beacon starts automatically when the export is up.  
4. **Scanner:** Pull tab → tick the LAN → **Scan network** → select peer → **Use** → Pull or copy URL for Attach/VM.  
5. Ensure L3 reachability between the two machines.

---

## Colors / result kinds (UI)

| Kind | Meaning |
|------|---------|
| **Peer (NBD Export)** | Beacon OK — same plugin family |
| **NBD open** | Port open; may be qemu-nbd or another NBD server |
| **Unreachable / timeout** | No answer (not listed, or listed as failed probe) |

---

## Related

- [client-attach.md](client-attach.md) — live VM / client use of `nbd://`  
- [security-and-bind.md](security-and-bind.md)  
- [how-to-use.md](how-to-use.md)  
- Thunderbolt Net: [fabric-link-map.md](https://github.com/ibigsnet/ThunderboltNet/blob/main/docs/fabric-link-map.md) (same multi-host philosophy)
