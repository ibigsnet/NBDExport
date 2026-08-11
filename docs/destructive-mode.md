# When to enable Destructive mode

**Path:** Settings → Network Services → NBD → **Settings** → **Destructive mode**

Default is **No**. Leave it Off for normal imaging.

Destructive mode only affects **Host** (publishing a local disk). **Pull** never needs it.

While **On**, every NBD tab shows an **orange banner**. Set it back to **No** when finished.

---

## Leave it Off (the usual case)

Host **read-only**, disk **unassigned**, **not mounted**, **not** the Unraid flash — Destructive mode stays **No**.

---

## The only times to enable it

Turn Destructive mode **On** only for one of these four Host situations:

### 1. Writable host (Read-only = No)

- Peer can **write** to the Unraid disk over NBD, not only image it  
- Blocked unless Destructive mode is On  
- Prefer read-only Host + Pull to a file instead  
- Use only for rare in-place lab recovery  

### 2. Array or parity member

- Array data disk, parity, or related `md*` member  
- Even **read-only** Host is blocked without Destructive mode  
- Prefer an unassigned disk; live array hosts risk consistency and load  

### 3. Mounted disk

- Any filesystem from that device still mounted under `/mnt/…`  
- Host (read-only or writable) is blocked without Destructive mode  
- Unmount first when you can — live mounts often mean an inconsistent image  

### 4. Unraid flash (USB boot drive)

- The device behind `/boot`  
- Host is blocked without Destructive mode  
- Almost never needed; writable host of flash is especially dangerous  

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
