# VERSIONING.md — Social Relay

## The rule

This project follows **Semantic Versioning 2.0.0** — <https://semver.org/spec/v2.0.0.html>.

Given `MAJOR.MINOR.PATCH`:

- **MAJOR** — a breaking change, as defined below.
- **MINOR** — new functionality that does not break anything defined below.
- **PATCH** — a bug fix that does not break anything defined below.

## What counts as breaking, for a WordPress plugin

A version is **MAJOR** if any of these is true. This list is exhaustive; anything not on it is not breaking.

1. **The settings schema changes in a way that needs a migration.** Renaming, removing, or changing the meaning of a key in `srl_settings`. Adding a key with a safe default is not breaking. The `schema_version` value in `srl_settings` is bumped in the same release.
2. **A filter or action hook is removed or its signature changes.** The published hooks are listed in `SPEC.md` §15. Adding a hook is not breaking. Changing what a hook is passed, or when it fires, is.
3. **The PHP floor or the WordPress floor is raised.** Sites below the new floor stop receiving the update, which is a breaking change from the site owner's point of view even though nothing in the code broke.
4. **The post meta schema changes** such that an existing post's recorded state would be misread. The keys are listed in `SPEC.md` §4.
5. **The log table schema changes destructively** — a dropped column, or a changed column meaning.
6. **The secret storage envelope format changes without backward reads.** The envelope carries a version byte precisely so that a format change can be MINOR: a new writer with an old reader is breaking, a new reader that still understands the old format is not.

Note on floors: PHP 8.2 leaves security support on 2026-12-31. A later raise to 8.3 is therefore expected, is breaking under rule 3, and should be planned rather than treated as a surprise.

## Where the version string lives

The version appears in **four** places and they must agree exactly:

| # | Location | Form |
|---|---|---|
| 1 | `social-relay.php` plugin header | `Version: X.Y.Z` |
| 2 | `social-relay.php` constant | `define( 'SRL_VERSION', 'X.Y.Z' );` |
| 3 | `readme.txt` | `Stable tag: X.Y.Z` |
| 4 | `CHANGELOG.md` | `## [X.Y.Z] - YYYY-MM-DD` heading |

`bin/check-version.sh` extracts all four and exits non-zero if they disagree. It runs in CI on every push and pull request, and it is the reason a release cannot ship with a mismatched header.

A release candidate uses `X.Y.Z-rc.N` in locations 1, 2 and 4. `readme.txt`'s `Stable tag` is **not** advanced to a release candidate; it stays on the last stable release, because that field controls what WordPress.org serves to existing installs.

## Branch and tag policy

- **`main` is always releasable.** CI green on `main` is a precondition, not an aspiration.
- Work happens on branches and merges into `main` via pull request. CI blocks the merge.
- Releases are tagged **`vX.Y.Z`**. Release candidates are tagged **`vX.Y.Z-rc.N`**.
- A tag is created only from a commit where CI is green.
- CI fails if a version tag exists with no matching `CHANGELOG.md` heading.

## Changelog discipline

`CHANGELOG.md` follows Keep a Changelog 1.1.0 and carries an `[Unreleased]` section at the top at all times.

**Every commit that changes behaviour adds its line to `[Unreleased]` in the same commit.** Not afterwards, not at release time. A changelog written at release time is a work of fiction assembled from `git log`.

At release, `[Unreleased]` becomes `[X.Y.Z] - YYYY-MM-DD` and a fresh empty `[Unreleased]` is opened above it.
