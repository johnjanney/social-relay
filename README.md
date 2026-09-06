# Social Relay

Publishes the title, featured image, and permalink of each newly published WordPress post to one X account, after a configurable delay, with no duplicate posts and no silent failures.

That is the whole feature. Everything below is about the three things that make it harder than it sounds: X charges per post, WordPress's scheduler is not a clock, and "newly published" is not the same as "saved".

## This costs money

X ended its free API tier for new developers on 2026-02-06. Every post this plugin makes contains a link, and links are the expensive case.

| What | Price |
|---|---|
| A post without a link | $0.015 |
| **A post with a link — every post this plugin makes** | **$0.200** |
| Media upload | not published; measure it in your Developer Console |

Twenty posts a month is about **$4**. Five hundred posts a month is about **$100**. You buy credits up front in the X Developer Console. Rates were read from <https://docs.x.com/x-api/getting-started/pricing> on 2026-09-05 and are set by X, not by this plugin.

The plugin counts every request it makes, by endpoint, so you can reconcile against your invoice. That count includes failed requests, because X bills for those too. Treat it as indicative; the Developer Console is authoritative.

## The delay needs real cron

WP-Cron only runs when someone visits your site. On a quiet site a 60-minute delay can become three hours, and page caching makes it worse because a cached page never boots WordPress.

**The delay means "not before", never "at".** The post goes out at the first cron run at or after the scheduled time.

`INSTALLATION.md` has the one configuration change that makes the delay accurate: disable the built-in trigger and call `wp-cron.php` from a real system cron every minute. The settings page shows whether that is actually working, and it will not show green until it is — including on sites where loading the page runs cron itself, which would otherwise make a broken setup look fine.

## What it does not do

- Other networks. The provider interface is built so a second one is one new class, but only X is implemented.
- More than one X account.
- OAuth 2.0 PKCE. You paste four keys once; there is no login flow to expire.
- Message templates. An optional prefix and suffix, nothing more.
- Hashtag generation, AI captions, link shortening, UTM tags.
- Post types other than `post`. The list is filterable but ships with one entry.
- Analytics or any read endpoint. Reads cost money and add nothing to the goal.
- Multisite network activation.
- A Gutenberg sidebar panel. A classic meta box works in both editors.

## How a post moves

```
none ──publish, all guards pass──▶ scheduled ──event fires──▶ sending ──2xx──▶ sent
  ▲                                    │                        │
  │                                    │ unpublish/trash        ├──429/5xx/transport, attempts<=3──▶ scheduled
  │                                    ▼                        │
  │                                cancelled                    ├──4xx, or attempts=4──▶ failed
  │                                                             │
cancelled / failed ──republish──▶ scheduled                     └──stale >15 min──▶ failed

sent ──republish──▶ sent          (no-op: only "Repost now" produces a second post)
scheduled ──schedule failed or event lost──▶ failed
scheduled ──"Cancel scheduled post"──▶ cancelled
sent / failed ──"Repost now" + confirm──▶ scheduled (delay 0)
```

A post is sent **at most once, ever**. Unpublishing and republishing does not send it again; neither does untrashing, nor duplicating it with a clone plugin. The only path to a second post is the "Repost now" button, which asks for confirmation.

## Security, and its limit

The four X credentials are encrypted before they reach the database, with `sodium_crypto_secretbox` keyed from your site's `auth` salt. A stolen database dump alone does not yield working credentials.

**This is defence in depth, not a guarantee.** The key derives from `wp-config.php`, so anyone holding both your database *and* your files can decrypt them. Treat the four keys as you would a password.

If your site's salts are rotated — routine after a security incident, and something several security plugins do automatically — the stored keys become unreadable. The plugin detects this specifically and tells you that salt rotation is the cause and that re-entering the keys fixes it. It will not tell you your credentials are invalid, because they are not, and sending you to regenerate working keys would waste your time at the worst possible moment.

## Documents

| File | What it is |
|---|---|
| [PROJECTBRIEF.md](PROJECTBRIEF.md) | What was asked for |
| [SPEC.md](SPEC.md) | What was built, exactly |
| [DECISIONS.md](DECISIONS.md) | Why, including the arguments against |
| [OPENQUESTIONS.md](OPENQUESTIONS.md) | What is still unknown, and what was verified how |
| [PLAN.md](PLAN.md) / [STATE.md](STATE.md) | Build order, and what is actually done |
| [VERSIONING.md](VERSIONING.md) | What counts as a breaking change here |
| [CHANGELOG.md](CHANGELOG.md) | What changed |
| [AGENTS.md](AGENTS.md) | Instructions for AI coding agents |
| `reviews/` | Independent review findings |
| [INSTALLATION.md](INSTALLATION.md) | Setting it up, for a site administrator. Each step marked with whether it was actually performed |
| [INSTRUCTIONS.md](INSTRUCTIONS.md) | Using it, for whoever writes the posts |

## Status

[![CI](https://github.com/johnjanney/social-relay/actions/workflows/ci.yml/badge.svg)](https://github.com/johnjanney/social-relay/actions/workflows/ci.yml)

**Not released.** The plugin is implemented and passes PHPCS, PHPStan level 6, and its unit suite; the WordPress-dependent tests are not yet written, and it has never run on a real site. `STATE.md` is the honest account of what is done and what is not.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
