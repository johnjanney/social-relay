# Changelog

All notable changes to Social Relay are documented in this file.

The format follows [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).
`VERSIONING.md` defines what counts as a breaking change for this plugin.

Every commit that changes behaviour adds its line here in the same commit.

## [Unreleased]

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

[Unreleased]: https://github.com/johnjanney/social-relay/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/johnjanney/social-relay/releases/tag/v0.1.0
