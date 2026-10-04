#!/usr/bin/env bash
# Build dist/dn-burst-funnel-stats-<version>.zip containing only runtime files.
# Run via Docker if the host lacks zip/rsync:
#   docker run --rm -v "$PWD":/app -w /app alpine sh -c "apk add --no-cache bash zip rsync >/dev/null && bash bin/build-zip.sh"
set -euo pipefail

SLUG="dn-burst-funnel-stats"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$SLUG.php" | head -n1 | tr -d '[:space:]')"
if [ -z "$VERSION" ]; then
	echo "Could not read Version from $SLUG.php" >&2
	exit 1
fi

OUT_DIR="$ROOT/dist"
ZIP="$OUT_DIR/$SLUG-$VERSION.zip"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

mkdir -p "$OUT_DIR" "$TMP/$SLUG"
rsync -a --exclude-from=.distignore ./ "$TMP/$SLUG/"

rm -f "$ZIP"
(cd "$TMP" && zip -qr "$ZIP" "$SLUG")

echo "Built $ZIP ($(du -h "$ZIP" | cut -f1))"
