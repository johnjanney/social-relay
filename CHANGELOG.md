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

### Notes

Nothing is released yet. The plugin itself is not implemented in this section; the
entries above are project infrastructure. The first release will describe the
plugin's behaviour rather than its build system.

[Unreleased]: https://github.com/johnjanney/social-relay/compare/main...HEAD
