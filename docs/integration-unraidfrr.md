# Integration with Fabric Routing (UnraidFRR)

**Fabric Routing / UnraidFRR is not required for NBD Export.**

FRR (via UnraidFRR + optional Thunderbolt Net OpenFabric) only affects **how packets are routed**. NBD is ordinary TCP to an IP:port. If a multi-hop fabric can reach `10.255.x.y`, an NBD client can use `nbd://10.255.x.y:10809` the same as any other TCP app.

| Product | UI location | Role for NBD |
|---------|-------------|--------------|
| **Fabric Routing (FRR)** / UnraidFRR | Network Settings → **Fabric Routing** | Host routing stack (optional) |
| **Thunderbolt Net** | Network Settings → **Thunderbolt** | Underlay IPs + optional OpenFabric policy |
| **NBD Export** | Network Services → **NBD** | Bind / host / pull on L4 |

No cross-plugin PHP requires. Do not enroll br0/eth0 into routing protocols just to use NBD.
