# Integration with Thunderbolt Net

NBD Export and Thunderbolt Net are **optional companions**. Either works alone.

## Dependency direction

```text
Thunderbolt Net  ──provides──►  thunderboltN + IPs (underlay)
                                      │
                                      ▼
NBD Export       ──binds to──►  those IPs (or any other private IP)
```

- NBD Export **never** `require`s Thunderbolt Net PHP.  
- Thunderbolt Net **never** requires NBD Export.  
- Soft detect: plugin directories / companion markers only.

## Bind defaults

If `thunderbolt*` has an IPv4 address, the bind dropdown lists it **first** and pre-selects it.

## Listening vs NBD

| Mechanism | SMB / NFS / SSH / web | NBD |
|-----------|------------------------|-----|
| Controlled by `network-extra` include? | **Yes** (Thunderbolt Net “Unraid services”) | **No** |
| How traffic is served | Unraid service stack on included ifaces | `qemu-nbd --bind=IP` |

Turn listening **Yes** if you want file/web services on the TB IP.  
Start an **NBD export** separately if you need block export on that IP.

## Recommended order for multi-TB imaging

1. Thunderbolt Net: link up, static IPs, ping both ways.  
2. Optional: listening Yes if you also need SMB on the same path.  
3. NBD Export: RO export bound to the TB IP.  
4. Image job or peer `qemu-img convert`.  
5. Stop export.
