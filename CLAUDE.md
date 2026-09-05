@AGENTS.md

## Claude-specific notes

- The scratchpad directory in the session prompt is for temporary files. Nothing temporary belongs in the repository.
- Phase 2 and Phase 7 reviews are **fresh-context sessions by design**. Their independence is the point, so run them as a subagent with a review-only prompt rather than reviewing your own work in the same context.
- `bin/verify-x-api.php` makes real, billed API calls against the owner's X account. Never run it. The owner runs it and pastes the output back. `--dry-run` makes no network calls and is safe.
- Prefer running the WordPress-free suite (`vendor/bin/phpunit --testsuite unit`) when iterating. It needs no Docker and gives real evidence in seconds.
