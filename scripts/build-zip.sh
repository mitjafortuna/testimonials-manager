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

echo "==> Writing .env with the real upstream API key (DB credentials stay placeholders — assessor's MySQL can't be predicted)"
API_KEY="$(grep -m1 '^LANDINGS_API_KEY=' .env 2>/dev/null | cut -d= -f2-)"
if [ -z "$API_KEY" ] || [ "$API_KEY" = "replace-me" ]; then
  echo "ERROR: local .env has no real LANDINGS_API_KEY set. Set it before building the release zip (see README) — refusing to ship a zip with a placeholder key while the README promises a working one." >&2
  exit 1
fi
awk -v key="$API_KEY" '{ if ($0 ~ /^LANDINGS_API_KEY=/) print "LANDINGS_API_KEY=" key; else print }' .env.example > "$DIST/.env"

echo "==> Installing production vendor/ inside the app container"
docker compose run --rm -v "$PWD/$DIST:/dist" -w /dist app composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Zipping"
(cd dist && zip -rq "../$NAME.zip" "$NAME")

echo "Built $NAME.zip ($(du -h "$NAME.zip" | cut -f1))"
