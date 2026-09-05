#!/usr/bin/env bash
#
# scan-secrets.sh — refuse to let a credential reach the repository.
#
# A secret committed by accident is not fixed by deleting it in a later commit;
# git keeps the history, and the credential must be treated as burned. That is
# why this blocks the merge rather than warning.
#
# Scans tracked files only. Excludes this script, which necessarily contains
# the patterns it looks for.

set -uo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$ROOT"

fail=0
report() { printf 'POSSIBLE SECRET: %s\n' "$*"; fail=1; }

# Files to scan: everything tracked by git, minus known-safe paths.
mapfile -t files < <(git ls-files \
    | grep -vE '^(vendor/|node_modules/|bin/scan-secrets\.sh$|LICENSE$)' \
    | grep -vE '\.(png|jpg|jpeg|gif|zip|woff2?|ttf)$')

if [ "${#files[@]}" -eq 0 ]; then
    echo "No tracked files to scan."
    exit 0
fi

# 1. An OAuth 1.0a access token: digits, hyphen, then 40+ base62 characters.
#    This is the shape the X console issues and the highest-value thing here.
if grep -nEH '[0-9]{15,25}-[A-Za-z0-9]{35,}' "${files[@]}" 2>/dev/null; then
    report "value shaped like an X OAuth 1.0a access token"
fi

# 2. A 50-character consumer/token secret assigned to a suggestive name.
if grep -nEHi "(api[_-]?secret|consumer[_-]?secret|token[_-]?secret|access[_-]?token|api[_-]?key)['\"]?\s*[:=]\s*['\"][A-Za-z0-9_-]{25,}['\"]" "${files[@]}" 2>/dev/null; then
    report "credential-shaped value assigned to a credential-named variable"
fi

# 3. A bearer token. X issues these with a long run of leading 'A's (~22).
#    A shorter prefix produces false positives: any base64 blob containing a
#    few zero bytes has an "AAAA" run, which is how the embedded test PNG in
#    bin/verify-x-api.php first tripped this check.
if grep -nEH 'AAAAAAAAAAAAAAAAAAAA[A-Za-z0-9%_-]{50,}' "${files[@]}" 2>/dev/null; then
    report "value shaped like an X bearer token"
fi

# 4. Private key blocks and generic provider keys.
if grep -nEH -- '-----BEGIN [A-Z ]*PRIVATE KEY-----' "${files[@]}" 2>/dev/null; then
    report "private key block"
fi
if grep -nEH '(AKIA[0-9A-Z]{16}|ghp_[A-Za-z0-9]{36}|sk-[A-Za-z0-9]{32,})' "${files[@]}" 2>/dev/null; then
    report "third-party provider key"
fi

# 5. A .env file must never be tracked, whatever it contains.
if git ls-files | grep -qE '(^|/)\.env($|\.)'; then
    report ".env file is tracked by git"
fi

if [ "$fail" -ne 0 ]; then
    echo ""
    echo "Secret scan failed. If a credential reached a commit, rotate it in the"
    echo "X Developer Console before doing anything else. Removing the line is"
    echo "not sufficient; the value is in the history."
    exit 1
fi

echo "OK — secret scan found nothing in ${#files[@]} tracked files."
