#!/usr/bin/env bash
#
# check-test-coverage.sh — SPEC.md section 16.11.
#
# Two halves, because the first draft's check was self-referential and could
# never fail:
#
#   1. Every FR-x.y in SPEC section 13 and every TR-n in section 10.1 appears
#      somewhere in the test list. This checks the spec against itself.
#   2. Every T-n named in the test list corresponds to a method of that exact
#      name in tests/. This checks the spec against the code, and it is the
#      half that actually decays.
#
# Half 2 is advisory while the suite is incomplete: it reports the gap and the
# percentage rather than failing, and STATE.md carries the number. Set
# SRL_STRICT_COVERAGE=1 to make it blocking, which is the intent once the suite
# is complete.

set -uo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$ROOT"

SPEC="SPEC.md"
fail=0

[ -f "$SPEC" ] || { echo "ERROR: $SPEC not found" >&2; exit 2; }

# Split the spec at the test list.
spec_before=$(sed -n '1,/^## 16\. Test list/p' "$SPEC")
spec_tests=$(sed -n '/^## 16\. Test list/,$p' "$SPEC")

echo "== Half 1: every requirement has a named test =="

missing_reqs=""
for id in $(printf '%s' "$spec_before" | grep -oE '\b(FR-[0-9]+\.[0-9]+|TR-[0-9]+|SEC-[0-9]+)\b' | sort -u); do
    if ! printf '%s' "$spec_tests" | grep -qE "\b${id}\b"; then
        missing_reqs="$missing_reqs $id"
    fi
done

if [ -n "$missing_reqs" ]; then
    echo "  FAIL — no named test covers:$missing_reqs"
    fail=1
else
    n=$(printf '%s' "$spec_before" | grep -oE '\b(FR-[0-9]+\.[0-9]+|TR-[0-9]+|SEC-[0-9]+)\b' | sort -u | wc -l)
    echo "  OK — all $n requirement identifiers appear in the test list."
fi

echo ""
echo "== Half 2: every named test exists as a method =="

# Method names as the spec lists them, e.g. T-101 `test_something`.
named=$(printf '%s' "$spec_tests" | grep -oE '`test_[a-z0-9_]+`' | tr -d '`' | sort -u)
total=$(printf '%s\n' "$named" | grep -c . || true)

implemented=0
absent=""
for method in $named; do
    if grep -rqE "function ${method}\(" tests/ 2>/dev/null; then
        implemented=$((implemented + 1))
    else
        absent="$absent $method"
    fi
done

pct=0
[ "$total" -gt 0 ] && pct=$(( implemented * 100 / total ))

echo "  $implemented of $total named tests exist in tests/  (${pct}%)"

if [ -n "$absent" ]; then
    echo ""
    echo "  Not yet written:"
    for m in $absent; do echo "    $m"; done
fi

# Orphans: a test method in the suite that the spec does not name.
echo ""
echo "== Orphans: methods in tests/ that SPEC does not name =="
orphans=""
for method in $(grep -rhoE 'function (test_[a-z0-9_]+)\(' tests/ 2>/dev/null | sed -E 's/function (.*)\(/\1/' | sort -u); do
    if ! printf '%s' "$spec_tests" | grep -q "\`${method}\`"; then
        orphans="$orphans $method"
    fi
done

if [ -n "$orphans" ]; then
    echo "  These pass but are not traceable to a requirement:"
    for m in $orphans; do echo "    $m"; done
else
    echo "  None."
fi

echo ""
if [ "${SRL_STRICT_COVERAGE:-0}" = "1" ] && [ -n "$absent" ]; then
    echo "STRICT: failing because the suite is incomplete."
    exit 1
fi

exit "$fail"
