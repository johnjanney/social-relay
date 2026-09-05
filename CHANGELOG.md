# Changelog

All notable changes to Social Relay are documented in this file.

The format follows [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).
`VERSIONING.md` defines what counts as a breaking change for this plugin.

Every commit that changes behaviour adds its line here in the same commit.

## [Unreleased]

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

### Fixed

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

### Notes

Nothing is released yet. The plugin itself is not implemented in this section; the
entries above are project infrastructure. The first release will describe the
plugin's behaviour rather than its build system.

[Unreleased]: https://github.com/johnjanney/social-relay/compare/main...HEAD
