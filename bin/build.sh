#!/usr/bin/env bash
#
# build.sh — produce the distributable plugin zip.
#
# The zip contains only what a site needs to run the plugin. Tests, dev
# configuration, the Phase 0 diagnostic, and the project documents are excluded:
# a site owner installing this should not receive the OAuth probe script or the
# specification.
#
# Usage: bin/build.sh [output-dir]   (default: ./build)

set -euo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$ROOT"

SLUG="social-relay"
OUT_DIR="${1:-$ROOT/build}"
STAGE="$OUT_DIR/$SLUG"

# The version in the zip name comes from the plugin header, and the header is
# only trustworthy if all four locations agree.
bash bin/check-version.sh >/dev/null || { echo "ERROR: version check failed; refusing to build." >&2; exit 1; }

VERSION=$(grep -m1 -iE '^\s*\*?\s*Version:' "$SLUG.php" | sed -E 's/.*[Vv]ersion:[[:space:]]*//' | tr -d '[:space:]')
[ -n "$VERSION" ] || { echo "ERROR: could not read version." >&2; exit 1; }

rm -rf "$STAGE"
mkdir -p "$STAGE"

# Ship list. Anything not named here does not reach a site.
for item in "$SLUG.php" uninstall.php readme.txt LICENSE includes admin; do
    [ -e "$item" ] || { echo "ERROR: required path missing: $item" >&2; exit 1; }
    cp -R "$item" "$STAGE/"
done

# Belt and braces: remove anything that should never ship, in case a stray file
# was added inside includes/ or admin/.
find "$STAGE" -name '*.md'        -delete
find "$STAGE" -name '.DS_Store'   -delete
find "$STAGE" -name '*:Zone.Identifier' -delete
find "$STAGE" -name 'tests' -type d -prune -exec rm -rf {} +

ZIP="$OUT_DIR/$SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$OUT_DIR" && zip -rq "$(basename "$ZIP")" "$SLUG" )
rm -rf "$STAGE"

echo "Built: $ZIP"
unzip -l "$ZIP" | tail -n +4 | head -n -2 | awk '{print "  " $4}'

# A last assertion: the zip must not contain anything from this list.
for forbidden in verify-x-api.php SPEC.md PROJECTBRIEF.md phpunit.xml composer.json .git; do
    if unzip -l "$ZIP" | grep -q -- "$forbidden"; then
        echo "ERROR: build leaked $forbidden into the zip." >&2
        exit 1
    fi
done
echo "Verified: no development or specification files in the zip."
