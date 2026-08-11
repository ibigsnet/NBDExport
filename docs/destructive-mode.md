# When to enable Destructive mode

**Path:** Settings → Network Services → NBD → **Settings** → **Destructive mode**

Default is **No**. Leave it there for normal imaging.

Destructive mode only affects the **Host** tab (publishing a local disk). **Pull** never needs it and never writes to `/dev/…`.

While it is **On**, every NBD tab shows an **orange banner**. Turn it back to **No** when the special job is finished.

---

## Contents

- [Safe default (leave it Off)](#safe-default-leave-it-off)
- [Turn it On only for these Host cases](#turn-it-on-only-for-these-host-cases)
- [After a special job](#after-a-special-job)
- [Related](#related)

---

## Safe default (leave it Off)

You do **not** need Destructive mode when all of these are true:

- Host **Read-only = Yes**
- Disk is **not** an Unraid array or parity member
- Disk is **not** mounted (no filesystem from it under `/mnt/…`)
- Disk is **not** the Unraid flash (`/boot`)

**Example:** an unassigned NVMe you plugged in only to image ([Scenario C](how-to-use.md#scenario-c--both-ends-are-unraid-plug-the-nvme-where-its-easy-store-the-qcow2-where-theres-room)) — Destructive mode stays **No**.

---

## Turn it On only for these Host cases

Enable Destructive mode only if **at least one** of the following applies to the disk you want to **Host**. Prefer fixing the situation first (unmount, unassign, keep read-only) when you can.

### Writable host (Read-only = No)

Without Destructive mode, the UI blocks a writable host.

The peer can **write** through NBD to that Unraid disk — not only image it. That is almost never needed for cold imaging. Prefer **read-only Host + Pull to a file**, then work on the copy.

Use a writable host only for rare lab recovery where a peer tool must modify the disk **in place**. A mistake can destroy data on that disk.

### Array or parity member

Even a **read-only** host of an array data disk, parity, or related `md*` member is blocked until Destructive mode is On.

Hosting a **live** array disk risks consistency and load. Prefer an **unassigned** disk. Use this only for an emergency capture you cannot unassign first.

### Mounted disk (or a mounted partition on it)

If any filesystem from that device is mounted, Host is blocked until Destructive mode is On (read-only or writable).

A live mount often means an **inconsistent** image. Unmount first when possible; enable Destructive mode only if you truly cannot.

### Unraid flash (USB boot drive)

The device behind `/boot` is blocked until Destructive mode is On.

Almost never needed for routine work. Critical system disk — a **writable** host of the flash is especially dangerous.

---

## After a special job

1. Finish Host and/or Pull.  
2. **Stop** any host still listening.  
3. Set **Destructive mode = No** and **Apply**.

---

## Related

- [security-and-bind.md](security-and-bind.md) — bind IP, isolation, read-only vs writable  
- [how-to-use.md](how-to-use.md) — Host / Pull walkthroughs  
- [settings-reference.md](settings-reference.md) — Settings tab controls  
