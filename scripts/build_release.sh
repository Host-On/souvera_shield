#!/usr/bin/env bash
# Builds an atomic, deployable release tarball under dist/.
# Deploy on the server with:
#   tar -xzf souvera_shield-<version>.tar.gz -C /path/to/custom_apps/ && systemctl restart php-fpm
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

yarn build
python3 scripts/generate_manifest.py

VERSION="$(grep -oPm1 '(?<=<version>)[^<]+' appinfo/info.xml)"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/souvera_shield" dist
for dir in appinfo lib js l10n templates img; do
    cp -r "$dir" "$STAGE/souvera_shield/"
done

TARBALL="dist/souvera_shield-${VERSION}.tar.gz"
tar -czf "$TARBALL" -C "$STAGE" souvera_shield
sha256sum "$TARBALL"
echo "OK: $TARBALL"
