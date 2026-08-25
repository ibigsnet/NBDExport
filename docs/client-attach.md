# NBD Export — client attach (live use / VM)

**Pull** copies a remote disk into a **file**. **Attach / Client** means using `nbd://host:port` as a **live block device** (VM disk, mount, clone tools) **without** finishing an image first.

This document is the product contract for that path. Full UI automation may lag; CLI/libvirt patterns work today.

---

## When to use Attach vs Pull

| Goal | Use |
|------|-----|
| Archive, migrate, boot later from qcow2 on Unraid | **Pull** → file → VM disk |
| Run a VM **now** against the remote physical/hosted disk | **Attach** (live `nbd://`) |
| Cold backup only | **Host RO** + **Pull** |

Live attach is **latency-sensitive** and **disconnect-sensitive**. Prefer private fast paths (Thunderbolt, 10G, isolated copper).

---

## Architecture

```text
Host Unraid                     Client (VM host / second Unraid)
┌─────────────────┐             ┌──────────────────────────┐
│ /dev/nvme1n1    │  nbd://     │ QEMU/libvirt  ──nbd──►   │
│ qemu-nbd :10809 │◄────────────│   VM disk = remote blocks│
└─────────────────┘             │  or nbd-client → /dev/nbd0│
                                └──────────────────────────┘
```

There is no separate “push” protocol: the **host publishes**; the **client** connects.

---

## Option A — QEMU/libvirt disk over NBD (preferred)

Unraid VM XML can use a network disk (syntax varies slightly by Unraid/libvirt version). Conceptually:

```xml
<disk type='network' device='disk'>
  <driver name='qemu' type='raw' cache='none'/>
  <source protocol='nbd' name=''>
    <host name='<HOST_IP>' port='10809'/>
  </source>
  <target dev='hdc' bus='virtio'/>
</disk>
```

Or QEMU CLI style:

```bash
-drive file=nbd://<HOST_IP>:10809,format=raw,if=virtio,cache=none
```

**Pros:** No `/dev/nbdN` on the host; guest I/O goes straight to NBD.  
**Cons:** VM fails if the host export dies; writable = dual-writer risk if anything else has the disk.

### Writable host (destructive)

If the Host exported **read-write**:

- Only **one** writer should own the filesystem (the VM).  
- Do **not** mount the same disk on the Host Unraid, and do **not** open a second client RW.  
- Prefer **read-only** host for imaging and for “look but don’t touch” VMs.

### Read-only host (client view)

If the Host exported **read-only**, clients can **read** but not **write**. With qemu tools this often shows as:

| Attempt | Typical message |
|---------|-----------------|
| Write while opened read-only (`qemu-io -r`) | `Block node is read-only` |
| Open for write (no `-r`) | `Could not open image: Permission denied` |

`qemu-img info` and `qemu-img convert` **from** a RO `nbd://` still work (they only read). Full table: [security-and-bind.md](security-and-bind.md#what-read-only-protects).

---

## Option B — Kernel `nbd-client` → `/dev/nbdN`

```bash
modprobe nbd
nbd-client <HOST_IP> 10809 /dev/nbd0
# then use /dev/nbd0 in libvirt as a block disk, or mount carefully
nbd-client -d /dev/nbd0   # disconnect when done
```

**Pros:** Looks like a local block device.  
**Cons:** Needs `nbd` module and `nbd-client`; must disconnect cleanly; easy to leave stale devices.

Plugin **Attach** UI (when present) should track pid/state and **Stop attach** the way Host tracks exports.

---

## Safety checklist

1. Host bind = **private IP** (not `0.0.0.0` unless you understand the risk).  
2. Prefer **read-only** host unless the VM must write.  
3. Stop Host when finished; do not leave writable NBD on a shared LAN.  
4. Discovery ([discovery.md](discovery.md)) only finds endpoints — it does not make multi-writer safe.  
5. **Not** a substitute for iSCSI/clustered SAN multipath/fencing.

---

## Product roadmap (UI)

| Stage | Behavior |
|-------|----------|
| **Now** | Docs + CLI/libvirt; Pull for imaging; Scan to fill URL |
| **Next** | Pull tab: “Copy libvirt disk XML” / “qemu -drive …” snippet from a scan row |
| **Later** | Attach tab: map/unmap `/dev/nbdN` with status on Status tab |

---

## Related

- [discovery.md](discovery.md) — Scan / beacon  
- [destructive-mode.md](destructive-mode.md)  
- [imaging-workflow.md](imaging-workflow.md)  
- [security-and-bind.md](security-and-bind.md)
