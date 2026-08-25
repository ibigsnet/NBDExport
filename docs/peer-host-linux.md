# Host a disk from another Linux machine

Use this when the physical disk lives on a **non-Unraid** peer (desktop, laptop, live USB, second Linux box) and you want Unraid **NBD Export → Pull** to save a **qcow2** or **raw** file.

Unraid-to-Unraid: use the **Host** tab on the peer instead. This page is the generic CLI path.

**Safety:** prefer **read-only** exports. NBD has **no authentication** — anyone who can reach the TCP port can read (or write, if you hosted writable) raw sectors. Bind a private address and open the firewall only for the Unraid that will pull.

---

## 1. Install tools on the peer

Package names vary:

| Distro family | Typical package |
|---------------|-----------------|
| Arch / derivatives | `qemu-img` or `qemu-base` (provides `qemu-nbd`) |
| Debian / Ubuntu | `qemu-utils` |
| Fedora / RHEL-ish | `qemu-img` |

Confirm:

```bash
command -v qemu-nbd
qemu-nbd --version
```

---

## 2. Pick device, bind IP, and port

- **Device:** whole disk or partition you intend to expose, e.g. `/dev/nvme0n1`, `/dev/sda`.  
  Prefer media that is **not** mounted read-write on the peer while exporting.
- **Bind IP:** an address on a private path Unraid can reach (LAN, VLAN, Thunderbolt IP, etc.). Avoid binding only to a public WAN IP unless you understand the risk.
- **Port:** e.g. `10809` (plugin default). Use a different port per disk if you host more than one.

---

## 3. Start a read-only export

```bash
qemu-nbd --read-only --persistent --shared=4 \
  --bind=<BIND_IP> --port=10809 --format=raw \
  <DEVICE>
```

Check it is listening:

```bash
ss -tlnp | grep 10809
# or: qemu-img info nbd://<BIND_IP>:10809
```

---

## 4. Firewall — narrow allow only

Do **not** open the NBD port to the whole Internet. Allow only the Unraid address or the private subnet that will Pull.

**firewalld** (runtime example):

```bash
firewall-cmd --add-rich-rule='rule family="ipv4" source address="<CLIENT_SUBNET_OR_IP>" port port="10809" protocol="tcp" accept'
```

Optional: add `--permanent` and `firewall-cmd --reload` if the job must survive a reboot on the peer. Remove the rule when finished:

```bash
firewall-cmd --remove-rich-rule='rule family="ipv4" source address="<CLIENT_SUBNET_OR_IP>" port port="10809" protocol="tcp" accept'
```

**ufw** sketch:

```bash
ufw allow from <CLIENT_SUBNET_OR_IP> to any port 10809 proto tcp
# when done:
ufw delete allow from <CLIENT_SUBNET_OR_IP> to any port 10809 proto tcp
```

If Unraid still cannot connect: confirm routing/ping/SSH to `<BIND_IP>`, then re-check listen address (`0.0.0.0` vs a specific IP) and the rich rule source.

---

## 5. Pull on Unraid

1. **Settings → Network Services → NBD → Pull**
2. NBD URL: `nbd://<BIND_IP>:10809`
3. Output: a **file** under `/mnt/…` (folder browser starts at `/mnt`).  
   Prefer `/mnt/cache/…`, `/mnt/diskN/…`, or a pool for large images; `/mnt/user` / `/mnt/user0` work but are slower.
4. Format: **qcow2** (usual) or **raw**
5. Start the pull; watch **Status**. When Done, stop the peer export and remove the firewall allow.

CLI equivalent on Unraid:

```bash
qemu-img convert -p -f raw -O qcow2 -t writeback -W \
  nbd://<BIND_IP>:10809 /mnt/cache/domains/example.qcow2
```

---

## 6. Stop the peer export

```bash
# find the process
ps aux | grep '[q]emu-nbd'
kill <pid>
```

Or, if you used a foreground job, Ctrl+C in that terminal.

---

## Writable host (rare)

Only if a remote tool must **modify** the disk in place:

```bash
qemu-nbd --persistent --shared=1 \
  --bind=<BIND_IP> --port=10809 --format=raw \
  <DEVICE>
```

Single writer only. Prefer read-only for imaging. On Unraid, **writable Host** of array/mounted/boot media also requires **Destructive mode** — see [destructive-mode.md](destructive-mode.md).

---

## Related

- [how-to-use.md](how-to-use.md) — UI walkthroughs  
- [security-and-bind.md](security-and-bind.md) — bind IP and read-only  
- [imaging-workflow.md](imaging-workflow.md) — convert / restore  
- [client-attach.md](client-attach.md) — live `nbd://` for VMs (not Pull)
