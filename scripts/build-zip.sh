#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

VERSION="$(date +%Y%m%d-%H%M%S)"
NAME="testimonials-manager-$VERSION"
DIST="dist/$NAME"

rm -rf dist
mkdir -p "$DIST"

echo "==> Copying source (excluding dev/build artifacts)"
rsync -a --exclude-from=scripts/zip-exclude.txt ./ "$DIST/"

echo "==> Installing production vendor/ inside the app container"
docker compose run --rm -v "$PWD/$DIST:/dist" -w /dist app composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Zipping"
(cd dist && zip -rq "../$NAME.zip" "$NAME")

echo "Built $NAME.zip ($(du -h "$NAME.zip" | cut -f1))"
