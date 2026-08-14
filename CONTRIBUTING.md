# Contributing

- Versioning: see [RELEASES.md](RELEASES.md).
- Never force-push `main`.  
- No empty-file pushes.  
- Do not hard-`require` ThunderboltNet or FabricRouting PHP.  
- Default security: read-only, no `0.0.0.0` without explicit allow.  
- Keep `README.md` to a short Plugins-list blurb; long form in `DOCS.md` / `docs/`.  

## Public docs vs private notes

- **GitHub (`main` / `stable`)** — product docs only: user-facing, stable, no lab hostnames, no session chatter, no agent/assistant reply text.
- **Local only** — put planning, lab notes, and assistant scratch work under **`.grok-notes/`** (gitignored). Never commit conversation dumps into `docs/` or `README.md`.

## License

By contributing, you agree that your contributions are licensed under the **GNU GPLv3 or later** (same as this project). Copyright for the project is held by **ibigs, LLC**.

## Branches

- `main` — development (may break)
- `stable` — production / CA channel only; maintainers merge release-ready work here

