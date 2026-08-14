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

1. Collect **private IPv4** subnets from this host’s interfaces (e.g. `192.168.1.0/24`, `192.168.254.0/24`).  
2. Probe each host for:
   - **NBD TCP** ports (default **10809**, plus a short range if configured).  
   - **Beacon HTTP** on port **10808** (plugin JSON when advertise is running).  
3. Classify results:
   - **Peer plugin** — beacon JSON from NBD Export (hostname, version, export list).  
   - **NBD port open** — TCP open; optional `qemu-img info` size/format.  
4. UI: pick a row → **Use** fills the Pull **NBD URL** field.

Scan is **best-effort** and bounded (timeouts, max hosts per subnet) so the WebUI does not hang.

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
| Scan targets | Private IPv4 ranges only |
| Beacon answers | Private client IPs only |
| NBD itself | Still **no** protocol auth — isolation is **bind IP** + RO default |
| Token (optional later) | Shared secret on beacon/scan — not required for v1 lab use |
| Cloud | None |

Treat open Host + writable export as sensitive even on LAN. Prefer Thunderbolt / isolated copper / VLAN.

---

## Setup (two Unraid hosts)

1. Install **NBD Export** on both.  
2. **NIROG (host):** Host tab → export disk, bind `192.168.1.3` (or lab IP), port `10809`.  
3. Beacon starts automatically when the export is up.  
4. **HoloX3D (scanner):** Pull tab → **Scan network** → select peer → **Use** → Pull or copy URL for Attach/VM.  
5. Ensure L3 reachability (Wi‑Fi, copper `192.168.254.x`, etc.).

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
