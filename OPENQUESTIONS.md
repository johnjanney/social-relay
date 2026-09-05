# OPENQUESTIONS.md — Social Relay

Seeded in Phase 0 from `PROJECTBRIEF.md` Section 13 and every **[UNVERIFIED]** claim in Section 1.

A row with `Blocking = yes` and `Status = open` blocks the Specification Gate (Section 11).
Rows are never deleted. When a row resolves, the answer moves into `SPEC.md` or `DECISIONS.md` and the row is marked `resolved`.

**Evidence labels** (Section 0):
- **VERIFIED** — checked against a named source, with URL and read date, or against a real API response.
- **INFERRED** — a reasoned conclusion from verified facts. Never enough to close a blocking row on its own.
- **UNVERIFIED** — not checked. The row stays open.

All web sources in this file were read on **2026-09-05**. Owner decisions on OQ-4, OQ-5, OQ-6, OQ-11 and OQ-16 were given on **2026-09-05** and are recorded in the rows below.

---

## Table

| ID | Question | Owner | Blocking | Status | Resolution and date |
|---|---|---|---|---|---|
| OQ-1a | Confirm the current X rate card for `POST /2/tweets`, with and without a URL. | Claude | yes | resolved | **VERIFIED 2026-09-05.** <https://docs.x.com/x-api/getting-started/pricing> — write-operations table: `Post: Create` = **$0.015 per request**; `Post: Create (with URL)` = **$0.200 per request**. Agrees with brief §1.1. |
| OQ-1b | Confirm the media-upload price under pay-per-use. | Claude → John | no | open | **Still UNVERIFIED 2026-09-05, and now measurable.** <https://docs.x.com/x-api/getting-started/pricing> lists no row for any `/2/media/upload` endpoint; absence from the table is not evidence the call is free. The 2026-09-05 probe made **six billable-or-not requests** — one user read plus five media calls — in a two-second window against a freshly funded account, which is the cleanest measurement opportunity this project will ever get. **John: open the Developer Console usage or credit balance and report the delta.** If the balance dropped by roughly $0.01, media upload is unbilled and the cost model is simply $0.20 per post. If it dropped by more, the difference divided across five calls is the per-call media price, and `INSTALLATION.md`'s cost formula must include it. Brief §1.1 defers this to Phase 10; capturing it now costs one look at a screen. |
| OQ-2 | Confirm `POST /2/media/upload` accepts OAuth 1.0a user context. Make one real call. | John (ran), Claude (wrote script) | yes | **resolved** | **VERIFIED 2026-09-05 by live API call. The answer is YES.** `bin/verify-x-api.php` run at 22:49:12–22:49:14 UTC against `api.x.com`, API version `2.168`, all five steps **HTTP 200**: `GET /2/users/me` 200; `POST /2/media/upload/initialize` 200 returning `{"data":{"expires_after_secs":86400,"id":"2096370090102919168","media_key":"3_2096370090102919168"}}`; `POST /2/media/upload/{id}/append` 200; `POST /2/media/upload/{id}/finalize` 200 returning the decoded image dimensions; and the one-shot `POST /2/media/upload` 200. Every response carried `x-access-level: read-write`. Because step 1 succeeded first, the signer and credentials were proved correct before the media calls, so these 200s are attributable to the endpoint accepting OAuth 1.0a and nothing else. The official documentation was accurate; the forum thread titles were misleading, stale, or described OAuth 2.0. **ADR-001 and ADR-002 are unblocked and both are now accepted.** |
| OQ-3 | Confirm the current post length limit and URL character weight. | Claude | yes | resolved | **VERIFIED 2026-09-05.** <https://docs.x.com/fundamentals/counting-characters> — "Posts on X can contain up to **280 characters**"; "All URLs are wrapped with `t.co` shortener and count as **23 characters**, regardless of the original length." Weighting: most characters count 1; emoji, CJK and some other Unicode count 2. Agrees with brief §4 FR-4.5. Note for SPEC: the truncation routine must use weighted counting, not `strlen()` or `mb_strlen()`. Premium/verified-org longer limits are out of scope (§3 targets one standard account); the plugin assumes 280 and does not detect account tier. |
| OQ-4 | Plugin name and slug. Check WordPress.org conflict and the "X"/"Twitter" trademark constraint. | John | yes | **resolved** | **RESOLVED by owner 2026-09-05.** Name **Social Relay**, slug **`social-relay`**, prefix **`srl_`** — the brief's working name is confirmed, not a placeholder any more. Supporting evidence, **VERIFIED 2026-09-05**: `https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=social-relay` returns `{"error":"Plugin not found."}` (HTTP 404), and `https://wordpress.org/plugins/social-relay/` 301-redirects to `/plugins/search/social-relay/`, the directory's behaviour for an unused slug. No directory plugin is named "Social Relay"; an unrelated plugin holds the slug `relay`. Trademark clear, **VERIFIED 2026-09-05**: <https://docs.x.com/developer-terms/agreement> (page states last updated 2026-04-27) §III.F: "You shall not include any of the X Marks in your registered corporate name(s), your logos, or your service or product names." "Social Relay" contains no X Mark. Carry into `SPEC.md` and the Phase 4 plugin header. |
| OQ-5 | PHP floor: 8.1 or 8.2? | John | yes | **resolved** | **RESOLVED by owner 2026-09-05.** Floor is **PHP 8.2**. Evidence, **VERIFIED 2026-09-05**: <https://www.php.net/supported-versions.php> — PHP 8.1 is absent from the supported-versions table; its security support ended 2025-12-31. PHP 8.2 security support ends 2026-12-31, 8.3 ends 2027-12-31, 8.4 ends 2028-12-31. Install share, **VERIFIED 2026-09-05** from `https://api.wordpress.org/stats/php/1.0/`: 8.2 = 24.816%, 8.3 = 25.094%, 8.4 = 8.455%, 8.5 = 2.913%, 8.6 = 0.012%, 8.1 = 11.489%; computed **PHP ≥ 8.2 = 61.29%**. Consequences for later phases: the plugin header gets `Requires PHP: 8.2`, and §10's CI matrix must change from "PHP 8.1 and latest" to **PHP 8.2 and latest**. Note that PHP 8.2 itself leaves security support on 2026-12-31, roughly four months from now, so `VERSIONING.md` should treat a floor raise to 8.3 as a planned, non-urgent breaking change rather than a surprise. |
| OQ-6 | License. Confirm GPL-2.0-or-later. | John | yes | **resolved** | **RESOLVED by owner 2026-09-05.** License is **GPL-2.0-or-later**. Note the brief overstates the requirement — see "Conflict: OQ-6" below. **VERIFIED 2026-09-05**: <https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/> guideline 1: "Although any GPL-compatible license is acceptable, using the same license as WordPress—'GPLv2 or later' — is strongly recommended." So this is the recommended choice, not a condition of listing, and it keeps the WordPress.org option open at no cost. Phase 4 adds a `LICENSE` file with the GPL-2.0 text and a `License: GPLv2 or later` / `License URI:` pair in the plugin header. |
| OQ-7 | Should "Send test post" include a URL, so it tests the exact billing path at $0.20 per test? | John | no | **resolved** | **RESOLVED by owner 2026-09-05.** **No.** The cost ratio is confirmed at 13.3× ($0.200 vs $0.015, OQ-1a). A URL-less test still exercises credentials, signing, and the create-post endpoint; the only untested difference is X's own URL detection, which the plugin does not control. The settings page states the $0.015-vs-$0.200 difference next to the button so the owner knows the test is not billing-representative. |
| OQ-8 | Should WordPress `future` posts use the delay from their scheduled time, or from publish time? | John | no | **resolved** | **RESOLVED by owner 2026-09-05.** **Publish time + delay**, the same as every other path. `transition_post_status` fires `future → publish` at the moment WP-Cron actually publishes the post, so publish time is already the only clock the plugin observes; any other reading would need a second scheduling path for no benefit. |
| OQ-9 | Downscale large images, or reject and post without an image? | John | no | **resolved** | **RESOLVED by owner 2026-09-05.** **Downscale with `wp_get_image_editor()`.** Byte limit for `media_category=tweet_image` is **5 MB**, **VERIFIED 2026-09-05** from <https://docs.x.com/x-api/media/quickstart/media-upload-chunked>. Pixel limits are not yet pinned; `SPEC.md` must pin them. FR-4.4's fallback still applies: if downscaling or upload fails, post the text and URL anyway and record `image_omitted = true`. |
| OQ-10 | Email on failure: default on or off? | John | no | **resolved** | **RESOLVED by owner 2026-09-05.** **Off by default.** FR-5.2's admin notice already surfaces failures inside wp-admin, and default-on email risks noise and deliverability problems on a fresh install before the owner has confirmed anything works. |
| OQ-11 | Does the host support system cron? If not, which external ping service? | John | no | **resolved (with one item for OQ-18)** | **RESOLVED by owner 2026-09-05.** Host is **Hostinger** shared hosting, which has native cron jobs in hPanel under Advanced → Cron Jobs; no external ping service is needed. **VERIFIED 2026-09-05** <https://www.hostinger.com/support/1583765-how-many-cron-jobs-can-you-set-up-in-hostinger/>: Single plan allows a "Maximum of two cron jobs"; "Premium hosting and above" is "Unlimited". The plugin needs one, so every plan is sufficient. **VERIFIED 2026-09-05** <https://www.hostinger.com/tutorials/how-to-setup-and-manage-a-wordpress-cron-job/>: Hostinger's own WordPress instructions give the `wp-config.php` line as `define( 'DISABLE_WP_CRON', true );` placed above the "That's all, stop editing" line, and the replacement command as `wget -O /dev/null -o /dev/null https://yoursite.com/wp-cron.php?doing_wp_cron`. **But Hostinger recommends an interval that this plugin cannot use — see "Conflict: OQ-11" below.** Also **VERIFIED 2026-09-05** <https://www.hostinger.com/support/1583514-troubleshooting-cron-jobs-at-hostinger/>: "it's possible for a cron job to fail if the server exceeds resource limits, such as CPU, memory, or disk space… causing cron jobs to be skipped or not executed as scheduled." That is exactly the condition FR-1.6's cron health panel must make visible. Everything here feeds `INSTALLATION.md` step 7 in Phase 6. The remaining detail — whether every-minute scheduling is offered on John's specific plan — is **OQ-18**. |

### New questions found while reading the brief

| ID | Question | Owner | Blocking | Status | Resolution and date |
|---|---|---|---|---|---|
| OQ-12 | Is the published X OAuth 1.0a signing example still in the docs? (Brief §5 marks this **[UNVERIFIED]** and says fall back to the RFC 5849 test vector.) | Claude | no | **resolved via the fallback** | X's own worked example: **UNVERIFIED 2026-09-05** — I did not locate a current signing worked-example page on `docs.x.com`. Brief §5's stated fallback was used instead, and it works: **VERIFIED 2026-09-05** the signing functions in `bin/verify-x-api.php` reproduce the RFC 5849 §3.4.1.1 published signature base string exactly, and the HMAC-SHA1 step cross-checks against an independent Python implementation. Command and output are recorded in "Signer verification" below. The RFC vector is therefore the test vector for the `class-x-provider.php` unit test; pin it in `SPEC.md`. |
| OQ-13 | Which host does v2 media upload use? Brief §8 says hard-code `api.x.com` **and `upload.x.com`**. | Claude | no | **resolved** | **VERIFIED 2026-09-05 by live API call.** The complete upload flow succeeded against **`api.x.com` alone**; `upload.x.com` was never contacted. **Decision: the §8 host allowlist is `api.x.com` only.** Dropping `upload.x.com` is not merely tidying — it is the host of the legacy v1.1 endpoint that §1.3 forbids building on, so allowlisting it would permit exactly the call the brief prohibits. `SPEC.md` states the single-host rule, and the test suite must fail the build on any outbound request to another host. |
| OQ-14 | Does v2 offer a one-shot (non-chunked) `POST /2/media/upload`, or is INIT/APPEND/FINALIZE the only v2 path? | Claude | no | **resolved** | **VERIFIED 2026-09-05 by live API call. Both paths exist and both work.** One-shot `POST /2/media/upload` as a single `multipart/form-data` request with fields `media_category` and `media` returned **200** with a usable media id. **Decision: FR-4.4 uses the one-shot path** — one API call instead of three, one failure point instead of three, and §0 makes the smaller design the required one. The chunked path stays documented as the fallback if a future image exceeds what one request can carry. **Two findings that must reach `SPEC.md`.** First, the two paths return *different JSON shapes for the same data*: `finalize` returns `"image":{"height":16,"image_type":"image/png","width":16}` while the one-shot returns `"image":{"h":16,"image_type":"image/png","w":16}` — `height`/`width` versus `h`/`w`. Any parser must not assume one shape. Second, the rate limits differ sharply: the one-shot path reported `x-rate-limit-limit: 500` against `1875` for each chunked step, both on 15-minute windows. 500 per 15 minutes is far beyond anything this plugin will do, so it does not constrain the choice, but it is recorded so a future bulk feature does not rediscover it the hard way. |
| OQ-15 | Is the create-post path `/2/tweets` or `/2/posts`? | Claude | no | open | **UNVERIFIED 2026-09-05.** The brief and most secondary sources say `POST /2/tweets`. One search result quoted `POST https://api.x.com/2/posts` for attaching media. The official pricing page names the operation `Post: Create` without giving a path. `bin/verify-x-api.php` does **not** test this, because creating a post costs money and publishes publicly. Resolve at Phase 6 local acceptance via FR-1.5 "Send test post", or by John checking the Console. |
| OQ-16 | WordPress floor: is 6.5 right? (§9 gives 6.5+ but only asks Phase 0 to confirm the PHP floor.) | John | no | **resolved** | **RESOLVED by owner 2026-09-05.** Floor stays **WordPress 6.5**. **VERIFIED 2026-09-05** from `https://api.wordpress.org/stats/wordpress/1.0/`: 7.1 = 51.779%, 7.0 = 15.856%, 6.9 = 8.232%, 6.8 = 6.111%, 6.7 = 2.795%, 6.6 = 1.424%, 6.5 = 1.26%. The plugin uses no API newer than 6.5, so the floor costs nothing, and §10's CI matrix already pins WP 6.5 and latest. Raise it only if a needed API demands it. |
| OQ-17 | What happens to stored credentials if the site's salts are rotated? | Claude | no | **resolved** | **RESOLVED by owner 2026-09-05.** The mitigation proposed in ADR-003 was **accepted** and has been moved from that ADR's Alternatives section into its Decision section. Stored ciphertext now carries a format-version byte and a non-secret fingerprint of the derived key; on mismatch the plugin enters a distinct `credentials_unreadable` state, shows a notice that names salt rotation as the cause and re-entry as the fix, and refuses to schedule posts that are certain to fail. This converts a silent, misattributed HTTP 401 arriving days later inside a cron event into an accurate instruction on the settings page. `SPEC.md` must specify the ciphertext envelope format. |
| OQ-18 | On John's Hostinger plan, does the hPanel cron dropdown offer **every minute**, and does that interval actually hold in practice? | John | no | open | **INFERRED, needs a two-minute check in hPanel.** Hostinger's own documentation is internally inconsistent on this: <https://docs.hostinger.com/websites/cron-jobs> lists "Every minute" among its common schedules while also presenting `*/5 * * * *` as its smallest worked example, and no Hostinger page I read states a minimum interval or a throttling policy. I am not closing this on a contradictory source. **What to check:** open hPanel → Advanced → Cron Jobs and confirm the dropdown offers every-minute, or that a custom `* * * * *` expression is accepted and is not silently rewritten. **Why it matters:** FR-1.6 warns when the last WP-Cron run is more than 5 minutes old. If Hostinger caps the interval at 5 minutes, that threshold sits exactly on the boundary and will produce false warnings, so it must be raised or made configurable. If every-minute works, the 5-minute threshold is correct as written. |
| OQ-19 | Does an App still have to sit inside a **Project**? Brief §1.2 marks this **[VERIFIED]**; the current documentation no longer mentions Projects anywhere. | Claude | no | open | **The brief may be stale here — see "Conflict: OQ-19" below.** **VERIFIED 2026-09-05** that four separate current documentation pages describe an App-centric console with no Project concept: <https://docs.x.com/x-api/getting-started/getting-access> ("Step 1: Create a developer account", "Step 2: Create an app", "Step 3: Save your credentials"), <https://docs.x.com/resources/fundamentals/developer-apps> ("Apps are containers for your API credentials. Each app has its own keys, tokens, and settings."), <https://docs.x.com/resources/fundamentals/developer-portal>, and <https://docs.x.com/fundamentals/authentication/oauth-1-0a/api-key-and-secret>. The console is now at **console.x.com**. **INFERRED**, not verified: the Project requirement was dropped or absorbed in the February 2026 pay-per-use rework. Silence across four pages is strong but is not the same as a statement that Projects are gone; the new console may create one implicitly. Closes when John reports what the console actually shows. **Practical effect: none on the plugin.** It changes only the wording of `INSTALLATION.md` step 2 and the troubleshooting text in `bin/verify-x-api.php`, both of which currently tell the owner to check Project membership when a call fails. |

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
- **RESOLVED 2026-09-05 in the documentation's favour.** The live run returned HTTP 200 from `initialize`, `append`, `finalize`, and the one-shot upload, all signed with OAuth 1.0a, all after `GET /2/users/me` had already proved the signer. The docs were right. The forum titles were not evidence, and treating them as evidence would have cost this project an OAuth 2.0 PKCE implementation it does not need.
- **The method mattered more than the answer.** Had the run been ordered differently — media first, no signer proof — a 200 would have been just as ambiguous as a 403, because nothing would have separated "the endpoint accepts OAuth 1.0a" from "this particular request happened to work". Step 1 is what makes the result load-bearing. Keep that ordering in any future probe.
- **Why this row could not be closed on inference:** if OAuth 1.0a had been rejected, ADR-001 (OAuth 1.0a only) could not have delivered ADR-002 (explicit featured-image upload), and the featured image is one of the three things the goal names. That contingency, recorded in ADR-001's Alternatives, is now moot and stays on file as the record of a risk that was retired by measurement rather than assumed away.

### Conflict: OQ-6 — is GPL-2.0-or-later *required*?

- **Brief §13 OQ-6 says:** "GPL-2.0-or-later is required if it ever goes to WordPress.org."
- **The source says otherwise.** <https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/>, read 2026-09-05, guideline 1, verbatim: *"Although any GPL-compatible license is acceptable, using the same license as WordPress—'GPLv2 or later' — is strongly recommended."*
- **The difference:** "required" overstates it. Any GPL-compatible license is acceptable to the directory; GPLv2-or-later is a strong recommendation, not a condition of listing.
- **This does not change the recommendation,** only its justification. GPL-2.0-or-later remains the right choice — see Q3.

### Conflict: OQ-13 — the `upload.x.com` host

- **Brief §8 says:** "The plugin makes outbound requests to `api.x.com` and `upload.x.com` only. Hard-code the hosts."
- **The source says the v2 endpoints are on `api.x.com`.** <https://docs.x.com/x-api/media/quickstart/media-upload-chunked>, read 2026-09-05: every example uses `api.x.com`, with paths `/2/media/upload/initialize`, `/2/media/upload/{id}/append`, `/2/media/upload/{id}/finalize`.
- **The difference:** `upload.x.com` (historically `upload.twitter.com`) is the host for the **legacy v1.1** upload endpoint, which brief §1.3 explicitly forbids building on. Allowlisting it would permit exactly the call the brief prohibits.
- **RESOLVED 2026-09-05:** the full upload flow succeeded against `api.x.com` alone. The allowlist is **`api.x.com` only**, and any outbound request to another host is a bug the test suite must catch. This is a correction to brief §8, made on measured evidence rather than preference.

### Conflict: OQ-11 — Hostinger recommends a cron interval this plugin cannot use

- **Brief §1.4 requires:** "`INSTALLATION.md` MUST instruct the owner to disable the built-in trigger (`DISABLE_WP_CRON`) and call `wp-cron.php` from a real system cron **every minute**. This is the only way the delay is accurate."
- **Hostinger's own WordPress guide recommends less.** <https://www.hostinger.com/tutorials/how-to-setup-and-manage-a-wordpress-cron-job/>, read 2026-09-05, suggests starting the replacement cron at **"twice an hour"**.
- **The difference matters, and the brief is right.** Hostinger's advice is written for generic WordPress housekeeping — update checks, scheduled publishing, backup jobs — where half-hour granularity is harmless. This plugin's delay is a promise about *when a post appears on a timeline*. At a 30-minute interval, a delay set to 60 minutes fires somewhere between 60 and 90 minutes, and a delay of 0 (FR-3.2 still routes through the scheduler) is not "immediately" but "within half an hour". That is the difference between a feature and an apology.
- **Resolution:** follow the brief. `INSTALLATION.md` specifies `* * * * *` for Hostinger and explains, in one sentence, why the host's own suggestion is too coarse for this plugin — so the owner does not later "correct" it back to Hostinger's default. Whether every-minute is actually offered on the plan is **OQ-18**.
- **Not a contradiction of the host's capability.** Nothing in Hostinger's documentation states a minimum interval; the 30-minute figure is advice, not a limit. Confirmed absent from <https://www.hostinger.com/support/1583514-troubleshooting-cron-jobs-at-hostinger/> and <https://www.hostinger.com/support/1583765-how-many-cron-jobs-can-you-set-up-in-hostinger/>, both read 2026-09-05.

**Second-order note for Phase 6.** Hostinger's recommended command is `wget` over HTTPS against the site's own public URL. That is fine and is the form `INSTALLATION.md` should use, but it makes the cron a real HTTP request to the site, so it interacts with Hostinger's LiteSpeed caching layer. `wp-cron.php` sends its own no-cache headers, so it should pass through; verify it actually does during Phase 6 acceptance rather than assuming, because a cached `wp-cron.php` response would produce a cron that appears to run and silently does nothing — the worst failure shape available here, and one the health panel would not catch, since the panel records the last time WordPress *ran* cron, not the last time something requested it.

### Conflict: OQ-19 — is the Project requirement still real?

- **Brief §1.2 says, marked [VERIFIED]:** "X API v2 requires the App to sit inside a Project in the Developer Console. An App outside a Project fails on v2 calls."
- **That was true when the brief was written and may not be true now.** X replaced tiered plans with pay-per-use on 2026-02-06 and moved the console to `console.x.com`. Across four current documentation pages read 2026-09-05 — getting-access, developer-apps, developer-portal, and the OAuth 1.0a API-key page — the word Project does not appear. The getting-access page reduces the whole flow to three steps: *"Step 1: Create a developer account," "Step 2: Create an app," "Step 3: Save your credentials."* The apps page defines the unit of credentials as the App alone: *"Apps are containers for your API credentials. Each app has its own keys, tokens, and settings."*
- **I am not marking the brief wrong.** Absence of a mention is weaker evidence than a statement, and secondary sources still describe the older "Projects & Apps" navigation, which may reflect stale content rather than a live console. Both readings are recorded.
- **Resolution:** John is creating an App now and will see which console he gets. That settles it in one look, at no cost.
- **Why it is not blocking:** either way the plugin's code is identical. Project membership is a Console-side configuration fact, not something the plugin observes. It affects one line of setup documentation and one troubleshooting hint.

---

## Phase 0 status — the gate is met

**No `blocking` row is open. The Specification Gate condition in §11 is satisfied.**

| Blocking row | Outcome |
|---|---|
| OQ-1a | resolved — $0.015 per post, $0.200 with a URL |
| OQ-2 | **resolved by live API call — OAuth 1.0a works on v2 media upload** |
| OQ-3 | resolved — 280 characters, URLs weigh 23 |
| OQ-4 | resolved — Social Relay / `social-relay` |
| OQ-5 | resolved — PHP 8.2 |
| OQ-6 | resolved — GPL-2.0-or-later |

`DECISIONS.md` holds ADR-001 through ADR-004, exceeding the §11 requirement of ADR-001..003. ADR-001 and ADR-002 moved from `proposed` to `accepted` on the OQ-2 result; ADR-003 and ADR-004 were accepted on 2026-09-05.

### The one attempt that did not count

*Attempt 1, 2026-09-05 — void.* The probe was first run with all four credentials set to the literal string `...`, the placeholder from the pasted instructions; the masked output read `(len=3)` for each. Step 1 returned HTTP 401. That 401 was not a result. Two things were fixed rather than noted: the script now refuses placeholder, whitespace-bearing and implausibly short values **before** any network call and offers to read them from the terminal instead, and the step-1 troubleshooting text no longer asserts the Project requirement as fact (OQ-19). *Attempt 2* is the run recorded above.

### Still open, none blocking

- **OQ-1b** — media-upload pricing. One look at the Developer Console balance closes it, and the 2026-09-05 run is the cleanest measurement this project will get. Otherwise deferred to Phase 10 per §1.1.
- **OQ-15** — `/2/tweets` versus `/2/posts`. Not tested, deliberately: creating a post costs money and publishes publicly. Closes at Phase 6 via FR-1.5.
- **OQ-18** — every-minute cron on Hostinger. A two-minute look at hPanel. Decides whether FR-1.6's 5-minute staleness threshold is usable as written.
- **OQ-19** — whether Apps still sit inside Projects. Closes the moment John says what the console showed. Affects setup wording only, no plugin code.

None of the four blocks writing `SPEC.md`. OQ-18 and OQ-19 need one sentence each from John. OQ-1b and OQ-15 are measurements that belong to later phases by the brief's own design.

### Facts measured on 2026-09-05 that `SPEC.md` must carry

Recorded here because they came from a real response and will not be re-derivable later without spending money again.

- API version `2.168`. Every media response carried `x-access-level: read-write`.
- Uploaded media expires after **86400 seconds**. `expires_after_secs: 86400` appeared on both `initialize` and the one-shot response. This confirms ADR-002's rejection of upload-at-schedule-time: a delay may legally reach 72 hours (FR-1.2), which is three times the media lifetime, so the image **must** be uploaded at send time.
- Rate limits, 15-minute windows: `GET /2/users/me` 75; each chunked step 1875; one-shot `POST /2/media/upload` **500**.
- The two upload paths disagree on JSON shape for identical data: `finalize` gives `image.height` / `image.width`, the one-shot gives `image.h` / `image.w`.
- X reported `size: 100` for an 89-byte source PNG, identically on both paths. Do not validate the uploaded size against the local file size; X's accounting is its own.
