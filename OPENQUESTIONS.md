# OPENQUESTIONS.md — Social Relay

Seeded in Phase 0 from `PROJECTBRIEF.md` Section 13 and every **[UNVERIFIED]** claim in Section 1.

A row with `Blocking = yes` and `Status = open` blocks the Specification Gate (Section 11).
Rows are never deleted. When a row resolves, the answer moves into `SPEC.md` or `DECISIONS.md` and the row is marked `resolved`.

**Evidence labels** (Section 0):
- **VERIFIED** — checked against a named source, with URL and read date, or against a real API response.
- **INFERRED** — a reasoned conclusion from verified facts. Never enough to close a blocking row on its own.
- **UNVERIFIED** — not checked. The row stays open.

All web sources in this file were read on **2026-09-05**.

---

## Table

| ID | Question | Owner | Blocking | Status | Resolution and date |
|---|---|---|---|---|---|
| OQ-1a | Confirm the current X rate card for `POST /2/tweets`, with and without a URL. | Claude | yes | resolved | **VERIFIED 2026-09-05.** <https://docs.x.com/x-api/getting-started/pricing> — write-operations table: `Post: Create` = **$0.015 per request**; `Post: Create (with URL)` = **$0.200 per request**. Agrees with brief §1.1. |
| OQ-1b | Confirm the media-upload price under pay-per-use. | Claude | no | open | **UNVERIFIED 2026-09-05.** <https://docs.x.com/x-api/getting-started/pricing> lists no row for any `/2/media/upload` endpoint in either the write-operations or webhook-events tables. Absence from the table is **not** evidence that the call is free. Brief §1.1 already directs that this be measured in Phase 10 against the Developer Console usage report; marked non-blocking on that instruction. |
| OQ-2 | Confirm `POST /2/media/upload` accepts OAuth 1.0a user context. Make one real call. | John (runs), Claude (wrote script) | **yes** | **open** | **Documentation and field reports disagree. See "Conflict: OQ-2" below.** Docs side **VERIFIED**: <https://docs.x.com/x-api/media/media-upload-initialize> lists two authorization schemes — `OAuth2UserToken: [media.write]` and `UserToken: []`, where `UserToken` is OAuth 1.0a User Context. Field-report side **UNVERIFIED**: two X developer-forum threads titled "Access to /2/media/upload using OAuth 1.0a User Context is Forbidden" and "Request for OAuth 1.0a Compatibility with /2/media/upload Endpoint" appear in search results, but `devcommunity.x.com` returned **HTTP 403** to my fetch, so I have the titles only, not the thread bodies or any X staff reply. **A real call is required.** `bin/verify-x-api.php` is written and unrun; John runs it. |
| OQ-3 | Confirm the current post length limit and URL character weight. | Claude | yes | resolved | **VERIFIED 2026-09-05.** <https://docs.x.com/fundamentals/counting-characters> — "Posts on X can contain up to **280 characters**"; "All URLs are wrapped with `t.co` shortener and count as **23 characters**, regardless of the original length." Weighting: most characters count 1; emoji, CJK and some other Unicode count 2. Agrees with brief §4 FR-4.5. Note for SPEC: the truncation routine must use weighted counting, not `strlen()` or `mb_strlen()`. Premium/verified-org longer limits are out of scope (§3 targets one standard account); the plugin assumes 280 and does not detect account tier. |
| OQ-4 | Plugin name and slug. Check WordPress.org conflict and the "X"/"Twitter" trademark constraint. | **John** | **yes** | **open** | Two halves. **Slug — VERIFIED 2026-09-05:** `https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=social-relay` returns `{"error":"Plugin not found."}` (HTTP 404), and `https://wordpress.org/plugins/social-relay/` 301-redirects to `/plugins/search/social-relay/`, which is the directory's behaviour for an unused slug. So `social-relay` is free. A separate plugin with slug `relay` exists; no directory plugin is named "Social Relay". **Trademark — VERIFIED 2026-09-05:** <https://docs.x.com/developer-terms/agreement> (page states last updated 2026-04-27), §III.F "Use of X Marks": "You shall not include any of the X Marks in your registered corporate name(s), your logos, or your service or product names." This rules out a name containing "X" or "Twitter" as a brand element. **Name decision is John's — asked in Q1.** |
| OQ-5 | PHP floor: 8.1 or 8.2? | **John** | **yes** | **open** | Evidence gathered, decision is John's — **asked in Q2**. **VERIFIED 2026-09-05:** <https://www.php.net/supported-versions.php> — PHP 8.1 is **not listed** among supported versions (end of life; its security support ended 2025-12-31). PHP 8.2 security support ends 2026-12-31, 8.3 ends 2027-12-31, 8.4 ends 2028-12-31. **VERIFIED 2026-09-05:** `https://api.wordpress.org/stats/php/1.0/` — 8.2: 24.816%, 8.3: 25.094%, 8.4: 8.455%, 8.5: 2.913%, 8.6: 0.012%, 8.1: 11.489%. Computed: **PHP ≥ 8.2 = 61.29%** of WordPress installs, **PHP ≥ 8.1 = 72.78%**. |
| OQ-6 | License. Confirm GPL-2.0-or-later. | **John** | **yes** | **open** | Decision is John's — **asked in Q3**. **Brief and source disagree — see "Conflict: OQ-6" below.** **VERIFIED 2026-09-05:** <https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/> guideline 1: "Although any GPL-compatible license is acceptable, using the same license as WordPress—'GPLv2 or later' — is strongly recommended." |
| OQ-7 | Should "Send test post" include a URL, so it tests the exact billing path at $0.20 per test? | John | no | open | Brief recommends **no**. I agree. Cost ratio is now confirmed at 13.3× ($0.200 vs $0.015, OQ-1a). A URL-less test still exercises auth, signing, and `POST /2/tweets`; the only untested difference is X's own URL detection, which the plugin does not control. Recommend: keep the test URL-less and state the $0.015-vs-$0.200 difference on the settings page next to the button. |
| OQ-8 | Should WordPress `future` posts use the delay from their scheduled time, or from publish time? | John | no | open | Brief recommends **publish time + delay**. I agree. `transition_post_status` fires `future → publish` at the moment WP-Cron actually publishes the post, so "publish time" is already the correct and only clock the plugin observes. Any other reading would need a second scheduling path. Consistent with §0's simplicity constraint. |
| OQ-9 | Downscale large images, or reject and post without an image? | John | no | open | Brief recommends **downscale with `wp_get_image_editor()`**. I agree. Pixel/byte limits to be pinned in `SPEC.md`; see OQ-13 for the byte limit source. |
| OQ-10 | Email on failure: default on or off? | John | no | open | Brief recommends **off**. I agree. FR-5.2's admin notice already surfaces failures inside wp-admin; email default-on risks noise and deliverability problems on a fresh install. |
| OQ-11 | Does the host support system cron? If not, which external ping service? | **John** | no | open | Cannot be answered without knowing the host — **asked in Q4**. This is non-blocking for the Specification Gate but it decides what `INSTALLATION.md` step 7 says, and §1.4 makes real cron the only way the delay is accurate. |

### New questions found while reading the brief

| ID | Question | Owner | Blocking | Status | Resolution and date |
|---|---|---|---|---|---|
| OQ-12 | Is the published X OAuth 1.0a signing example still in the docs? (Brief §5 marks this **[UNVERIFIED]** and says fall back to the RFC 5849 test vector.) | Claude | no | **resolved via the fallback** | X's own worked example: **UNVERIFIED 2026-09-05** — I did not locate a current signing worked-example page on `docs.x.com`. Brief §5's stated fallback was used instead, and it works: **VERIFIED 2026-09-05** the signing functions in `bin/verify-x-api.php` reproduce the RFC 5849 §3.4.1.1 published signature base string exactly, and the HMAC-SHA1 step cross-checks against an independent Python implementation. Command and output are recorded in "Signer verification" below. The RFC vector is therefore the test vector for the `class-x-provider.php` unit test; pin it in `SPEC.md`. |
| OQ-13 | Which host does v2 media upload use? Brief §8 says hard-code `api.x.com` **and `upload.x.com`**. | Claude | no | open | **Brief and source disagree — see "Conflict: OQ-13" below.** **VERIFIED 2026-09-05:** <https://docs.x.com/x-api/media/quickstart/media-upload-chunked> — every example uses host `api.x.com`; paths are `POST /2/media/upload/initialize`, `POST /2/media/upload/{id}/append`, `POST /2/media/upload/{id}/finalize`, `GET /2/media/upload?command=STATUS&media_id={id}`. The same page states the image limit for `media_category=tweet_image` is **5 MB**. `upload.x.com` / `upload.twitter.com` is the **legacy v1.1** host, which brief §1.3 forbids building on. Proposed resolution: allowlist `api.x.com` only. `bin/verify-x-api.php` confirms this against the live API. |
| OQ-14 | Does v2 offer a one-shot (non-chunked) `POST /2/media/upload`, or is INIT/APPEND/FINALIZE the only v2 path? Brief §1.3 assumes "simple upload for images". | Claude | no | open | **UNVERIFIED 2026-09-05.** The chunked quickstart (URL above) states the v2 flow uses the dedicated `initialize`/`append`/`finalize` paths and warns against passing `command=INIT`/`APPEND`/`FINALIZE` to `POST /2/media/upload`; it mentions no non-chunked method. I could not retrieve a "simple upload" quickstart page (guessed URL returned HTTP 404). This changes the shape of FR-4.4: one call or three. `bin/verify-x-api.php` tries **both** paths and reports which works. |
| OQ-15 | Is the create-post path `/2/tweets` or `/2/posts`? | Claude | no | open | **UNVERIFIED 2026-09-05.** The brief and most secondary sources say `POST /2/tweets`. One search result quoted `POST https://api.x.com/2/posts` for attaching media. The official pricing page names the operation `Post: Create` without giving a path. `bin/verify-x-api.php` does **not** test this, because creating a post costs money and publishes publicly. Resolve at Phase 6 local acceptance via FR-1.5 "Send test post", or by John checking the Console. |
| OQ-16 | WordPress floor: is 6.5 right? (§9 gives 6.5+ but only asks Phase 0 to confirm the PHP floor.) | John | no | open | **VERIFIED 2026-09-05:** `https://api.wordpress.org/stats/wordpress/1.0/` — 7.1: 51.779%, 7.0: 15.856%, 6.9: 8.232%, 6.8: 6.111%, 6.7: 2.795%, 6.6: 1.424%, 6.5: 1.26%. Recommend keeping **6.5** as the floor: it costs nothing (the plugin uses no API newer than 6.5) and the brief's CI matrix already pins WP 6.5 and latest. Raise it only if a needed API demands it. |
| OQ-17 | What happens to stored credentials if the site's salts are rotated? | Claude | no | open | **INFERRED 2026-09-05.** Brief §8 keys `sodium_crypto_secretbox` from `wp_salt('auth')`. Rotating `wp-config.php` salts is routine incident response and some security plugins do it automatically. When that happens every stored secret becomes permanently undecryptable, and under the brief as written the plugin would discover this only at send time, as an opaque failure. Recorded as a disagreement in **ADR-003 → Alternatives**, with a proposed mitigation. Not blocking; must be closed before the ADR moves from `proposed` to `accepted`. |

---

## Signer verification (evidence for OQ-12)

The two pure signing functions were extracted from `bin/verify-x-api.php` into a
temporary harness and exercised offline. **`bin/verify-x-api.php` itself was not
run and made no network calls.**

Case A reproduces the worked example in RFC 5849 §3.4.1.1. The RFC's example
includes the parameter `a3` twice; the helper takes an associative array and so
cannot express a duplicate key, and the plugin never sends one, so the duplicate
token is removed from the expected string before comparison. Nothing else is
changed. Case B signs exactly the request the probe's step 1 makes, and its
HMAC-SHA1 output is checked against an independent implementation in Python.

```
$ php sigtest.php > sigout.txt 2>&1 && python3 check.py
CASE A vs RFC 5849 3.4.1.1 (duplicate a3 removed): MATCH
  (the only removed token is the duplicate: 2 a3 params in RFC vs 1 here)
CASE B HMAC-SHA1 cross-check: MATCH -> owppY8KMmpdIQ46WX3k8VQwxvPw=
```

Percent-encoding was checked separately against the RFC 3986 unreserved set:

```
  enc(' '       ) = %20
  enc('~'       ) = ~
  enc('+'       ) = %2B
  enc('*'       ) = %2A
  enc('a-b_c.d~e') = a-b_c.d~e
  enc('r b'     ) = r%20b
  enc('=%3D'    ) = %3D%253D
```

Space encodes to `%20` and not `+`, `~` stays unreserved, and an already-encoded
value is double-encoded — the three places `urlencode()` would have been wrong.

Syntax check on the probe itself:

```
$ php -l bin/verify-x-api.php
No syntax errors detected in bin/verify-x-api.php
```

**What this does and does not establish.** It establishes that the signer is
correct as an implementation of OAuth 1.0a. It establishes nothing about OQ-2,
which asks whether X's media endpoint accepts a correctly signed OAuth 1.0a
request. That still needs the live run. Its value is that it removes the signer
from the list of suspects in advance, so a 403 from the media endpoint is
attributable to the endpoint.

---

## Conflicts between the brief and a source

The brief instructs that where a source disagrees with it, the disagreement is stated and the source quoted, and neither is silently preferred. Three occurred.

### Conflict: OQ-2 — OAuth 1.0a on `POST /2/media/upload`

- **Brief §1.2 says:** "Confirm in Phase 0 that `POST /2/media/upload` accepts OAuth 1.0a user-context. Public documentation says it does."
- **Official docs agree with the brief.** <https://docs.x.com/x-api/media/media-upload-initialize>, read 2026-09-05, lists the endpoint's authorization schemes as `OAuth2UserToken: [media.write]` and `UserToken: []`. `UserToken` is OAuth 1.0a User Context.
- **Field reports appear to contradict both.** Search results surface X developer-forum threads titled "Access to /2/media/upload using OAuth 1.0a User Context is Forbidden" and "Request for OAuth 1.0a Compatibility with /2/media/upload Endpoint". I cannot quote them: `devcommunity.x.com` returned **HTTP 403** to my fetch, so I have thread titles from a search index and nothing more. I am not treating a title as evidence of its contents.
- **Status:** unresolved. Documentation alone is not enough here, because the documented behaviour is precisely what is disputed. The brief already anticipated this and demanded a real call. `bin/verify-x-api.php` makes it.
- **Why this is the one row that must not be closed on inference:** if OAuth 1.0a is rejected by media upload, ADR-001 (OAuth 1.0a only) cannot deliver ADR-002 (explicit featured-image upload), and the featured image is one of the three things the goal names. The contingency is written into ADR-001's Alternatives section.

### Conflict: OQ-6 — is GPL-2.0-or-later *required*?

- **Brief §13 OQ-6 says:** "GPL-2.0-or-later is required if it ever goes to WordPress.org."
- **The source says otherwise.** <https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/>, read 2026-09-05, guideline 1, verbatim: *"Although any GPL-compatible license is acceptable, using the same license as WordPress—'GPLv2 or later' — is strongly recommended."*
- **The difference:** "required" overstates it. Any GPL-compatible license is acceptable to the directory; GPLv2-or-later is a strong recommendation, not a condition of listing.
- **This does not change the recommendation,** only its justification. GPL-2.0-or-later remains the right choice — see Q3.

### Conflict: OQ-13 — the `upload.x.com` host

- **Brief §8 says:** "The plugin makes outbound requests to `api.x.com` and `upload.x.com` only. Hard-code the hosts."
- **The source says the v2 endpoints are on `api.x.com`.** <https://docs.x.com/x-api/media/quickstart/media-upload-chunked>, read 2026-09-05: every example uses `api.x.com`, with paths `/2/media/upload/initialize`, `/2/media/upload/{id}/append`, `/2/media/upload/{id}/finalize`.
- **The difference:** `upload.x.com` (historically `upload.twitter.com`) is the host for the **legacy v1.1** upload endpoint, which brief §1.3 explicitly forbids building on. Allowlisting it would permit exactly the call the brief prohibits.
- **Proposed resolution:** allowlist `api.x.com` only, and treat any outbound request to another host as a bug the test suite catches. Confirmed live by `bin/verify-x-api.php`. Not applied to the brief; recorded here for John.

---

## What Phase 0 still needs

Blocking rows still open, and who closes each:

1. **OQ-2** — John runs `bin/verify-x-api.php` and pastes the output back. This is the only row that needs a real API call.
2. **OQ-4** — John decides the name and slug (Q1).
3. **OQ-5** — John decides the PHP floor (Q2).
4. **OQ-6** — John confirms the license (Q3).

Non-blocking rows OQ-13, OQ-14 and OQ-15 also close from the same script output or from Phase 6, and are recorded so they are not lost.
