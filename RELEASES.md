# Releases

Versioning: Unraid `strcmp` — `YYYY.MM.DD` then `aa`, `ab`, … No hyphens.

## Install URLs

```text
# Latest
https://raw.githubusercontent.com/ibigsnet/NbdExport/main/nbdexport.plg
```

## History

### 2026.08.11ad

- Clearer UX: Start NBD listener (server) vs image job (client); how-to-use.md with scenarios.

### 2026.08.11ac

- Destructive mode (default Off): server + UI guards against accidental writable/array exports; image jobs cannot target block devices.

### 2026.08.11ab

- Settings page layout aligned with UnraidFRR / Thunderbolt Net (forms, help panels, companion strip).

### 2026.08.11aa

- Clarify Unraid product min vs qemu-nbd/qemu-img tools (not Linux kernel version).

### 2026.08.11

- Initial public release: Network Services → NBD UI  
- read-only `qemu-nbd` export with bind IP picker (Thunderbolt first)  
- Background `qemu-img convert` image jobs  
- Docs: when to use, vs NFS/SMB, security, imaging, Thunderbolt/FRR integration  
