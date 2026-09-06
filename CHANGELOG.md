# Changelog

All notable changes to Social Relay are documented in this file.

The format follows [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).
`VERSIONING.md` defines what counts as a breaking change for this plugin.

Every commit that changes behaviour adds its line here in the same commit.

## [Unreleased]

Nothing yet.

## [0.3.0] - 2026-09-05

Adds a manual send for any published post. **MINOR** under `VERSIONING.md`: new
functionality, and nothing on that document's exhaustive breaking list applies. No
settings key, hook, post meta key, table column or floor changes; the one new
write is to an existing meta key, `_srl_enabled`, with a value it already takes.

Still **pre-release in the sense that matters**: no post from this plugin has
reached X in production, and the `POST /2/tweets` path remains untested against the
live API (OQ-15, bundled with OQ-19). The new button makes that test one click
away on any existing post.

### Added

- A "Post to X now" button in the post meta box, for a published post the
  automatic trigger never reached: one published before the plugin was installed,
  one skipped by the import, bulk-edit or freshness guards, one published with a
  switch off, or one whose scheduled post was cancelled. It asks for confirmation,
  schedules a single cron event at delay 0 like "Repost now", and never sends
  inline. It is refused from `sent`, `sending` and `failed`, and for any post that
  is not published. Brief v0.4 amendment 3, spec amendment 2, FR-2.6, TR-16,
  ADR-006. Four integration tests, T-250 to T-253.

### Changed

- Both "Repost now" and "Post to X now" now set the post's "Post to X" switch on.
  The button submits the whole meta box form, so on a site whose master switch is
  off the unticked checkbox was stored a moment before the click was handled, and
  the publisher's re-read then cancelled the send the owner had just confirmed
  with "Per-post switch was turned off". A confirmed click outranks a checkbox.

## [0.2.0] - 2026-09-05

Adds hashtags built from the post's own tags. **MINOR** under `VERSIONING.md`: new
functionality, and nothing on that document's exhaustive breaking list applies. The
two new settings keys carry safe defaults, which it names explicitly as not
breaking, so `srl_settings['schema_version']` stays at 1 and no migration runs.

Still **pre-release in the sense that matters**: no post from this plugin has
reached X in production, and the `POST /2/tweets` path remains untested against the
live API (OQ-15, now bundled with OQ-19).

### Added

- Hashtags built from the post's own tags, off by default and capped at three per
  post. Multi-word tags join in PascalCase, so `machine learning` becomes
  `#MachineLearning`; capitalisation the author typed is preserved, so `iPhone SE`
  becomes `#iPhoneSE` rather than `#IphoneSe`. Punctuation is removed rather than
  left in place, because X ends a hashtag at the first character outside its
  alphabet and the tag `co-op` would otherwise ship as `#co`. An all-digit tag
  produces no hashtag, because X does not link one. `SPEC.md` §7.6, FR-4.13,
  tests T-441 through T-449.
- Two settings: `hashtags_enabled` (default off) and `hashtags_max` (default 3,
  maximum 10).

### Changed

- `SRL_Text::compose()` takes a fifth argument, the hashtag list, and places it
  after the suffix and before the newline. Hashtags are dropped whole, from the
  end, whenever they would not fit; the title is never shortened to make room for
  one, and a title long enough to truncate on its own produces text identical to
  what it produced before this feature existed.
- `readme.txt` and `README.md` no longer list hashtags under "What it does not do",
  and `README.md` gains a section describing the feature.
- `PROJECTBRIEF.md` is amended to version **0.2**. §3 "Non-goals for v1" carries
  **Amendment 1**, which removes hashtags from the list, quotes the original
  wording in full, and explains that nothing is *generated* — every hashtag is a
  tag the author typed. FR-4.13 is added to §4 and FR-4.5's composition string now
  shows the hashtag block. ADR-005 records the original conflict as it stood at
  decision time rather than being rewritten, and carries an Update noting the
  amendment.
- `SPEC.md` reconciled with the amended brief: its `Inputs` line now names brief
  v0.2 and ADR-001..005, §1's "Not in scope" list no longer excludes hashtags, and
  an **Amendment 1** marker under the Specification Gate names everything the
  feature introduced — §7.6, FR-4.13, the two `hashtags_*` rows in §3, and tests
  T-441 through T-449. §17 gains **OPEN-14** for visibility. Without this the spec
  contradicted itself: §7.6 specified a feature §1 excluded, and §0 makes the spec
  the implementation contract. Found by review on PR #2.
- **OQ-20 closed.** X's Help Center confirms both premises `SPEC.md` §7.6.1 rests on:
  punctuation ends a hashtag where it occurs (`#it'sfun` is categorised under
  `#it`), and an all-digit hashtag is not hyperlinked while `#123go` works. §7.6.1
  now carries the quotations and is marked `[DOC]` rather than `[MEASURED]` —
  `help.x.com` returns HTTP 403 to automated fetch, so the wording comes from two
  independent search passes rather than a page read first-hand, and no live post
  was inspected.
- **OQ-19 stays open, with higher stakes than it was first given.** Two docs pages
  read directly show an App-centric console with no Project step, which
  strengthens the case that Projects are gone. But a developer-forum thread titled
  "No Projects section in console, POST /2/tweets returns 403" argues against
  closing it, and the row previously claimed nothing in the plugin depends on it.
  That claim is withdrawn in `PROJECTBRIEF.md` §1.2: if Project membership still
  gates write endpoints, it affects the one call the plugin cannot do without and
  the one Phase 0 never tested. OQ-19 and OQ-15 are now one check.
- `PROJECTBRIEF.md` amended again, to **v0.3**. **Amendment 2** sweeps every
  evidence label in the document against what Phase 0 actually established: the
  media-upload auth question becomes `[MEASURED]` (OQ-2, live HTTP 200), the 280
  character limit becomes `[VERIFIED]` (OQ-3), the signing-example question is
  settled via the RFC 5849 fallback (OQ-12), the PHP floor reads 8.2 and the CI
  matrix follows (OQ-5). The Project-membership claim is **downgraded** from
  `[VERIFIED]` to `[UNVERIFIED]` (OQ-19), because correcting only the labels that
  improved would have left the one misleading claim standing. Two labels stay
  `[UNVERIFIED]` on purpose — they are unknown, not stale (OQ-1b, OQ-15). §8's
  `upload.x.com` allowlist is annotated inline rather than rewritten, since
  `SPEC.md` INV-3 already contradicts it and the original instruction is worth
  keeping visible. §12's `AGENTS.md` and `INSTALLATION.md` templates are annotated
  the same way: they repeat the forbidden host and the unconditional Project step,
  and §12 prescribes revising both documents after Phase 8, so an unannotated
  template would regress two files that are currently correct. No requirement
  changed.

## [0.1.0] - 2026-09-05

First packaged build. **Pre-release: this has never posted to X in production.**

### Added

- Project scaffolding: `composer.json` with dev-only dependencies, PHPCS with the
  WordPress Coding Standards, PHPStan at level 6, and a PHPUnit configuration that
  splits a WordPress-free unit suite from a WordPress integration suite.
- `bin/verify-x-api.php`, a standalone Phase 0 diagnostic that proves an OAuth 1.0a
  signature against the live X API without any WordPress dependency. Not shipped in
  the plugin zip.
- `bin/check-version.sh`, `bin/build.sh`, `bin/scan-secrets.sh` and
  `bin/install-wp-tests.sh`.
- GitHub Actions CI: static analysis, a unit-test matrix on PHP 8.2 and 8.4, an
  integration matrix on WordPress 6.5 and latest, plus secret and version guards.

- OAuth 1.0a signer (`SRL_OAuth1`), verified against the RFC 5849 §3.4.1.1 vector.
- Weighted-length text algorithm (`SRL_Text`), verified against X's own published
  `twitter-text` conformance fixtures (22/22).
- Log table, usage counter and cron health classes.
- Settings storage with encrypted credentials and a `credentials_unreadable`
  state that names salt rotation as the cause rather than blaming the keys.
- Scheduler with nine guards, including skips for imports, bulk edit and posts
  older than the freshness window.
- Send pipeline with an atomic compare-and-swap claim, the 5/15/60-minute retry
  backoff, and media-id reuse across retries.
- Meta box, settings page, admin notices, and uninstall.
- "Check credentials" control (FR-1.9): asks X who the stored keys belong to for
  about $0.010 and publishes nothing, so the owner can verify keys without putting
  a test message on their timeline.

### Fixed

Phase 7 code review, all 16 findings applied:

- **Blocker:** `truncate()` measured with literal weights while `compose()`
  budgeted with URL weights, so a truncated title overflowed X's 280 limit.
  An ordinary headline naming four products by domain composed to 320 — a
  terminal HTTP 400 and a burned paid call. `compose()` now measures the
  assembled string, not the parts.
- A 403 duplicate-content rejection was classified as `auth`, making the entire
  duplicate safety net dead code and misdirecting the owner to their credentials
  on a request where a post may have gone live.
- Three of the four paths into `failed` never raised the admin notice — exactly
  the failures that happen while nobody is watching.
- The failure notice could never be dismissed; it now carries a nonced link that
  works without JavaScript.
- `reconcile()` could starve: the batch filled with healthy, newest-first posts
  while genuinely stuck older ones were never examined, and trashed posts were
  invisible to it entirely.
- `run()` could overwrite a terminal status with `cancelled`, re-opening the
  duplicate-post guard.
- The retry budget was never reset when a failed post was republished.
- Per-post delay and switch were ignored in the block editor.
- No size guard before a billed image upload; the multipart filename was
  interpolated unescaped; `x-rate-limit-reset` was ignored; `sanitize()` could
  double-encrypt; uninstall left secrets behind on multisite.

- The one-minute cron schedule was registered on `plugins_loaded`, which does not
  fire for the plugin being activated, so the heartbeat was never scheduled. The
  cron health panel could never turn green and the reconciliation scan never ran.
  Found by activating the plugin on a real WordPress site.
- A terminally failed post kept its scheduled event, and a stale event inside
  WordPress's ten-minute duplicate window would silently swallow the owner's next
  "Repost now".

- Three counting defects found by testing against X's fixtures rather than against
  the plugin's own counter: PCRE2's `\X` merges adjacent ZWJ emoji (140 family emoji
  counted 2 instead of 280); an over-permissive URL matcher counted a 12,000-character
  invalid URL as 23; and U+2026 weighs 2, not 1, so every truncated post overflowed by
  exactly one unit.

### Known limitations

- Never exercised against the live X API beyond the Phase 0 authentication probe.
  Everything below is verified against the specification and against real
  WordPress, which is not the same as verified in production.
- `INSTALLATION.md` steps 1 to 3 (the X Developer Console) were written from
  official documentation and not walked; each step in that file carries a mark
  saying whether it was actually performed.
- Image downscaling is not implemented. Images over 5 MB are skipped with a
  recorded reason rather than resized, and the post still goes out.
- The plugin has not run for an extended period on a live site. Phase 10 of the
  project plan is a seven-day staging run, and it has not happened.

[Unreleased]: https://github.com/johnjanney/social-relay/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/johnjanney/social-relay/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/johnjanney/social-relay/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/johnjanney/social-relay/releases/tag/v0.1.0
