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

**Scope changed after the Specification Gate.** On 2026-09-05 the owner asked for hashtags built from the post's tags. `PROJECTBRIEF.md` §3 listed hashtag generation as a v1 non-goal, so this was a deliberate departure from the approved brief rather than a gap in it. It is built, specified in `SPEC.md` §7.6 as FR-4.13, recorded as **ADR-005**, and merged to `main` as `7848ef3` with all CI checks green. The owner then asked for the brief itself to be updated: `PROJECTBRIEF.md` is now at version **0.2** carrying **Amendment 1**, which removes hashtags from the non-goals, quotes the original wording, and adds FR-4.13. `README.md` is corrected to match, and `SPEC.md` is reconciled with it — its `Inputs` line, §1 scope statement, a new **Amendment 1** marker under the Specification Gate, and **OPEN-14** in §17. That reconciliation was missed when the feature merged, leaving `SPEC.md` briefly specifying in §7.6 a feature its own §1 excluded; caught by review on PR #2.

**Brief evidence labels swept — `PROJECTBRIEF.md` v0.3, amendment 2.** The brief was written before Phase 0 ran, so several of its `[VERIFIED]` / `[UNVERIFIED]` labels recorded what was known on the day rather than what the probe found. Every label is now reconciled against `OPENQUESTIONS.md`: four raised, one **downgraded** (the Project-membership claim, OQ-19), two correctly left `[UNVERIFIED]` because they remain unknown (OQ-1b, OQ-15). The PHP floor and CI matrix in the brief now read 8.2 rather than 8.1. Three instructions are annotated inline rather than rewritten, because the documents they describe are already correct and the risk is regenerating a correct document from a stale template: §8's `upload.x.com` allowlist (`SPEC.md` INV-3 is the binding form, §17 OPEN-7 records the departure), §12's `AGENTS.md` template repeating that allowlist, and §12's `INSTALLATION.md` template making the Project step unconditional. The last two were missed in the first pass and caught by review on PR #3. No requirement changed.

**OQ-20 closed, OQ-19 escalated — 2026-09-05.** X's Help Center confirms both premises behind the hashtag conversion rules, so `SPEC.md` §7.6.1 now carries the quotations as **[DOC]**. It is not **[MEASURED]**: `help.x.com` refuses automated fetch, so the wording comes from two independent search passes rather than a page read first-hand, and no live post was inspected. OQ-19 went the other way. The documentation evidence got stronger — two pages read directly show an App-centric console with no Project step — but a developer-forum thread titled "No Projects section in console, POST /2/tweets returns 403" argues against closing it, so the row's original claim that nothing in the plugin depends on it is **withdrawn**. If Project membership still gates write endpoints it affects `POST /2/tweets`, the one call the plugin cannot do without and the one Phase 0 never tested (**OQ-15**). OQ-19 and OQ-15 are now one check, settled the first time "Send test post" runs against the real API.

**Manual send for any published post — 2026-09-05.** The owner asked whether the admin could post *any* WordPress post to X. The brief's trigger in §1.5 is the transition to `publish`, and a post older than a day cannot even be re-triggered by unpublishing, because G-8 refuses it. Built as a third meta box button, "Post to X now", visible only on a published post in `none` or `cancelled`; it shares one helper with "Repost now" and schedules at delay 0 through the ordinary pipeline. `PROJECTBRIEF.md` is **v0.4** carrying **amendment 3** and **FR-2.6**; `SPEC.md` carries **amendment 2**, **TR-16**, FR-2.6, tests T-250 to T-253 and **OPEN-15**; **ADR-006** records the five sub-decisions. One behaviour change rides along: both manual buttons now set the per-post switch on, because the meta box form is submitted with the click and an unticked checkbox was cancelling the send the owner had just confirmed. Found while writing T-253, which drives the real `save_post` ordering.

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
| 14 | Hashtags from post tags | **done** | 8 unit tests, 1 integration test |
| 15 | Manual send for any published post | **done** | 4 integration tests |

## Verification

| Check | Status | Command |
|---|---|---|
| PHP syntax, every file | **passing** | `find . -path ./vendor -prune -o -name '*.php' -print0 \| xargs -0 -n1 php -l` |
| PHPCS (WordPress standard) | **passing** | `vendor/bin/phpcs` |
| PHPStan level 6 | **passing** | `vendor/bin/phpstan analyse` |
| PHPUnit unit suite | **passing, 45 tests** | `vendor/bin/phpunit --testsuite unit` |
| PHPUnit integration suite | **passing, 140 tests** | `wp-env` + `phpunit --testsuite integration` on PHP 8.2 / WP 6.5 |
| Full suite | **passing, 185 tests, 752 assertions** | run inside the wp-env tests container |
| Test-to-requirement mapping | **142 of 150 (94%)** | `bin/check-test-coverage.sh` |
| Release zip builds | **yes** | `bin/build.sh` — 26 files, no dev or spec files |
| Activates on a real site | **yes** | wp-env dev site: table created, defaults written with the switch off, both cron events scheduled |
| End-to-end on a real site | **yes** | published a post → `scheduled` + event created → ran `srl_send_post` → failed gracefully with reason `missing`, log row self-contained |
| Admin screens render | **yes** | settings page 4,467 bytes with all four panels; meta box renders with nonce and the correct FR-2.5 and FR-2.6 controls |
| CI on GitHub | **green** | <https://github.com/johnjanney/social-relay/actions> — all six jobs |

**Honest gap.** `SPEC.md` §16 names 150 tests. **142 exist (94%).** They cover the three pure-logic units, all nine scheduling guards, the compare-and-swap claim, the whole error matrix, the log schema and its versioning, the meta box save path, the owner actions, the notices, the security requirements, and uninstall. Still unwritten: the image downscale path (this workstation has neither GD nor Imagick, so `wp_get_image_editor()` cannot be exercised here), a few media-response variants, and the remaining admin-render permutations.

**CI is green.** Repository: <https://github.com/johnjanney/social-relay> (private). All six jobs pass:

| Job | Result |
|---|---|
| Static analysis and standards | PHPCS + PHPStan level 6, clean |
| Unit tests, PHP 8.2 | OK (37 tests, 109 assertions) |
| Unit tests, PHP 8.4 | OK (37 tests, 109 assertions) |
| Integration, WP 6.5 / PHP 8.2 | OK (135 tests, 577 assertions) |
| Integration, WP latest (7.1) / PHP 8.4 | OK (135 tests, 577 assertions) |
| Secrets and version consistency | clean |

The forward-compatibility result is worth noting: the whole suite passes on **WordPress 7.1 with PHP 8.4**, not only on the 6.5 / 8.2 floor.

The first CI run failed on `svn: command not found` — GitHub's runners no longer ship Subversion, which the canonical WordPress test-suite instructions assume. `bin/install-wp-tests.sh` now fetches the suite as a tarball from the wordpress-develop mirror instead, which removes the dependency rather than installing around it.

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
| Phase 6 acceptance | upgrading the verification marks in `INSTALLATION.md`, OPEN-5, OPEN-11 | A staging site with a sandbox X app |
| Phase 10 acceptance | Release Gate | 7 days on the owner's staging site with real cron |

`INSTALLATION.md` and `INSTRUCTIONS.md` were written on 2026-09-05 at the owner's explicit request, ahead of the Phase 6 acceptance run that brief §12 says should produce them. The concern was raised twice and overruled, which is the owner's call to make.

They are not guesses dressed as instructions. Every step in `INSTALLATION.md` carries a mark — **[PERFORMED]**, **[PARTLY PERFORMED]** or **[NOT PERFORMED]** — saying whether it was actually carried out. Steps 5, 6, 8, 9 and the uninstall section were performed on a real WordPress site, including installing the distributable zip through WordPress's own plugin installer. Steps 1 to 3 are from X's documentation and were not walked. Step 7 is partly performed and says exactly which half.

Every failure reason, status label, button label and numeric limit quoted in both documents was cross-checked against the source. The remaining work at Phase 6 is to walk the unperformed steps and upgrade the marks.
