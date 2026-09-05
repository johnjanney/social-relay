# STATE.md — Social Relay

Current state of the build. Updated in the same commit as the code it describes.

**Last updated:** 2026-09-05

---

## Gates

| Gate | Status | What it needs |
|---|---|---|
| **Specification Gate** | **awaiting the owner** | The owner writes `APPROVED` and the date at the top of `SPEC.md`. The Phase 2 review is complete (`reviews/spec-review-1.md`, 29 findings, all applied). |
| **Quality Gate** | not reached | CI green on `main`, review findings closed, coverage report attached. Then the owner writes `QUALITY GATE PASSED` here. |
| **Release Gate** | not reached | Zero plugin-caused `failed` posts across the Phase 10 staging run. Then the owner writes `RELEASE GATE PASSED` here. |

**Note on order.** The owner instructed the build to continue without stopping at gates. Phases 3 to 5 were therefore built while the Specification Gate was still open. The spec has been independently reviewed and every finding applied, so the risk is low, but the gate is still the owner's to sign and the work below is provisional until they do.

---

## Build units

| # | Unit | Status | Evidence |
|---|---|---|---|
| 1 | Bootstrap and settings | **done** | `php -l` clean; PHPCS and PHPStan clean |
| 2 | Encryption | **done** | 11 unit tests |
| 3 | Provider signer | **done** | 7 unit tests, RFC 5849 vector |
| 4 | Text algorithm | **done** | 17 unit tests, 22/22 twitter-text conformance fixtures |
| 5 | Log and usage | **done** | schema + versioning written; integration tests pending |
| 6 | Cron health | **done** | three-state panel; integration tests pending |
| 7 | Scheduler | **done** | nine guards; integration tests pending |
| 8 | Publisher | **done** | claim, error matrix, backoff; integration tests pending |
| 9 | Provider | **done** | integration tests pending |
| 10 | Meta box | **done** | integration tests pending |
| 11 | Notices | **done** | integration tests pending |
| 12 | Admin screens | **done** | integration tests pending |
| 13 | Uninstall | **done** | integration tests pending |

## Verification

| Check | Status | Command |
|---|---|---|
| PHP syntax, every file | **passing** | `find . -path ./vendor -prune -o -name '*.php' -print0 \| xargs -0 -n1 php -l` |
| PHPCS (WordPress standard) | **passing** | `vendor/bin/phpcs` |
| PHPStan level 6 | **passing** | `vendor/bin/phpstan analyse` |
| PHPUnit unit suite | **passing, 35 tests** | `vendor/bin/phpunit --testsuite unit` |
| PHPUnit integration suite | **not yet written** | needs the WordPress test suite |
| CI on GitHub | **never run** | no remote is configured |

**Honest gap.** `SPEC.md` §16 names 132 tests. 35 exist. The 35 cover the three pure-logic units — the signer, the text algorithm and the crypto envelope — which is where the defects actually were. Everything WordPress-dependent (hooks, cron, meta, the claim, the error matrix, the admin screens) is written but **not yet tested**, and "written and linted" is not "verified". This is the single largest piece of outstanding work.

---

## Blocked on the owner

| Item | Blocks | What is needed |
|---|---|---|
| Specification Gate | formally, everything after Phase 2 | `APPROVED` + date at the top of `SPEC.md` |
| OPEN-4 | FR-1.5 design | Whether to add a "Check credentials" control beside "Send test post" |
| OPEN-13 | nothing | Whether 18 plugin files is acceptable against the brief's target of under 15 |
| OQ-1b | cost documentation | The Developer Console credit delta from the 2026-09-05 probe run |
| OQ-18 | FR-1.6 threshold | Whether Hostinger's hPanel offers every-minute cron |
| OQ-19 | `INSTALLATION.md` wording | Whether the X console still shows Projects |
| Phase 6 acceptance | `INSTALLATION.md`, `INSTRUCTIONS.md`, OPEN-5, OPEN-11 | A wp-env or staging site with a sandbox X app |
| Phase 10 acceptance | Release Gate | 7 days on the owner's staging site with real cron |

`INSTALLATION.md` and `INSTRUCTIONS.md` are deliberately unwritten: brief §12 requires them to be written *from* the Phase 6 acceptance run, so that every step has actually been performed rather than imagined.
