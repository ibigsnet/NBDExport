# When to enable Destructive mode

**Path:** Settings → Network Services → NBD → **Settings** → **Destructive mode**

Default is **No**. Leave it Off for normal imaging.

Destructive mode only affects **Host** (publishing a local disk). **Pull** never needs it.

While **On**, every NBD tab shows an **orange banner**. Set it back to **No** when finished.

---

## Leave it Off (the usual case)

Host **read-only**, disk **not** part of Unraid storage, **not mounted**, **not** the Unraid boot device — Destructive mode stays **No**.

Typical safe source: an **unassigned** NVMe/SSD you plugged in only to image.

---

## The only times to enable it

Turn Destructive mode **On** only for one of these four Host situations:

### 1. Writable host (Read-only = No)

- Peer can **write** to the Unraid disk over NBD, not only image it  
- Blocked unless Destructive mode is On  
- Prefer read-only Host + Pull to a file instead  
- Use only for rare in-place lab recovery  

### 2. Unraid storage disk (array, parity, cache, or pool)

- Array data disks, parity, **cache**, and **named pools** (anything Unraid tracks in its disk inventory — not only “the array”)  
- Also `md*` array devices  
- Even **read-only** Host is blocked without Destructive mode  
- Prefer an unassigned disk; hosting live storage risks consistency and load  

### 3. Mounted disk

- Any filesystem from that device still mounted (e.g. under `/mnt/…`)  
- Host (read-only or writable) is blocked without Destructive mode  
- Unmount first when you can — live mounts often mean an inconsistent image  
- Catches pool/cache/user mounts even when you think of the disk as “not array”  

### 4. Unraid boot device (usually the USB flash)

- Whatever device currently holds **`/boot`** (the Unraid OS config drive)  
- Almost always the **USB flash**; if Unraid is booted from a disk/partition instead, **that** media is treated the same  
- Host is blocked without Destructive mode  
- Writable host of the boot device is **refused** entirely (even with Destructive mode On)  
- Almost never needed for routine work  

---

## After a special job

1. Finish Host / Pull  
2. **Stop** any host  
3. **Destructive mode = No** → **Apply**

---

## Related

- [security-and-bind.md](security-and-bind.md)  
- [how-to-use.md](how-to-use.md)  
- [settings-reference.md](settings-reference.md)  
