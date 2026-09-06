# STATE.md — Social Relay

Current state of the build. Updated in the same commit as the code it describes.

**Last updated:** 2026-09-05

---

## Gates

| Gate | Status | What it needs |
|---|---|---|
| **Specification Gate** | **PASSED 2026-09-05** | Owner approved `SPEC.md`, with OPEN-4 accepted (add a "Check credentials" control, now FR-1.9) and OPEN-13 accepted (18 plugin files stand). |
| **Quality Gate** | not reached | CI green on `main`, review findings closed, coverage report attached. Then the owner writes `QUALITY GATE PASSED` here. |
| **Release Gate** | not reached | Zero plugin-caused `failed` posts across the Phase 10 staging run. Then the owner writes `RELEASE GATE PASSED` here. |

**Note on order.** The owner instructed the build to continue without stopping at gates, so phases 3 to 5 were built while the Specification Gate was still open. The gate has since passed, retroactively covering that work. Nothing in the approved spec required a change to code already written, apart from the FR-1.9 addition the owner asked for at the same time.

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
| PHPUnit unit suite | **passing, 39 tests** | `vendor/bin/phpunit --testsuite unit` |
| PHPUnit integration suite | **passing, 133 tests** | `wp-env` + `phpunit --testsuite integration` on PHP 8.2 / WP 6.5 |
| Full suite | **passing, 172 tests, 686 assertions** | run inside the wp-env tests container |
| Test-to-requirement mapping | **129 of 137 (94%)** | `bin/check-test-coverage.sh` |
| Release zip builds | **yes** | `bin/build.sh` — 26 files, no dev or spec files |
| Activates on a real site | **yes** | wp-env dev site: table created, defaults written with the switch off, both cron events scheduled |
| End-to-end on a real site | **yes** | published a post → `scheduled` + event created → ran `srl_send_post` → failed gracefully with reason `missing`, log row self-contained |
| Admin screens render | **yes** | settings page 4,467 bytes with all four panels; meta box renders with nonce and the correct FR-2.5 controls |
| CI on GitHub | **never run** | no remote is configured |

**Honest gap.** `SPEC.md` §16 names 137 tests. **129 exist (94%).** They cover the three pure-logic units, all nine scheduling guards, the compare-and-swap claim, the whole error matrix, the log schema and its versioning, the meta box save path, the owner actions, the notices, the security requirements, and uninstall. Still unwritten: the image downscale path (this workstation has neither GD nor Imagick, so `wp_get_image_editor()` cannot be exercised here), a few media-response variants, and the remaining admin-render permutations.

**CI has never run.** There is no git remote configured, so `.github/workflows/ci.yml` is unexecuted. Everything above was run locally: PHPCS, PHPStan and the unit suite on the workstation, and the full suite inside the wp-env container on PHP 8.2 with WordPress 6.5.

**Three numbered requirements had UI but no behaviour** — FR-1.5 "Send test post" did not exist, and the meta box's "Cancel scheduled post" and "Repost now" buttons submitted to nothing. Found by implementing SPEC §16.11's coverage check and reading which named tests had no method: several were unwritten because the feature was.

Real defects found by testing against real WordPress, and fixed:

- The one-minute cron schedule was registered on `plugins_loaded`, which does not fire for the plugin being activated, so the heartbeat never got scheduled. No heartbeat means no reconciliation scan and no honest cron health reading — INV-7 had no enforcement at all. Found by activating the plugin on a real site, not by any test.

- `reconcile()` marked a post `failed` but left its event scheduled. A stale event inside WordPress's ten-minute duplicate window would have silently swallowed the owner's next "Repost now".
- The same hole existed on every terminal failure in `apply_result()`.

---

## Reviews

| Phase | Review | Findings | Outcome |
|---|---|---|---|
| 2 | `reviews/spec-review-1.md` | 29 (4 blocker, 13 major, 11 minor, 1 question) | All accepted and applied. None declined, so no ADR was opened. |
| 7 | `reviews/code-review-1.md` | 16 (1 blocker, 10 major, 5 minor) | All accepted and applied. 13 regression tests reproduce them. |

The Phase 7 blocker is worth remembering: `truncate()` measured with a different
function than `compose()` budgeted with, so a headline naming four products by
domain composed to 320 weighted units against a limit of 280. Every such post
would have been a terminal HTTP 400 and a burned paid call, and it was invisible
until a title happened to be long enough to truncate.

Finding 16 was about the test suite itself, and it was correct: several tests
passed for the wrong reason, including the one written for the duplicate-content
bug, which asserted one level too shallow to see it.

## Blocked on the owner

| Item | Blocks | What is needed |
|---|---|---|
| OQ-1b | cost documentation | The Developer Console credit delta from the 2026-09-05 probe run |
| OQ-18 | FR-1.6 threshold | Whether Hostinger's hPanel offers every-minute cron |
| OQ-19 | `INSTALLATION.md` wording | Whether the X console still shows Projects |
| Phase 6 acceptance | `INSTALLATION.md`, `INSTRUCTIONS.md`, OPEN-5, OPEN-11 | A wp-env or staging site with a sandbox X app |
| Phase 10 acceptance | Release Gate | 7 days on the owner's staging site with real cron |

`INSTALLATION.md` and `INSTRUCTIONS.md` are deliberately unwritten: brief §12 requires them to be written *from* the Phase 6 acceptance run, so that every step has actually been performed rather than imagined.
