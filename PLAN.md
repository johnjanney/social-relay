# PLAN.md — Social Relay

Phase 3 artefact. Ordered build units, each sized to one session, each with the tests that prove it.

**Recorded honestly:** this file was written *after* the units were built, not before. The build followed the order below and the unit boundaries held, but a plan written retrospectively is weaker evidence than one written in advance, and saying so is worth more than pretending otherwise. `STATE.md` records what is actually done.

## Unit order and rationale

The order is dependency-driven: each unit depends only on units above it, so no unit is blocked waiting for one below.

| # | Unit | Depends on | Tests |
|---|---|---|---|
| 1 | **Bootstrap and settings** — plugin header, constants, class map, activation/deactivation, environment guard, `SRL_Settings` | — | T-110..T-113, T-103 |
| 2 | **Encryption** — `SRL_Crypto`, the `srl1:` envelope, the `credentials_unreadable` state | 1 | T-101, T-102, T-104..T-108 |
| 3 | **Provider signer** — `SRL_OAuth1`, RFC 5849 vector | — | T-900..T-908 |
| 4 | **Text algorithm** — `SRL_Text`, weighted length, composition, truncation | — | T-420..T-439 |
| 5 | **Log and usage** — `SRL_Log`, `SRL_Usage`, schema, versioning, pruning | 1 | T-150..T-153, T-140, T-141, T-500..T-502 |
| 6 | **Cron health** — `SRL_Cron_Health`, heartbeat, three-state panel | 5 | T-130..T-133 |
| 7 | **Scheduler** — `SRL_Scheduler`, nine guards, event arg typing, reconciliation | 1, 5, 6 | T-300..T-323 |
| 8 | **Publisher** — `SRL_Publisher`, the claim, the error matrix, backoff | 2, 3, 4, 5, 7 | T-400..T-419, T-450..T-480 |
| 9 | **Provider** — `SRL_X_Provider`, media upload, create post | 3, 4 | T-429..T-431, T-415..T-419 |
| 10 | **Meta box** — `SRL_Post_Meta`, the `save_post` path, status line | 7 | T-200..T-242, T-314, T-315 |
| 11 | **Notices** — `SRL_Notices`, failure notices, optional email | 8 | T-510, T-511, T-520, T-521 |
| 12 | **Admin screens** — settings page, log panel, usage panel | 5, 6, 10 | T-150, T-120..T-122 |
| 13 | **Uninstall** — `uninstall.php` | all | T-630 |

## Why units 3 and 4 have no dependencies

`SRL_OAuth1` and `SRL_Text` are deliberately WordPress-free. That is what lets the unit suite run them on a bare PHP install with no Docker and no database, and it is not a stylistic preference: both are places where a bug is invisible until it produces a wrong result hours later, and both turned out to contain real defects that only fixture-based testing caught.

## What each unit must leave behind

- Its tests, passing, with the command and output shown.
- `STATE.md` moved to `done` for that unit, in the same commit.
- A `CHANGELOG.md` line under `[Unreleased]`, in the same commit.

## Not in this plan

Phases 6, 7, 10 and 11 need things no coding session can supply: a real WordPress site, real X credentials, and seven days of elapsed time. They are listed in `STATE.md` as blocked, with what unblocks each.
