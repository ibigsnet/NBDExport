# Contributing

- Versioning: `YYYY.MM.DD` + `aa`/`ab`/… (Unraid `strcmp`). No hyphens.  
- Never force-push `main`.  
- No empty-file pushes.  
- Do not hard-`require` ThunderboltNet or FabricRouting PHP.  
- Default security: read-only, no `0.0.0.0` without explicit allow.  
- Keep `README.md` to a short Plugins-list blurb; long form in `DOCS.md` / `docs/`.  

## License

By contributing, you agree that your contributions are licensed under the **GNU GPLv3 or later** (same as this project). Copyright for the project is held by **ibigs, LLC**.

## Branches

- `main` — development (may break)
- `stable` — production / CA channel only; maintainers merge release-ready work here

## Versioning (calendar)

Version date = **lab host wall clock** (America/Chicago), not UTC and not “previous date + 1”. Run `date` on lab before bumping. See [RELEASES.md](RELEASES.md).
