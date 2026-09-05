# AGENTS.md — Social Relay

Vendor-neutral instructions for any AI coding agent working in this repository.
`CLAUDE.md` imports this file and adds nothing but Claude-specific notes.

## Purpose

Publish the title, featured image, and permalink of each newly published WordPress post to one X account, after a configurable delay, with no duplicate posts and no silent failures.

## The one rule that outranks the others

**Simple is a hard constraint, not a preference.** When two designs meet a requirement, take the one with less code and fewer moving parts. This is `PROJECTBRIEF.md` §0.5 and it has already decided several things: no scheduler library, no OAuth library, no Composer runtime dependencies, one-shot media upload instead of the three-step flow.

## Invariants

A change that breaks one of these is a defect regardless of what else it achieves. Full statements in `SPEC.md` §2.1.

1. **A post is sent at most once, ever** — unless the owner explicitly clicks "Repost now". Enforced by persisted post meta, never by in-memory state. The compare-and-swap in `SPEC.md` §11.4 is what makes this true; do not replace it with `update_post_meta()`.
2. **Never call the X API during an editor save request**, or any request a human is waiting on. Every send happens inside a cron event. This is why delay 0 still goes through the scheduler.
3. **Outbound requests go to `api.x.com` only.** The host is a constant. It is never read from settings, a filter, or the database. `upload.x.com` is the legacy v1.1 host and is forbidden.
4. **Every query is prepared.** No interpolated variable ever reaches `$wpdb->query()`.
5. **A failure is always recorded.** Nothing is swallowed into silence.
6. **The featured image never blocks the post.** If the image fails, the text-and-URL post still goes out.

## File map

```
social-relay/
  social-relay.php          bootstrap, constants, autoloader, activation/deactivation
  includes/
    class-plugin.php        wires hooks
    class-settings.php      options page, sanitization, encryption
    class-scheduler.php     transition_post_status -> wp_schedule_single_event
    class-publisher.php     the send pipeline
    class-log.php           table install + write/read
    class-post-meta.php     meta box + per-post fields
    class-cron-health.php   last-run tracking + warning
    class-usage.php         API request counter
    providers/
      interface-provider.php
      class-x-provider.php  OAuth 1.0a signing, media upload, create post
  admin/
    settings-page.php       template
    meta-box.php            template
  uninstall.php
  readme.txt
```

Target: under 15 PHP files, no build step.

## Commands

```bash
composer install                      # dev dependencies

vendor/bin/phpunit --testsuite unit   # no WordPress needed, runs anywhere
vendor/bin/phpunit                    # needs the WP test suite (see below)
vendor/bin/phpcs                      # WordPress Coding Standards
vendor/bin/phpstan analyse            # level 6
find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l

bin/install-wp-tests.sh wordpress_test root '' localhost 6.5   # WP test suite
npx wp-env start                      # local WordPress, needs Docker

bin/check-version.sh                  # the four version strings must agree
bin/scan-secrets.sh                   # blocks credential-shaped strings
bin/build.sh                          # distributable zip
```

## Documents and where truth lives

| Question | File |
|---|---|
| What the owner asked for | `PROJECTBRIEF.md` |
| What we are building, exactly | `SPEC.md` |
| Why a decision was made | `DECISIONS.md` |
| What is still unknown | `OPENQUESTIONS.md` |
| What is built and what is next | `STATE.md`, `PLAN.md` |
| What changed | `CHANGELOG.md` |
| Reviews | `reviews/` |

`SPEC.md` is the implementation contract. If the code and the spec disagree, one of them is wrong and it must be resolved, not worked around.

## Working rules

- **`STATE.md` and `CHANGELOG.md` update in the same commit as the code**, not afterwards. A changelog assembled later from `git log` is a work of fiction.
- **A self-reported "tests pass" is not accepted.** Show the command and its output. `PROJECTBRIEF.md` §10: model self-reports are not evidence.
- **Do not report a check as done without showing it.** This applies to lint, static analysis, and tests equally.
- **When the brief and a source disagree, say so and quote the source.** Do not silently pick one. There are four such disagreements already recorded in `OPENQUESTIONS.md`.
- **When a question has no verifiable answer, say "unverified"** and leave the row open in `OPENQUESTIONS.md`.
- **Every FR in `SPEC.md` §13 has at least one named test** in §16. CI asserts this mapping. Adding a requirement without a test breaks the build, by design.
- **Declining a review finding is allowed; declining it silently is not.** A declined finding becomes an ADR in `DECISIONS.md`.

## Things that look like bugs and are not

- **Delay means "not before", never "at".** WP-Cron fires at the first run at or after the scheduled time. This is documented behaviour, not drift.
- **`bin/verify-x-api.php` has no WordPress dependency and does not follow WPCS.** It is a standalone Phase 0 diagnostic, excluded from the ruleset in `phpcs.xml` and excluded from the shipped zip.
- **`SPEC.md` §11.4 uses a direct `$wpdb->query()`.** That is the only correct way to do an atomic compare-and-swap here, and it is still fully prepared, so invariant 4 holds.
- **The plugin source stays PHP 8.1-parseable** even though the declared floor is 8.2, so `php -l` works on older workstations. The floor is enforced statically by PHPCS `testVersion 8.2-`.
