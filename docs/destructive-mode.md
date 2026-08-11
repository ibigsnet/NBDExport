# When to enable Destructive mode

**Path:** Settings → Network Services → NBD → **Settings** tab → **Destructive mode**

Default is **No**. Leave it there for normal imaging of an unassigned, unmounted disk.

Destructive mode is **only** for the **Host** tab (publishing a local disk). **Pull** never needs it and never writes to `/dev/…`.

---

## Contents

- [You need Destructive mode = Yes when…](#you-need-destructive-mode-yes-when)
- [You do **not** need Destructive mode when…](#you-do-not-need-destructive-mode-when)
- [After a special job](#after-a-special-job)
- [Related](#related)

## You need Destructive mode = Yes when…

Enable it only if **at least one** of the following is true for the disk you want to **Host**:

### 1. You want a **writable** host

| | |
|--|--|
| **Setting** | Host tab → **Read-only = No** |
| **What happens** | The peer can **write** to that Unraid disk over NBD (not only image it). |
| **When you might** | Rare lab recovery where a peer tool must modify the disk in place. |
| **Risk** | A mistake can destroy data on that disk. Prefer read-only + Pull to a file instead. |

Without Destructive mode, **Read-only = No** is blocked.

### 2. The disk is an **Unraid array or parity** member

| | |
|--|--|
| **Examples** | Array data disk, parity, `md*` devices listed as array members |
| **What happens** | Even a **read-only** host is blocked until Destructive mode is On. |
| **When you might** | Emergency capture of an array disk you cannot unassign first. |
| **Risk** | Hosting a live array disk is dangerous for consistency and load; prefer an unassigned disk when you can. |

### 3. The disk (or a partition on it) is **mounted**

| | |
|--|--|
| **Examples** | Filesystem mounted under `/mnt/…`, or any child partition with a mount |
| **What happens** | Host is blocked until Destructive mode is On (read-only or writable). |
| **When you might** | You must image a disk that is still mounted and cannot unmount it first. |
| **Risk** | Live mounts mean the image may not be consistent; unmount first when possible. |

### 4. The disk is the **Unraid flash** (USB boot drive)

| | |
|--|--|
| **Examples** | The device behind `/boot` |
| **What happens** | Host is blocked until Destructive mode is On. |
| **When you might** | Almost never for routine work. |
| **Risk** | Critical system disk; writable host of flash is especially dangerous. |

---

## You do **not** need Destructive mode when…

All of these are true (the common case):

- **Read-only = Yes** on Host  
- The disk is **not** an array/parity member  
- The disk is **not** mounted (no filesystem mounted from it)  
- The disk is **not** the Unraid flash  

Example: an **unassigned** NVMe you plugged in only to image (Scenario C) — Destructive mode stays **No**.

---

## After a special job

1. Finish Host / Pull.  
2. **Stop** any host.  
3. Set **Destructive mode = No** and **Apply**.  

While it is On, every NBD tab shows an **orange banner**.

---

## Related

- [security-and-bind.md](security-and-bind.md) — bind IP, isolation  
- [how-to-use.md](how-to-use.md) — Host / Pull walkthroughs  
- [settings-reference.md](settings-reference.md) — Settings tab controls  
