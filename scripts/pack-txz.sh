#!/usr/bin/env bash
# Build Slackware-style .txz for NBD Export (runtime payload only).
# Usage: ./scripts/pack-txz.sh [version]
# Output: archive/NBDExport-<version>-x86_64-1.txz
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
  VERSION=$(sed -n 's/.*ENTITY version "\([^"]*\)".*/\1/p' nbd.plg | head -1)
fi
[ -n "$VERSION" ] || { echo "version required"; exit 1; }

PKG="NBDExport-${VERSION}-x86_64-1"
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
DEST="$STAGE/usr/local/emhttp/plugins/NBDExport"

mkdir -p "$DEST"/{include,scripts,event}

# Runtime only — no docs/, DOCS.md, SECURITY.md, CONTRIBUTING, LICENSE
for f in \
  NBDExport.page NBDStatus.page NBDHost.page NBDPull.page NBDSettings.page \
  default.cfg README.md
do
  cp -a "$ROOT/$f" "$DEST/$f"
done
cp -a "$ROOT"/include/* "$DEST/include/"
cp -a "$ROOT"/scripts/nbd-export-start "$ROOT"/scripts/nbd-export-stop "$ROOT"/scripts/nbd-image-from-url "$DEST/scripts/"
cp -a "$ROOT"/event/started "$DEST/event/"
# Do not pack pack-txz.sh into runtime tree
rm -f "$DEST/scripts/pack-txz.sh" 2>/dev/null || true

mkdir -p "$ROOT/archive"
OUT="$ROOT/archive/${PKG}.txz"
rm -f "$OUT"

# Slackware-compatible package (installpkg/upgradepkg/removepkg)
(
  cd "$STAGE"
  # Prefer makepkg when present (Unraid/Slackware); else tar+xz
  if command -v makepkg >/dev/null 2>&1; then
    makepkg -l y -c n "$OUT"
  else
    tar --owner=0 --group=0 --numeric-owner -cJf "$OUT" .
  fi
)

ls -la "$OUT"
echo "Built $OUT"
# show top of listing
tar -tJf "$OUT" | head -20
echo "…"
echo "files: $(tar -tJf "$OUT" | wc -l)"
