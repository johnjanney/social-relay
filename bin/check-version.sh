#!/usr/bin/env bash
#
# check-version.sh — enforce that the version string agrees in all four places.
#
# VERSIONING.md names the four locations. This script is the enforcement.
# It runs in CI on every push and pull request.
#
# Exit codes: 0 all agree, 1 they disagree, 2 a location could not be read.

set -euo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$ROOT"

PLUGIN_FILE="social-relay.php"
README_FILE="readme.txt"
CHANGELOG_FILE="CHANGELOG.md"

fail=0
note() { printf '%s\n' "$*"; }
die()  { printf 'ERROR: %s\n' "$*" >&2; exit 2; }

[ -f "$PLUGIN_FILE" ]    || die "$PLUGIN_FILE not found"
[ -f "$README_FILE" ]    || die "$README_FILE not found"
[ -f "$CHANGELOG_FILE" ] || die "$CHANGELOG_FILE not found"

# 1. Plugin header:  * Version: X.Y.Z
header_version=$(grep -m1 -iE '^\s*\*?\s*Version:' "$PLUGIN_FILE" \
    | sed -E 's/.*[Vv]ersion:[[:space:]]*//' | tr -d '[:space:]')

# 2. Constant:  define( 'SRL_VERSION', 'X.Y.Z' );
constant_version=$(grep -m1 -E "define\(\s*'SRL_VERSION'" "$PLUGIN_FILE" \
    | sed -E "s/.*'SRL_VERSION'\s*,\s*'([^']+)'.*/\1/")

# 3. readme.txt:  Stable tag: X.Y.Z
stable_tag=$(grep -m1 -iE '^Stable tag:' "$README_FILE" \
    | sed -E 's/.*[Ss]table tag:[[:space:]]*//' | tr -d '[:space:]')

# 4. CHANGELOG.md: first "## [X.Y.Z]" heading that is not [Unreleased]
changelog_version=$(grep -m1 -E '^##[[:space:]]+\[[0-9]' "$CHANGELOG_FILE" \
    | sed -E 's/^##[[:space:]]+\[([^]]+)\].*/\1/' || true)

[ -n "$header_version" ]   || die "could not read Version: from $PLUGIN_FILE"
[ -n "$constant_version" ] || die "could not read SRL_VERSION from $PLUGIN_FILE"
[ -n "$stable_tag" ]       || die "could not read Stable tag: from $README_FILE"

note "plugin header  : $header_version"
note "SRL_VERSION    : $constant_version"
note "readme.txt     : $stable_tag"
note "CHANGELOG.md   : ${changelog_version:-<none released yet>}"
note ""

if [ "$header_version" != "$constant_version" ]; then
    note "MISMATCH: plugin header ($header_version) != SRL_VERSION ($constant_version)"
    fail=1
fi

# A release candidate is intentionally NOT published as the stable tag.
# readme.txt must stay on the last stable release. See VERSIONING.md.
if [[ "$header_version" == *-rc.* ]]; then
    note "release candidate detected; readme.txt Stable tag is not required to match"
else
    if [ "$header_version" != "$stable_tag" ]; then
        note "MISMATCH: plugin header ($header_version) != readme.txt Stable tag ($stable_tag)"
        fail=1
    fi
    if [ -n "$changelog_version" ] && [ "$header_version" != "$changelog_version" ]; then
        note "MISMATCH: plugin header ($header_version) != CHANGELOG heading ($changelog_version)"
        fail=1
    fi
fi

# If a git tag exists for this commit, it must match too, and the changelog
# must have a heading for it. This is the check that stops a release shipping
# with an empty changelog entry.
if git describe --exact-match --tags HEAD >/dev/null 2>&1; then
    tag=$(git describe --exact-match --tags HEAD)
    tag_version="${tag#v}"
    note "git tag        : $tag"
    if [ "$tag_version" != "$header_version" ]; then
        note "MISMATCH: git tag ($tag_version) != plugin header ($header_version)"
        fail=1
    fi
    if ! grep -qE "^##[[:space:]]+\[${tag_version//./\\.}\]" "$CHANGELOG_FILE"; then
        note "MISSING: no CHANGELOG.md heading for released version $tag_version"
        fail=1
    fi
fi

if [ "$fail" -ne 0 ]; then
    note ""
    note "Version strings disagree. See VERSIONING.md."
    exit 1
fi

note "OK — all version strings agree."
