# DECISIONS.md — Social Relay

Architecture Decision Records. One heading per decision, numbered from ADR-001.

Each record uses the template in `PROJECTBRIEF.md` §12: Status, Date, Context, Decision, Consequences, Alternatives rejected.

Status values: `proposed` → `accepted` → `superseded`.

**Owner decisions of 2026-09-05.** John confirmed the name **Social Relay** / `social-relay`, a **PHP 8.2** floor, **GPL-2.0-or-later**, and **Hostinger** as the host, and accepted the recommendations attached to each ADR. ADR-003 and ADR-004 are therefore **accepted**. ADR-003's Decision section has been amended to fold in the salt-rotation mitigation that was previously only a recorded disagreement; the original recommendation and the reasoning that changed it are both preserved below, so nothing was altered silently.

**All five ADRs are now `accepted`.** ADR-001 and ADR-002 were unblocked on 2026-09-05 when the OQ-2 probe returned HTTP 200 from every media-upload endpoint under OAuth 1.0a. The contingency planning recorded in ADR-001's Alternatives is retained, not deleted: it is the record of a risk retired by measurement rather than assumed away, and it is the plan that would have been executed had the result gone the other way.

**ADR-005 recorded a decision that contradicted the brief; the brief has since been amended.** `PROJECTBRIEF.md` §3 listed hashtag generation as a v1 non-goal. The owner asked for the feature on 2026-09-05, it was built, and the owner then asked for the brief itself to be updated — so §3 now carries "Amendment 1", which removes hashtags from the non-goals, quotes the original wording, and adds FR-4.13. ADR-005 keeps the disagreement as it stood at decision time rather than being rewritten to match; its closing **Update** note records the amendment.

From Phase 8 onward, every review finding that is declined rather than fixed becomes an ADR here.

---

## ADR-001 — Use OAuth 1.0a with owner-generated user tokens; do not build OAuth 2.0 PKCE

**Status:** **accepted** 2026-09-05, on the OQ-2 probe result

**Date:** 2026-09-05

**Context**

The site owner posts to their own X account. There is no third-party user to authorize, so no consent screen, no callback URL, and no token store keyed by user.

- **VERIFIED** (brief §1.2) X API v2 requires the App to sit inside a Project in the Developer Console. An App outside a Project fails on v2 calls.
- **VERIFIED** (brief §1.2) Two user-context methods exist. OAuth 1.0a uses four static strings generated once in the Console. OAuth 2.0 Authorization Code with PKCE needs a public callback endpoint, an `offline.access` scope, and refresh-token rotation.
- **VERIFIED 2026-09-05** <https://docs.x.com/x-api/media/media-upload-initialize> lists media upload's authorization schemes as `OAuth2UserToken: [media.write]` and `UserToken: []`, where `UserToken` is OAuth 1.0a User Context. On the documented behaviour, OAuth 1.0a is sufficient for the whole pipeline.
- ~~**UNVERIFIED** Developer-forum thread titles suggest OAuth 1.0a is rejected with 403 by `/2/media/upload` in practice.~~ **Settled 2026-09-05 and struck through rather than deleted, so the record shows what was believed and how it was tested.** A live run signed with OAuth 1.0a returned HTTP 200 from `initialize`, `append`, `finalize`, and the one-shot upload, each response carrying `x-access-level: read-write`, after `GET /2/users/me` had already returned 200 and thereby proved the signer. The documentation was accurate. The thread titles were not evidence.
- Refresh-token expiry is a silent failure mode: the plugin keeps working until it suddenly does not, at a moment nobody is watching. A delayed-post plugin is exactly where that failure is least visible.

**Decision**

Implement OAuth 1.0a HMAC-SHA1 user-context signing only. The owner pastes API Key, API Key Secret, Access Token, and Access Token Secret into the settings page. The plugin signs every request itself in `class-x-provider.php`, with no OAuth library and no Composer runtime dependency. OAuth 2.0 PKCE is a documented non-goal for v1 (brief §3).

**Consequences**

*Easier:* No callback endpoint, so no public route to secure and no site-URL coupling. No refresh loop, so no expiry failure mode and no background token cron. No consent UI. Setup is four paste fields. The signer is roughly 80 lines and is fully unit-testable offline against a fixed vector. Credentials are static, so a `wp_remote_post()` mock in tests needs no token lifecycle.

*Harder:* HMAC-SHA1 signing is easy to get subtly wrong — percent-encoding, parameter sorting, and the rule about which body parameters enter the signature base string are all places where a bug produces a generic 401 with no diagnostic. This must be covered by a unit test against a known-good vector, not only by live calls. Rotating credentials means the owner regenerates them in the Console and re-pastes; there is no self-service refresh. If X ever deprecates OAuth 1.0a for v2, this becomes a forced rewrite of the auth layer — bounded, because it is one class behind the provider interface.

**Alternatives rejected**

- *OAuth 2.0 Authorization Code with PKCE.* Rejected for v1: it adds a callback endpoint, refresh-token storage and rotation, and the expired-refresh-token silent failure this decision exists to avoid. Cost is not repaid when there is exactly one user and that user owns the site.
- *App-only Bearer token.* Rejected: app-only context cannot post on behalf of a user.
- *A third-party OAuth library via Composer.* Rejected under §0's simplicity constraint and §5's "no Composer runtime dependencies". Signing is ~80 lines; a dependency is a larger surface than the code it replaces.

**Disagreement recorded, and resolved 2026-09-05 — the contingency the brief did not state**

*Outcome first: the probe returned 200 on every media call, so none of the three options below was needed and this ADR stands as written. The reasoning is kept because it is the reason the question was asked at all.*

I did not disagree with choosing OAuth 1.0a. I disagreed with treating it as settled before OQ-2 returned.

The brief recommends OAuth 1.0a in §1.2 and separately requires the featured image in §1.3 and FR-4.4, but the two are only jointly satisfiable if `/2/media/upload` actually accepts OAuth 1.0a. Documentation says it does. Thread titles suggest it does not. If the live call comes back 403 while `GET /2/users/me` succeeds with the same credentials, then this ADR and ADR-002 are in direct conflict and one of them has to give. The available options, in the order I would take them:

1. **OAuth 1.0a for `POST /2/tweets`, OAuth 2.0 PKCE for media upload only.** Keeps posting simple but reintroduces the entire refresh-token problem for the image path, and makes the image path the fragile one. Worst of both.
2. **OAuth 2.0 PKCE throughout.** Overturns this ADR. Honest and coherent, and the largest amount of new work.
3. **Drop the uploaded image and fall back to X's link-preview card** (ADR-002's rejected alternative). Cheapest, and it gives up something the goal names.

I am not choosing between these now, because the choice depends on a fact I do not have. I am recording that the choice exists so it is not discovered mid-implementation. **This ADR stays `proposed` until OQ-2 closes.**

**2026-09-05 — OQ-2 closed VERIFIED. Option 1 is unnecessary, option 2 is unnecessary, option 3 is unnecessary. ADR-001 is accepted unchanged.** The cost of asking was one API call and about a cent. The cost of assuming, had the assumption been wrong, would have been discovering it in Phase 5 with the settings page, the scheduler, and the provider already built around four static strings.

---

## ADR-002 — Upload the featured image explicitly; do not rely on X's link-preview card

**Status:** **accepted** 2026-09-05, on the OQ-2 probe result

**Date:** 2026-09-05 (amended 2026-09-05 to pin the upload path)

**Context**

- **VERIFIED** (brief §1.3) X does not fetch an image from a URL at post time. Attaching an image means uploading the bytes, receiving a `media_id`, and passing it in `media.media_ids` on the create-post call.
- **VERIFIED** (brief §1.3) The v1.1 media upload endpoint is legacy. Build on v2.
- **VERIFIED 2026-09-05** <https://docs.x.com/x-api/media/quickstart/media-upload-chunked> — the v2 upload endpoints are on `api.x.com`, and the stated size limit for `media_category=tweet_image` is 5 MB.
- **VERIFIED 2026-09-05 by live API call** (OQ-2, OQ-13, OQ-14). The whole flow works under OAuth 1.0a against `api.x.com` alone. Two upload paths exist and both returned 200: the three-step `initialize` → `append` → `finalize`, and a **one-shot `POST /2/media/upload`** carrying `media_category` and `media` as one `multipart/form-data` request.
- **VERIFIED 2026-09-05 by live API call** — uploaded media reports `expires_after_secs: 86400`. Media is usable for 24 hours after upload.
- The alternative is to post the URL alone and let X's crawler build a card from the page's `og:image`. That is less code, but it depends on the site emitting correct Open Graph tags, on X's crawler reaching the page, and on X's card rendering, which has changed repeatedly since 2023. None of those three is under the plugin's control.
- The owner asked for the featured image to be posted.

**Decision**

Upload the featured image explicitly. Read the file from the local filesystem, never over HTTP — the site may be behind basic auth, a staging password, or a CDN, and an HTTP self-fetch turns a local file read into a network dependency that fails in exactly those environments. Downscale if the file exceeds the byte or pixel limit. Upload via the v2 media endpoint, then attach the returned `media_id` to the post.

**Amended 2026-09-05, once the probe showed both paths working.** Use the **one-shot `POST /2/media/upload`** — a single `multipart/form-data` request with `media_category=tweet_image` and the file in `media` — not the three-step chunked flow. One call instead of three, one failure point instead of three, and §0 requires the design with fewer moving parts when both meet the requirement. The chunked flow is documented as the fallback should an image ever exceed what one request can carry; it is not built in v1.

Two measured details bind the implementation:

1. **Upload at send time, never at schedule time.** Media expires 86400 seconds after upload, and FR-1.2 allows a delay of up to 72 hours — three times the media lifetime. Uploading early would silently break exactly the long-delay case the feature exists to serve. This also keeps ADR-002 consistent with FR-4.3, which already reads title and permalink at send time.
2. **Do not assume one response shape.** The two paths return different keys for the same data: `finalize` gives `image.height` and `image.width`, the one-shot gives `image.h` and `image.w`. The plugin reads only `data.id`, which is stable across both, and treats the dimension keys as absent unless proven present. Relatedly, X reported `size: 100` for an 89-byte source PNG on both paths, so the uploaded size must never be validated against the local file size.

If there is no featured image, or the upload fails after retries, publish the text-and-URL post anyway and record `image_omitted = true` with the reason (brief §1.3 requirement, FR-4.4). The image is not allowed to block the post.

**Consequences**

*Easier:* The image is deterministic. What the plugin uploads is what appears, independent of crawler behaviour, Open Graph plugins, and card-format changes. It works for a site that is not publicly reachable by X's crawler at post time. Failures are visible in the log rather than silently absent from the timeline.

*Harder:* **One** extra API call per post, of unknown price (OQ-1b) — reduced from the one-to-three the chunked flow would have cost. More failure modes: missing file on disk, unsupported MIME type, oversize file, image editor unavailable, upload timeout, media processing failure. Each needs a fixture in the test suite and a defined fallback. Adds a dependency on `wp_get_image_editor()` and therefore on GD or Imagick being present. The exact call shape is not yet settled — see OQ-14 (one-shot vs chunked).

**Alternatives rejected**

- *Rely on X's link-preview card via `og:image`.* Rejected: three dependencies outside the plugin's control, and it does not satisfy the owner's stated requirement.
- *Fetch the image over HTTP from the permalink.* Rejected: breaks on non-public and password-protected sites, and adds a network round trip for a file already on disk.
- *Build on the v1.1 upload endpoint.* Rejected by brief §1.3; it is legacy, and it is the host `upload.x.com` that OQ-13 proposes to drop from the allowlist.
- *Upload at schedule time and reuse the `media_id` at send time.* Rejected, and as of 2026-09-05 **measurably wrong**, not merely unattractive: media expires after 86400 seconds while FR-1.2 permits a 72-hour delay. This was rejected on the general principle that FR-4.3 reads content at send time; the probe then supplied the specific number that makes it a correctness bug rather than a style preference.
- *Use the three-step chunked upload.* Rejected on 2026-09-05 after both paths were confirmed working. Three calls, three failure points and more retry logic, for an image the brief already caps at 5 MB. Retained as the documented fallback.

---

## ADR-003 — Encrypt the four X secrets at rest with `sodium_crypto_secretbox`, keyed from `wp_salt('auth')`

**Status:** **accepted** 2026-09-05, with the salt-rotation mitigation folded into the Decision

**Date:** 2026-09-05 (amended 2026-09-05 on owner acceptance)

**Context**

- The plugin stores four long-lived credentials that can post to the owner's X account.
- WordPress options are plain text in the database. A database dump, a leaked backup, or an unrelated SQL-injection bug in another plugin exposes them.
- `sodium_crypto_secretbox` is in PHP core from 7.2, so it is available at any floor under discussion in OQ-5. It is authenticated encryption; it detects tampering rather than silently decrypting garbage.
- **INFERRED** (brief §8) This is defence in depth only. The key derives from `wp-config.php`, so anyone with both database *and* filesystem access can decrypt. The brief already requires that limit be stated plainly in `README.md`.

**Decision**

Encrypt each of the four secrets with `sodium_crypto_secretbox` before storing it in the `srl_settings` option. Derive the 32-byte key from `wp_salt('auth')` — `wp_salt()` returns a string, not a 32-byte key, so it must be run through a KDF or hash to the correct length rather than truncated. Generate a fresh random nonce per encryption and store it alongside the ciphertext. Never echo a secret back into an input field: show a masked value and a "replace" control, so a saved credential cannot be read out of the settings page HTML by anyone who reaches wp-admin.

**Amended 2026-09-05, on the owner accepting the mitigation recorded under Alternatives.** Stored values additionally carry, in a versioned envelope alongside the nonce and ciphertext:

1. **A format-version byte**, so the envelope can change in a later release without stranding values written by an earlier one.
2. **A short non-secret fingerprint of the derived key** — a truncated hash of the key, never the key itself. On load, the plugin recomputes the fingerprint and compares.

On a fingerprint mismatch the plugin enters a distinct `credentials_unreadable` state. In that state it (a) shows an admin notice that names salt rotation as the likely cause and re-entering the four keys as the fix, (b) surfaces the same message on the settings page rather than only in a notice that can be dismissed and forgotten, and (c) **refuses to schedule new posts**, rather than scheduling posts that are guaranteed to fail at send time. It does not attempt to decrypt and it does not treat the condition as an API error. `SPEC.md` specifies the exact envelope layout, and this state is a required transition in the test suite.

**Consequences**

*Easier:* A stolen database alone does not yield working credentials. Authenticated encryption means a corrupted or tampered value fails loudly instead of producing a broken signature and an unexplained 401. Masked display removes the most common accidental leak: a screenshot or a support session showing the settings page.

*Harder:* Key management now exists where it did not before, and with it a class of failures that look nothing like an encryption bug — see below. Backup and restore across sites stops working transparently: restoring a database to a site with different salts yields undecryptable credentials. Every read path must handle a decrypt failure, and the ciphertext format needs a version marker so it can change later without stranding stored values.

**Alternatives rejected**

- *Store the secrets in plain text.* Rejected: free to implement, and it makes any database exposure an immediate account compromise.
- *Require the secrets to be defined as constants in `wp-config.php`.* Genuinely more secure — nothing sensitive reaches the database at all — but it puts credential entry outside the admin UI and contradicts FR-1.1, which specifies settings fields. Worth reconsidering as an *optional override* that takes precedence over the stored value; that is additive and does not change this ADR.
- *Encrypt with a key stored in the database next to the ciphertext.* Rejected: that is obfuscation, not encryption. It defends against nothing that matters.
- *Use `openssl_encrypt` with AES-256-GCM.* Comparable security, but requires the OpenSSL extension, whereas sodium is in core. No advantage here.

**Disagreement recorded — salt rotation destroys stored credentials silently** *(raised 2026-09-05; accepted by the owner the same day and folded into the Decision above; OQ-17 closed. Kept in full, because an ADR that hides why it changed is worth less than one that shows it.)*

I agree with encrypting, with sodium, and with the masked-field rule. I disagree with keying from `wp_salt('auth')` without a recovery path, and I am recording this rather than changing the recommendation.

Rotating the salts in `wp-config.php` is routine: it is standard incident response after a suspected compromise, several security plugins offer it as a one-click action, and some hosts do it during migrations. The moment it happens, every stored secret becomes permanently undecryptable. Under the brief as written, nothing detects this. The plugin discovers it at send time, inside a WP-Cron event, where it surfaces as a signing failure — most likely an HTTP 401, which FR-4.9 correctly treats as terminal and does not retry. The admin notice would tell the owner their credentials need attention, which is true but misleading: the credentials in the Console are fine, and re-pasting them is the fix, but nothing says so. Worse, this happens right after a security incident, when the owner is already looking for signs of compromise and a mysterious auth failure reads as evidence of one.

Two changes would close this without altering the decision:

1. Store a short non-secret fingerprint of the key alongside the ciphertext — for example the first bytes of a hash of the derived key, plus a format-version byte. On load, compare. A mismatch means the salts changed.
2. On mismatch, set the plugin to a distinct `credentials_unreadable` state: fail the *settings page* loudly with a notice that names salt rotation as the cause and says re-entering the four keys fixes it, and refuse to schedule new posts rather than scheduling posts that are certain to fail at send time.

Both are small. Item 1 is a few bytes of stored metadata. Item 2 reuses the admin-notice mechanism FR-5.2 already requires. Together they convert a silent, misattributed failure into an accurate instruction.

**Outcome.** John accepted the mitigation on 2026-09-05. Both items are now part of the Decision above and go into `SPEC.md`. OQ-17 is closed. `README.md` still states the underlying limit the brief identified — that this is defence in depth only, and anyone holding both the database and the filesystem can decrypt — because the mitigation makes key loss *legible*, not impossible.

---

## ADR-004 — Use WP-Cron via `wp_schedule_single_event()`; do not ship Action Scheduler or any other scheduler

**Status:** **accepted** 2026-09-05

**Date:** 2026-09-05

**Context**

- **VERIFIED** (brief §1.4) WP-Cron runs only when a request hits the site. On a low-traffic site a job scheduled for 60 minutes after publish may run at 60 minutes, at 3 hours, or whenever the next visitor arrives. Page caches make it worse, because a cached response never boots WordPress.
- The plugin's entire scheduling need is one event per post, carrying one post ID, fired once.
- Action Scheduler is a large dependency with its own database tables, its own admin UI, and its own queue-runner semantics. It is bundled by WooCommerce and by several other plugins, which means version conflicts when two copies of different versions load in one request.
- A custom background runner would mean the plugin ships its own scheduler, which brief §1.4 forbids outright.
- §0 makes simplicity a hard constraint: where two designs meet the requirement, take the one with less code and fewer moving parts.

**Decision**

Schedule the delayed send with `wp_schedule_single_event( $timestamp, 'srl_send_post', [ $post_id ] )`. Ship no scheduler, no background process, and no external dependency in v1. Compensate for WP-Cron's imprecision with three things the brief requires rather than with more machinery:

1. `INSTALLATION.md` instructs the owner to set `DISABLE_WP_CRON` and call `wp-cron.php` from a real system cron every minute. This is the only way the delay is accurate.
2. The settings page shows a cron health panel: the timestamp of the last WP-Cron run, and a warning when it is more than five minutes old.
3. "Delay" is defined and documented as **"not before"**, never "at". The plugin publishes at the first cron run at or after the scheduled time, and `INSTRUCTIONS.md` states that tolerance so it reads as designed behaviour rather than a bug.

Even with delay 0 the send goes through the scheduler (FR-3.2), so there is exactly one send path and the editor's save request never blocks on an X API call.

**Consequences**

*Easier:* No tables beyond the log table. No dependency conflicts. Nothing to uninstall except options, meta, one table, and scheduled events. The whole scheduling layer is two WordPress core functions, which the WordPress test suite already supports directly. The retry backoff in FR-4.8 reuses the same mechanism, so retries need no second design.

*Hostinger specifics, settled 2026-09-05 (OQ-11).* The host supports this decision without compromise. Single plans allow two cron jobs and Premium and above allow unlimited; the plugin needs one. Hostinger's own WordPress guide gives `define( 'DISABLE_WP_CRON', true );` and a `wget -O /dev/null -o /dev/null https://yoursite.com/wp-cron.php?doing_wp_cron` replacement, which is the exact shape this ADR needs. Two cautions carry into Phase 6, both recorded in `OPENQUESTIONS.md` under "Conflict: OQ-11". First, Hostinger recommends running that cron **twice an hour**; this plugin requires **every minute**, because a 30-minute interval turns a 60-minute delay into 60-to-90 minutes and turns delay-0 into "within half an hour". `INSTALLATION.md` must specify `* * * * *` *and say why*, so the owner does not later restore the host's default. Second, Hostinger documents that cron jobs "may be interrupted or fail to run altogether" when account resource limits are hit — which is precisely the condition FR-1.6's health panel exists to surface, and an argument for the panel being a required feature rather than a nicety.

*Harder:* Timing accuracy is the site administrator's responsibility, not the plugin's, and the plugin can only observe and warn. On a site with no real cron and low traffic, posts sit in `scheduled` indefinitely — which §9 requires must not break anything, and which the health panel must make visible rather than leaving the owner to notice that nothing was posted. WP-Cron can fire an event more than once under concurrent requests, which is why FR-4.1's idempotency guard and FR-4.2's `sending` lock are load-bearing rather than defensive extras, and why a double-fire test is on the acceptance checklist. Long delays are vulnerable to anything that flushes the cron array.

**Alternatives rejected**

- *Bundle Action Scheduler.* Rejected: several megabytes and multiple tables to schedule one event per post, plus a real risk of conflicting with the copy WooCommerce already loaded. Fails §0's simplicity constraint by a wide margin.
- *Ship a custom background worker or a loopback-request runner.* Rejected explicitly by brief §1.4, and it would mean owning a scheduler's failure modes for no gain over system cron.
- *Post inline during the editor's save request when the delay is 0.* Rejected by FR-3.2: it creates a second send path to test, and it makes the editor's save wait on the X API, so a slow or hanging API call becomes a hung publish button.
- *Use an external cron-ping service as the primary mechanism.* Rejected on 2026-09-05, and now moot: OQ-11 closed with the host confirmed as **Hostinger**, which provides native cron jobs in hPanel (Advanced → Cron Jobs). No third party needs to hold a key to whether this site's posts go out.

---

## ADR-005 — Build hashtags from the post's own tags, joined in PascalCase; overrides a brief non-goal

**Status:** **accepted** 2026-09-05

**Date:** 2026-09-05

**Context**

- **The brief forbids this.** `PROJECTBRIEF.md` §3 "Non-goals for v1" says, verbatim: "Hashtag generation, AI-written captions, URL shortening, UTM appending." The section opens with "Do not build them." `readme.txt` carried the same line under "What it does not do". The owner asked for the feature anyway on 2026-09-05, in those words: "Add a feature that adds hashtags to the social post using the post tags linked to the article."
- The owner's instruction outranks the brief, because the brief is a record of what the owner asked for and this is the owner asking for something else. But `AGENTS.md` requires the disagreement be stated rather than silently resolved, which is what this ADR is for. At decision time the non-goal line was left standing in `PROJECTBRIEF.md` as the historical record, and only `readme.txt` was corrected, because it describes the shipped plugin rather than the original ask. **See the Update below: the owner subsequently asked for the brief to be amended too.**
- There is a narrower reading under which no conflict exists: the brief forbids hashtag *generation*, and nothing here generates a hashtag. Every hashtag is a term the author already typed into the post's tag box, mechanically transformed. That reading is defensible and it is probably what the non-goal meant, sitting as it does beside "AI-written captions". It is recorded as a note, not leaned on: the decision stands on the owner's instruction.
- X ends a hashtag at the first character outside `[letter, digit, underscore]`. This is what makes the multi-word question a correctness question rather than a cosmetic one: the tag `co-op` with its spaces merely stripped ships as `#co`, and `rock 'n' roll` ships as `#rock`. Both are *wrong* hashtags, and both would ship silently.

**Decision**

Convert each `post_tag` term to one hashtag by splitting on everything outside `[letter, digit, mark, underscore]`, capitalising the first letter of any word the author left entirely lower-case, and concatenating with no separator. Full rules and worked examples in `SPEC.md` §7.6.

Three sub-decisions, each of which could have gone another way:

1. **PascalCase, not lower-case and not underscores.** It survives punctuation, which lower-case-and-strip does not. A screen reader segments `#MachineLearning` and reads `#machinelearning` as one unpronounceable run, so the choice has an accessibility dimension rather than only a stylistic one. And it is the convention on X, so the output reads as deliberate.
2. **Existing capitalisation is preserved.** Upper-casing every word unconditionally turns `iPhone SE` into `#IphoneSe`, which is a different hashtag from the one the author meant. A word is capitalised only when it contains no upper-case letter already.
3. **Hashtags are dropped whole, from the end, before the title is truncated.** A hashtag cut in half is a different hashtag, not a shorter one, so the block is never truncated the way the title is; and a tag is worth less than the words the author wrote. The consequence worth stating plainly: a title long enough to truncate sheds every hashtag first, and the text it then produces is byte-identical to what the same title produced before this feature existed. §7.3's guarantee that the title always gets at least 134 weighted characters survives untouched.

Off by default, like the master switch, and capped at three hashtags per post by default. An existing site's tag vocabulary was not written with hashtags in mind — tags like `2026 predictions` or `part 2` are ordinary and read badly as hashtags — so the owner should see the output before it ships.

**Consequences**

*Easier:* No new UI beyond two controls, no new storage, no new API call, and no new failure mode. The whole feature is three pure functions in `SRL_Text` plus one `wp_get_post_terms()` read at send time, so all but one of its nine tests run in the WordPress-free unit suite. Because hashtags are dropped rather than truncated, the composition's hardest property — never exceeding 280 weighted — is unchanged and its existing tests still bound it.

*Harder:* The plugin now has an opinion about how the owner's taxonomy should read on X, and that opinion can be wrong for a specific tag with no per-tag override to correct it. Two premises about X's own rendering are unverified (OQ-20); neither can fail a send, but either could make a hashtag read differently than intended. And `PROJECTBRIEF.md` §3 now contains a line the plugin does not honour, which is a documentation hazard for anyone reading the brief as current scope rather than as the original ask — this ADR is the pointer that resolves it.

**Alternatives rejected**

- *Lower-case and strip spaces.* Rejected: produces `#co` for `co-op`, and defeats screen-reader segmentation.
- *Underscore separator.* Rejected: legal on X but unconventional, and each underscore costs a weighted character against the title's budget.
- *Skip any tag containing a space.* Rejected: the safest rule and the least useful one, since most real tags are multi-word. It trades a small correctness risk for dropping most of the feature.
- *Truncate the hashtag block to fit.* Rejected: a truncated hashtag is a wrong hashtag pointing at a real and unrelated conversation, which is worse than no hashtag.
- *Let hashtags shorten the title.* Rejected: it inverts the priority. The author wrote the title; the tags are metadata.
- *A per-tag override field, so the owner can pin `#SEO` to a tag named `search engine optimisation`.* Rejected for v1 under §0.5: it means term meta, a UI on the term edit screen, and a migration path, to fix a case the owner can already handle by renaming the tag.

**Update — 2026-09-05, after merge.** The owner asked for the brief to be brought in line with what shipped. `PROJECTBRIEF.md` §3 now carries **Amendment 1**: the hashtag clause is removed from the non-goal line, the original wording is quoted in full inside the amendment note, the brief version is bumped to 0.2, and **FR-4.13** is added to §4 alongside an updated FR-4.5 composition string. `README.md`'s "What it does not do" list is corrected the same way and gains a section describing the feature.

This changes nothing about the decision and does not retire the tension it records. Two documents now disagree about what the brief is *for*: `AGENTS.md` calls `PROJECTBRIEF.md` the record of "what the owner asked for", which argues for leaving it frozen, while the owner has asked for it to describe current scope. The amendment resolves that in favour of current scope **while preserving the original text inside the amendment note**, so neither reading loses its evidence. Anyone auditing the original ask should read §3's amendment block and this ADR together, or `git log -- PROJECTBRIEF.md`.

One item is deliberately **not** fixed: FR-4.5 in the brief still carries `**[UNVERIFIED]**: 280 for standard accounts`, which OQ-3 verified on 2026-09-05. Sweeping every stale marker in the brief is a larger job than this amendment and was not asked for; it is noted here so the omission is a recorded choice rather than an oversight.
