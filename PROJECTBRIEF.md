# PROJECTBRIEF.md — Social Relay for WordPress

**Working name:** Social Relay (slug `social-relay`, prefix `srl_`). The name is a placeholder. Confirm it in Phase 0.
**Brief version:** 0.4 — 2026-09-05 (amendments 1, 2 and 3; see §3, §0 and §4)
**Author:** John Janney
**Reader:** Claude Code CLI. Read this file in full before you write any code.

---

## 0. How to use this brief

1. This brief is the input to Phase 0 and Phase 1 of the development framework in Section 11.
2. Do not write plugin code until the Specification Gate in Section 11 is passed.
3. Where this brief says **MUST**, the requirement is fixed. Where it says **SHOULD**, you can propose a change in `DECISIONS.md`. Where it says **OPEN**, record the item in `OPENQUESTIONS.md` and ask the owner.
4. Every claim in Section 1 has a label: **[VERIFIED]** (checked against a source on 2026-09-05), **[MEASURED]** (proved by a real API call in Phase 0 — the strongest label in this document), **[INFERRED]** (a reasoned conclusion), or **[UNVERIFIED]** (not yet established). Labels were swept after Phase 0; see amendment 2.
5. Treat "simple" as a hard constraint. When two designs meet a requirement, choose the one with less code and fewer moving parts.

**Amendment 2 — 2026-09-05. Evidence labels swept after Phase 0.**

This brief was written before Phase 0 ran. Its labels recorded what was known on the day it was written, and several went stale the moment the probe returned. A label that says "check this in Phase 0" long after Phase 0 finished is worse than no label: it tells a reader the question is open when it is answered, and it hides the answer.

Every change below is a label, a pointer to the row in `OPENQUESTIONS.md` that settles it, or both. **No requirement changed.**

| Where | Was | Now | Source |
|---|---|---|---|
| §1.2 media auth | **[UNVERIFIED]** | **[MEASURED]** | OQ-2 — live call, HTTP 200 |
| §1.2 Projects | **[VERIFIED]** | **[UNVERIFIED]** | OQ-19 — four current doc pages contradict it |
| §1.3 upload path | — | **[MEASURED]** added | OQ-14 — both paths work; one-shot chosen |
| §4 FR-4.5 length limit | **[UNVERIFIED]** | **[VERIFIED]** | OQ-3 — 280, and 23 per URL |
| §5 signing example | **[UNVERIFIED]** | **[VERIFIED]** via the fallback | OQ-12 — the example was not found; RFC 5849 used |
| §9 PHP floor | "confirm in Phase 0" | **8.2** | OQ-5 |
| §10 CI matrix | PHP 8.1 | PHP 8.2 | follows OQ-5 |

**One label is deliberately downgraded, not raised.** §1.2's claim that a v2 App must sit inside a Project was labelled **[VERIFIED]** and the current documentation no longer supports it. Correcting only the labels that improved would have left the one misleading claim standing, so it is marked **[UNVERIFIED]** and points at OQ-19.

**Two labels correctly stay [UNVERIFIED]**, because they are still unknown, not stale: the per-image media cost (**OQ-1b**) and — recorded in `SPEC.md` §17 as OPEN-5 rather than here — whether the create-post path is `/2/tweets` or `/2/posts` (**OQ-15**).

**Three contradictions are annotated rather than rewritten**, all for the same reason: they are instructions rather than labels, and the shipped documents they describe are already correct, so the risk is that someone regenerates a correct document *from* a stale template.

1. **§8's host allowlist.** It instructs that outbound requests go to `api.x.com` **and `upload.x.com`**. OQ-13 proved the whole flow against `api.x.com` alone, and `upload.x.com` is the legacy v1.1 host that §1.3 forbids building on, so `SPEC.md` **INV-3** allows one host only. Recorded as `SPEC.md` §17 **OPEN-7**.
2. **§12's `AGENTS.md` template** repeats that allowlist in the invariant list it prescribes, and §12 says `AGENTS.md` is *"revised after Phase 8"*. The shipped `AGENTS.md` correctly names one host; following the template unrevised would put the forbidden one back into the file that agents read first.
3. **§12's `INSTALLATION.md` template** specifies step 2 as "create a Project, then an App inside it", unconditionally, while §1.2's Project claim is now **[UNVERIFIED]** (OQ-19). The shipped `INSTALLATION.md` already handles it conditionally; the template would replace correct conditional advice with the claim the current documentation does not support.

Items 2 and 3 were **missed in the first pass of this amendment and caught by review on PR #3** — the same defect as §8, one level of indirection away. In each case the line carries an inline correction, and the original instruction stays visible so the record of what the brief asked for is intact.

---

## 1. Facts that constrain the design

Read these before you make any design decision. Several change the shape of the plugin.

### 1.1 X API access is pay-per-use, and a post with a URL is expensive

- **[VERIFIED]** X ended its free API tier for new developers on 2026-02-06. New developers buy credits in the X Developer Console and pay per request. Legacy Basic and Pro plans are closed to new sign-ups.
- **[VERIFIED]** Rate card as of the April 2026 repricing: `POST /2/tweets` without a URL costs about $0.015 per request. `POST /2/tweets` **with a URL costs about $0.20 per request**. Prices are set by X and can change. Confirm the current rate on the official pricing page during Phase 0.
- **[INFERRED]** This plugin posts a URL in every post by design. Each post will bill at the URL rate. For a site that publishes 20 posts a month, the cost is about $4. For 500 posts a month, the cost is about $100. Document this in `INSTALLATION.md` so the site owner can budget.
- **[VERIFIED]** X reports that billing for the media-upload calls under pay-per-use is not fully documented. Developers have asked X to clarify. Treat the per-image cost as **[UNVERIFIED]** and measure it against the Developer Console usage report. *Amendment 2:* this label is **correct and stays** — it is `OPENQUESTIONS.md` **OQ-1b**, still open. It is now measurable earlier than Phase 10: the Phase 0 probe made six billable-or-not requests in a two-second window against a freshly funded account, so the credit-balance delta answers it.
- **[REQUIREMENT]** The plugin MUST keep an accurate local count of API requests it makes, by endpoint, so the owner can reconcile against the X invoice.

### 1.2 Authentication

- **[UNVERIFIED]** X API v2 requires the App to sit inside a Project in the Developer Console. An App outside a Project fails on v2 calls. *Amendment 2 downgraded this from **[VERIFIED]**.* Four current documentation pages describe an App-centric console with no Project concept, and the console has moved to `console.x.com`; the requirement was probably dropped or absorbed in the February 2026 rework, but silence across four pages is not a statement that Projects are gone. `OPENQUESTIONS.md` **OQ-19**. *Corrected 2026-09-05:* this row previously said nothing in the plugin depends on it. That may be wrong. Two documentation pages read directly show an App-centric console with no Project step, but a developer-forum thread title reports **"No Projects section in console, POST /2/tweets returns 403"** — the one endpoint the plugin cannot do without, and the one Phase 0 never tested because it costs money and publishes (**OQ-15**). The thread itself could not be read. OQ-19 and OQ-15 are now treated as one check, settled the first time "Send test post" runs.
- **[VERIFIED]** Two user-context auth methods exist: OAuth 1.0a (consumer key/secret + access token/secret, generated once in the Console) and OAuth 2.0 Authorization Code with PKCE (needs a callback URL, token refresh, and `offline.access` scope).
- **[RECOMMENDED]** Use **OAuth 1.0a with owner-generated user tokens** for v1. Reason: the site owner posts to their own account. There is no third-party user, so there is no need for an authorization flow, a callback endpoint, or refresh-token logic. The owner pastes four strings into the settings page. This removes the largest source of complexity and the largest source of silent failure (expired refresh tokens). Record this as ADR-001. OAuth 2.0 PKCE is a documented non-goal for v1.
- **[MEASURED]** `POST /2/media/upload` **accepts OAuth 1.0a user context.** *Amendment 2, was [UNVERIFIED].* Proved on 2026-09-05 at 22:49 UTC by `bin/verify-x-api.php` against `api.x.com`, API version `2.168`: `GET /2/users/me` returned 200 **first**, so the signer and credentials were proved correct before any media call, and then every upload step returned 200 with `x-access-level: read-write`. `OPENQUESTIONS.md` **OQ-2**. This is what unblocked ADR-001 and ADR-002.

### 1.3 Attaching the featured image

- **[VERIFIED]** X does not fetch an image from a URL at post time. To attach an image, the client uploads the bytes to `POST /2/media/upload` (simple upload for images; chunked upload is the recommended path), receives a `media_id`, and passes it in `media.media_ids` on `POST /2/tweets`. *Amendment 2:* **[MEASURED]** both the one-shot and the chunked paths work (**OQ-14**), and `SPEC.md` §8.2 requires the **one-shot** path in v1 — one call instead of three, under §0.5's simplicity constraint — with chunked kept as the documented fallback. The two return different JSON shapes for the same data (`height`/`width` versus `h`/`w`), so no parser may assume one.
- **[VERIFIED]** The v1.1 media upload endpoint is legacy. Build on v2 media upload. Do not build on v1.1.
- **[INFERRED]** An alternative is to attach no image and rely on X's link-preview card, which reads `og:image` from the post URL. This is simpler, but the card depends on X's crawler and on the site's Open Graph tags, and X has changed card rendering several times since 2023. The owner asked for the feature image to be posted. Upload it explicitly. Record this as ADR-002.
- **[REQUIREMENT]** If the post has no featured image, or the image upload fails after retries, the plugin MUST still publish the text-and-URL post and MUST record that the image was omitted.

### 1.4 The delay feature depends on WordPress cron, which is not a real clock

- **[VERIFIED]** WP-Cron runs only when a request hits the site. On a low-traffic site, a job scheduled for 60 minutes after publish can run at 60 minutes, or at 3 hours, or at whatever time the next visitor arrives. Page caches make this worse because cached pages do not trigger WP-Cron.
- **[REQUIREMENT]** The plugin MUST schedule the delayed post with `wp_schedule_single_event()`. The plugin MUST NOT ship its own scheduler, background process, or external dependency (for example Action Scheduler) in v1.
- **[REQUIREMENT]** `INSTALLATION.md` MUST instruct the owner to disable the built-in trigger (`DISABLE_WP_CRON`) and call `wp-cron.php` from a real system cron every minute. This is the only way the delay is accurate.
- **[REQUIREMENT]** The settings page MUST show a cron health indicator: the timestamp of the last WP-Cron run and a warning if it is more than 5 minutes old.
- **[REQUIREMENT]** The plugin MUST treat "delay" as "not before". It publishes at the first cron run at or after the scheduled time. Document this tolerance in `INSTRUCTIONS.md`.

### 1.5 "Newly published" is a state transition, not a save event

- **[VERIFIED]** WordPress fires `save_post` on every edit, including edits to posts that are already published, and on autosaves and revisions.
- **[REQUIREMENT]** The plugin MUST hook `transition_post_status` and act only when the new status is `publish` and the old status is not `publish`. This covers draft→publish, pending→publish, and future→publish (WordPress scheduled posts). It MUST ignore publish→publish (edits).
- **[REQUIREMENT]** The plugin MUST post each WordPress post to X at most once, ever, unless the owner explicitly requests a repost. Enforce this with post meta, not with in-memory state.

---

## 2. Goal

Publish the title, featured image, and permalink of each newly published WordPress post to one X account, after a configurable delay, with no duplicate posts and no silent failures.

## 3. Non-goals for v1

Record each of these in `README.md` under "Not in scope". Do not build them.

- Other networks (Bluesky, Mastodon, LinkedIn, Facebook, Threads). Design the provider interface so a second network can be added later, but implement only X.
- Multiple X accounts.
- OAuth 2.0 PKCE flow.
- Custom message templates with tokens beyond the three fields (title, image, URL). A single optional prefix/suffix string is allowed.
- AI-written captions, URL shortening, UTM appending.
- Posting for custom post types other than `post` (make the post type list filterable, but ship with `post` only).
- Analytics, engagement reads, or any read endpoint. Reads cost money and add nothing to the goal.
- Multisite network activation.
- Gutenberg sidebar panel. A classic meta box is enough and works in both editors.

**Amendment 1 — 2026-09-05. Hashtags left the non-goals and were built.**

The fifth item above originally read: *"Hashtag generation, AI-written captions, URL shortening, UTM appending."* The owner asked for hashtags built from the post's own tags on 2026-09-05, after the Specification Gate had passed, and the feature is now merged to `main`.

The line is **edited rather than annotated in place**, so §3 reads as current scope rather than as a list with a footnote contradicting it. Nothing is lost: the original wording is preserved here, in **ADR-005**, and in the git history.

The distinction the amendment rests on is worth stating, because it is why the rest of that line is untouched: **nothing generates a hashtag.** Every hashtag is a `post_tag` term the author already typed, mechanically transformed. AI-written captions stay on the non-goal list beside it precisely because they would be generated. ADR-005 records this reading as supporting rather than load-bearing — the decision stands on the owner's instruction, not on the wording.

Specified in `SPEC.md` §7.6 and added below as **FR-4.13**. Two premises about how X renders hashtags remain unverified and are tracked as **OQ-20**; neither can fail a send.

## 4. Functional requirements

Number each requirement. Each one MUST map to at least one automated test in Phase 5.

### FR-1 Settings (Settings → Social Relay)

- FR-1.1 Fields: API Key, API Key Secret, Access Token, Access Token Secret. Store encrypted (see Section 8).
- FR-1.2 Field: Default delay. Unit selector (minutes / hours). Range 0 to 72 hours. Default 60 minutes.
- FR-1.3 Field: Enabled (master switch). Default off after install so an unconfigured plugin never fires.
- FR-1.4 Field: Optional text prefix and suffix (each ≤ 60 characters).
- FR-1.5 Button: "Send test post". Posts a fixed test string with no URL (so it bills at the cheap rate) and shows the raw API response. This is the connectivity check.
- FR-1.6 Panel: Cron health (Section 1.4).
- FR-1.7 Panel: API usage counter for the current calendar month, by endpoint.
- FR-1.8 Panel: Log of the last 50 attempts (post title, scheduled time, sent time, result, X post ID or error).

### FR-2 Per-post control (meta box on the post edit screen)

- FR-2.1 Checkbox: "Post to X" (default from the master switch; the owner can uncheck per post before publishing).
- FR-2.2 Field: Delay override for this post (optional; blank means use the default).
- FR-2.3 Read-only status: Not scheduled / Scheduled for {time} / Sent {time} — {link to X post} / Failed — {reason}.
- FR-2.4 Button: "Cancel scheduled post" (visible only while status is Scheduled).
- FR-2.5 Button: "Repost now" (visible only when status is Sent or Failed; requires a confirmation click; this is the only path to a second post).
- FR-2.6 Button: "Post to X now" (visible only when the post is published and its status is Not scheduled or Cancelled; requires a confirmation click; goes through the scheduler at delay 0, never inline). This is the owner's path for a post the automatic trigger in Section 1.5 never covered: one published before the plugin was installed, one skipped by the import, bulk-edit or freshness guards, one published with a switch off, or one whose scheduled post was cancelled. It is a *first* send, so the at-most-once rule in Section 1.5 is untouched; it does not apply to a post that is already Sent, where "Repost now" remains the only path. Added by amendment 3.

**Amendment 3 — 2026-09-05. A manual send for any published post.**

Section 1.5 defines the automatic trigger as the transition to `publish`, and Section 2's goal is worded around "each newly published" post. Nothing in the brief gave the owner a way to send a post that trigger had not reached — a post older than the plugin, or one the guards in `SPEC.md` §10.3 deliberately skipped — short of unpublishing and republishing it, which the freshness guard then rejects for anything older than a day. The owner asked for that path on 2026-09-05, after the Specification Gate had passed.

**No requirement is edited.** FR-2.6 is added beside the two existing buttons because it is the same shape as them: a nonce-protected, capability-checked, confirmed click on the post edit screen that schedules a single cron event. The goal sentence in Section 2 still describes the automatic behaviour, and the at-most-once rule stands unchanged because a post that has never been sent is being sent once. The guards that exist to stop *unintended* paid posts — import, bulk edit, freshness — are bypassed by design here, because a confirmed click on one post is the intent they exist to detect the absence of.

Specified in `SPEC.md` §10.1 as **TR-16** and §13 as **FR-2.6**; recorded as **ADR-006**.

### FR-3 Scheduling

- FR-3.1 On the transition to `publish` (Section 1.5), if "Post to X" is checked and the master switch is on, compute `scheduled_at = now + delay` and schedule one single event carrying the post ID.
- FR-3.2 If delay is 0, still go through the scheduler (do not post inline during the editor request). Reason: keeps one code path, and keeps the editor save fast.
- FR-3.3 Write post meta `_srl_status = scheduled` and `_srl_scheduled_at` in the same request.
- FR-3.4 If the post is unpublished, trashed, or deleted before the event fires, the event MUST be cleared and status set to `cancelled`.

### FR-4 Sending

- FR-4.1 When the event fires, re-read the post. If it is no longer `publish`, cancel. If `_srl_status` is not `scheduled`, exit without posting (idempotency guard against double-fired events).
- FR-4.2 Set `_srl_status = sending` before the first API call. This is the lock.
- FR-4.3 Read the current title and permalink at send time, not at schedule time. The owner may have corrected a typo during the delay.
- FR-4.4 If a featured image exists: fetch the file from the local filesystem (not over HTTP). Downscale if larger than 5 MB or the X pixel limit. Upload via `POST /2/media/upload`. On failure after retries, continue without the image and record `image_omitted = true` with the reason.
- FR-4.5 Compose text: `{prefix} {title} {suffix} {hashtags}\n{permalink}`, where the hashtag block is empty unless FR-4.13 is enabled. Truncate the title, never the URL, so the text fits X's length limit. X counts every URL as 23 characters. The limit is **[VERIFIED]** at **280** for standard accounts, and every URL counts as **23** regardless of its real length — <https://docs.x.com/fundamentals/counting-characters>, read 2026-09-05, `OPENQUESTIONS.md` **OQ-3** (*amendment 2; was [UNVERIFIED]*). Counting is **weighted**, not `strlen()` or `mb_strlen()`: see `SPEC.md` §7.1.
- FR-4.6 `POST /2/tweets` with text and `media_ids` if present.
- FR-4.7 On HTTP 2xx: store the X post ID, set `_srl_status = sent`, `_srl_sent_at`, write a log row.
- FR-4.8 On HTTP 429 or 5xx: reschedule with backoff (5 min, 15 min, 60 min), maximum 3 retries, then `failed`.
- FR-4.9 On HTTP 401 or 403: do not retry. Set `failed`, log the response body, and show an admin notice that credentials need attention.
- FR-4.10 On any other 4xx: do not retry. Set `failed` and log the body.
- FR-4.11 On a duplicate-content error from X (X rejects identical text posted recently): set `failed` with reason `duplicate`, do not retry.
- FR-4.12 Every API call increments the usage counter (FR-1.7) whether it succeeds or fails.
- FR-4.13 Optionally append the post's own tags as hashtags. Off by default; capped at a configurable number per post. Multi-word tags join in PascalCase, because X ends a hashtag at the first character outside `[letter, digit, underscore]` — stripping spaces alone turns the tag `co-op` into `#co`, which is a wrong hashtag rather than an ugly one. Capitalisation the author typed is preserved, so `iPhone SE` stays `#iPhoneSE`. Hashtags are dropped whole, from the end, before the title is truncated: a title long enough to truncate sheds every hashtag first, so FR-4.5's guarantees are unchanged. Added by amendment 1; see §3.

### FR-5 Logging and notices

- FR-5.1 Keep a custom table `{prefix}srl_log` (Section 6). Do not write to `error_log` in normal operation.
- FR-5.2 Show one dismissible admin notice per failed post, with a link to the post.
- FR-5.3 Optional email to the admin address on `failed`. Default off.

## 5. Architecture

Keep it flat. Target: fewer than 15 PHP files, no build step, no Composer runtime dependencies.

```
social-relay/
  social-relay.php          bootstrap, constants, autoloader, activation/deactivation
  includes/
    class-plugin.php        wires hooks
    class-settings.php      options page, sanitization, encryption
    class-scheduler.php     transition_post_status → wp_schedule_single_event
    class-publisher.php     the send pipeline (FR-4)
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
  readme.txt                WordPress.org format, even if never submitted
```

- OAuth 1.0a signing: implement HMAC-SHA1 signing in `class-x-provider.php` (about 80 lines). Do not add a library. Test the signer against the **RFC 5849 §3.4.1.1 test vector**. *Amendment 2, was [UNVERIFIED]:* X's published signing example was **not located** on `docs.x.com` on 2026-09-05, so the fallback this line already named is what shipped — and it is **[VERIFIED]**, in that the signer reproduces the RFC's published signature base string exactly and its HMAC-SHA1 step cross-checks against an independent implementation. `OPENQUESTIONS.md` **OQ-12**.
- All HTTP through `wp_remote_post()` / `wp_remote_get()`. This makes every call mockable with the `pre_http_request` filter in tests.
- Provider interface: `send( PostPayload $payload ): SendResult`. `PostPayload` carries title, permalink, image path or null, prefix, suffix. Adding Bluesky later is one new class.

## 6. Data model

**Post meta (`post` table, by post ID):**

| Key | Type | Values |
|---|---|---|
| `_srl_enabled` | bool | per-post checkbox |
| `_srl_delay_override` | int minutes or empty | |
| `_srl_status` | string | `none`, `scheduled`, `sending`, `sent`, `failed`, `cancelled` |
| `_srl_scheduled_at` | int UTC timestamp | |
| `_srl_sent_at` | int UTC timestamp | |
| `_srl_remote_id` | string | X post ID |
| `_srl_attempts` | int | |
| `_srl_last_error` | string | |

**Custom table `{prefix}srl_log`:** `id`, `post_id`, `provider`, `event` (scheduled/sent/failed/cancelled/retry/test), `http_status`, `remote_id`, `message` (≤ 2 KB, response body truncated), `created_at`. Index on `post_id` and `created_at`. Prune rows older than 90 days on a daily cron event.

**Options:** one option `srl_settings` (array). One option `srl_usage` (array keyed by `YYYY-MM` then endpoint). One option `srl_cron_last_run` (int).

## 7. State machine

```
none ──publish + enabled──▶ scheduled ──event fires──▶ sending ──2xx──▶ sent
                                │                        │
                                │ unpublish/trash        ├──429/5xx, attempts<3──▶ scheduled (backoff)
                                ▼                        │
                            cancelled                    └──4xx or attempts=3──▶ failed

sent / failed ──"Repost now" + confirm──▶ scheduled (delay 0)
```

Write this diagram, kept current, into `README.md`. Every transition MUST have a test.

## 8. Security

- Store the four X secrets encrypted at rest with `sodium_crypto_secretbox` keyed from `wp_salt('auth')`. Never echo a secret back into an input field; show a masked value and a "replace" control. Record as ADR-003. **[INFERRED]** This is defense in depth only; anyone with database and filesystem access can decrypt. State that limit in `README.md`.
- Nonces on every form and every AJAX action. Capability `manage_options` for settings; `edit_post` for the meta box.
- `$wpdb->prepare()` on every query. Add a test that greps for raw `$wpdb->query(` with interpolated variables and fails the build.
- Sanitize every input; escape every output (`esc_html`, `esc_attr`, `esc_url`).
- The plugin makes outbound requests to `api.x.com` and `upload.x.com` only. Hard-code the hosts. Do not accept a host from settings. **⚠ Corrected by amendment 2 — do not implement this line as written.** The allowlist is **`api.x.com` alone**. OQ-13 proved the entire flow, media upload included, against that host without ever contacting `upload.x.com`, which is the legacy v1.1 host that §1.3 forbids building on — so allowlisting it would permit exactly the call this brief prohibits. Binding form is `SPEC.md` **INV-3**; the departure is recorded as `SPEC.md` §17 **OPEN-7**. The instruction is left visible rather than deleted because it is what the brief asked for.
- `uninstall.php` removes options, post meta, the log table, and scheduled events.

## 9. Compatibility floor

- WordPress 6.5+, **PHP 8.2+**. *Amendment 2:* the floor was "8.1+ (confirm in Phase 0)"; **OQ-5** resolved it to **8.2**, on the owner's decision and on the evidence that PHP 8.1's security support ended 2025-12-31 while PHP ≥ 8.2 covers 61.29% of WordPress installs. `SPEC.md` §17 **OPEN-10**. Note that 8.2 itself leaves security support on 2026-12-31, so a later raise to 8.3 is planned rather than surprising — `VERSIONING.md`.
- Classic editor and block editor.
- Must not break when WP-Cron is disabled and no system cron exists (posts stay `scheduled`; the health panel warns).

## 10. Verification strategy

"Flawless" is not a feeling. It is a set of checks that run without a human.

- **Unit and integration tests:** PHPUnit with the WordPress test suite via `wp-env`. Mock X with `pre_http_request`. Fixtures for: 2xx create, 2xx media, 429, 500, 401, 403, duplicate error, malformed JSON, timeout.
- **Coverage target:** every FR in Section 4 has a named test. Every state transition in Section 7 has a test. Report coverage; do not set a percentage target.
- **Static analysis:** PHPStan level 6 and PHPCS with the WordPress Coding Standards ruleset. Both run in CI and block merge.
- **CI:** GitHub Actions on push and PR. Matrix: **PHP 8.2** and latest; WP 6.5 and latest. *Amendment 2:* was "PHP 8.1 and latest", which follows the floor OQ-5 settled.
- **Local acceptance (Phase 6):** `wp-env` site with a real X sandbox app on a throwaway account. A scripted checklist in `INSTRUCTIONS.md`: publish with delay 2 min → post appears; publish and trash within the delay → nothing posts; publish with no featured image → text post appears; kill credentials → failed with notice; double-fire the cron event by hand → one post.
- **Real-world acceptance (Phase 10):** run on the owner's staging site for 7 days with real cron. Reconcile the usage counter against the X Developer Console.

Model self-reports ("tests pass") are not evidence. The CI run link is evidence.

## 11. Phased plan

The framework below is the owner's standard. Each phase has an **exit criterion** that a human or CI can check. Do not move past a gate without the listed artifact.

| Phase | Work | Exit criterion (artifact) |
|---|---|---|
| **0 Discovery** | Resolve every **[UNVERIFIED]** item in Section 1 with a real API call or a doc link. Resolve `OPENQUESTIONS.md` items marked `blocking`. Confirm name, PHP floor, license. | `OPENQUESTIONS.md` has no `blocking` items open. `DECISIONS.md` has ADR-001..003. |
| **1 Specification** | Write `SPEC.md`: expanded FRs, API contract per endpoint with request/response examples, error matrix, settings schema, DB schema, test list (test name → FR). | `SPEC.md` committed. Every FR has ≥1 named test in the list. |
| **2 Spec review** | A separate Claude session (fresh context, review-only prompt) reads `PROJECTBRIEF.md` + `SPEC.md` and returns numbered findings. | `reviews/spec-review-1.md` committed. |
| **Specification Gate** | Owner reads the findings and the spec. | Owner writes `APPROVED` and date at the top of `SPEC.md`. |
| **3 Implementation planning** | `PLAN.md`: ordered build units, each ≤ 1 session, each with its tests. Unit order: bootstrap+settings → encryption → provider signer → scheduler → publisher → meta box → log → cron health → usage → notices → uninstall. | `PLAN.md` committed. `STATE.md` created with all units `todo`. |
| **4 Repo init** | Git repo `johnjanney/social-relay`, `.gitignore`, `wp-env.json`, `phpunit.xml`, `phpcs.xml`, `phpstan.neon`, CI workflow, `CLAUDE.md`, all documents in Section 12 as skeletons. | CI runs green on an empty test suite. |
| **5 Iterative development** | One unit per session. Write the tests first for the unit, then the code. Commit per unit. Update `STATE.md` and `CHANGELOG.md` [Unreleased]. | Each unit: tests pass locally, `STATE.md` updated, commit pushed. |
| **Automated verification** | CI: PHPUnit matrix, PHPStan, PHPCS, secret-scan, version-consistency check (plugin header, constant, `readme.txt`, `CHANGELOG.md`). | Green CI on `main`. |
| **6 Local acceptance** | The scripted checklist in Section 10 on `wp-env` with a sandbox X app. | `reviews/acceptance-local-1.md` with every checklist item marked pass/fail and the X post URLs. |
| **7 Independent review** | Fresh Claude session, review-only, full repo. Focus: security (Section 8), state machine gaps, cron edge cases. | `reviews/code-review-1.md` numbered findings. |
| **8 Review response** | Fix or formally decline each finding. Declines go in `DECISIONS.md`. | Every finding has a commit hash or a DECISIONS entry. |
| **Quality Gate** | CI green, review findings closed, coverage report attached. | Owner writes `QUALITY GATE PASSED` + date in `STATE.md`. |
| **9 Release candidate** | Tag `v1.0.0-rc.1`. Build the zip with a script (`bin/build.sh`) that excludes tests and dev files. | Tagged. Zip attached to a GitHub pre-release. |
| **10 Real-world acceptance** | 7 days on staging with real cron and real credentials. Log every post. Reconcile usage counter vs. X Console. | `reviews/acceptance-real-1.md` with the 7-day log and the reconciliation table. |
| **Release Gate** | Zero `failed` posts caused by the plugin in Phase 10 (X-side outages are excused if logged). | Owner writes `RELEASE GATE PASSED` in `STATE.md`. |
| **11 Release** | Tag `v1.0.0`. Move [Unreleased] to [1.0.0] in `CHANGELOG.md`. GitHub release with zip and notes. | Release published. |
| **12 Maintenance** | Monitor the X pricing page and API changelog monthly. Patch releases per `VERSIONING.md`. | `CHANGELOG.md` entry per release. |

## 12. Required project documents

Create each file in Phase 4 as a skeleton with the headings below. Fill it in during the phase named. Keep every file in the repository root except where noted.

### README.md
Audience: anyone who lands on the repo. Contents, in this order: one-paragraph purpose; what it posts and what it does not (copy Section 3 as "Not in scope"); the cost warning from Section 1.1 in plain language; the state diagram from Section 7; the cron requirement in one sentence with a link to `INSTALLATION.md`; links to every other document in this section; license line. Keep it under 150 lines. Fill in Phase 4; update at each release.

### CHANGELOG.md
Format: Keep a Changelog 1.1.0. Sections per release: Added, Changed, Fixed, Removed, Security. Maintain an `[Unreleased]` section at the top. Every commit that changes behavior adds a line here in the same commit. CI fails if a version tag exists with no matching heading. Start in Phase 4.

### VERSIONING.md
State the rule: Semantic Versioning 2.0.0. Define what counts as breaking for a plugin (settings schema change that needs migration, removed filter/action hook, raised PHP or WP floor). List the four places the version string lives (plugin header `Version:`, `SRL_VERSION` constant, `readme.txt` `Stable tag`, `CHANGELOG.md` heading) and state that `bin/check-version.sh` enforces agreement. State the branch/tag policy: `main` is always releasable; tags `vX.Y.Z`; release candidates `vX.Y.Z-rc.N`. Write in Phase 4.

### DECISIONS.md
Architecture Decision Records, one heading per decision, numbered ADR-001 upward. Template per record: Status (proposed/accepted/superseded), Date, Context (the facts), Decision (one paragraph), Consequences (what becomes easier, what becomes harder), Alternatives rejected (one line each). Seed in Phase 0 with ADR-001 OAuth 1.0a, ADR-002 explicit image upload, ADR-003 secret storage, ADR-004 WP-Cron without Action Scheduler. Every declined review finding in Phase 8 becomes an ADR.

### OPENQUESTIONS.md
A table: ID, Question, Owner (John / Claude), Blocking (yes/no), Status (open/resolved), Resolution and date. Seed in Phase 0 with every **[UNVERIFIED]** item from Section 1 plus the list in Section 13. A `blocking: yes` item that is `open` blocks the Specification Gate. When resolved, move the answer into `SPEC.md` or `DECISIONS.md` and mark the row resolved; do not delete rows.

### INSTALLATION.md
Audience: a site administrator, not a developer. Steps, numbered, one action per step: (1) create an X Developer account and buy credits, with the cost estimate formula from Section 1.1; (2) create an App — **⚠ amendment 2: write this step conditionally.** It originally read "create a Project, then an App inside it". §1.2's Project requirement is now **[UNVERIFIED]** (OQ-19), and the shipped `INSTALLATION.md` already handles it conditionally ("If you see Projects, create one and put the App inside it"). This template must not be used to replace that with an unconditional instruction while OQ-19 is open; (3) set App permissions to Read and Write; (4) generate the four OAuth 1.0a keys and where to find each; (5) upload the plugin zip and activate; (6) paste keys, run "Send test post"; (7) set up real cron — exact `wp-config.php` line and an example crontab line, plus the equivalent for common hosts; (8) confirm the cron health panel shows green; (9) set the default delay and enable. Include a "Cost" section and an "Uninstall" section. Write in Phase 6 from the acceptance test run, so every step was actually performed.

### INSTRUCTIONS.md
Audience: the person who writes posts. How the meta box works, what each status means, how the delay tolerance behaves (Section 1.4), how to cancel, how to repost, what a failure notice means and what to do, how to read the log. Include the local acceptance checklist from Section 10 as an appendix so a tester can rerun it. Write in Phase 6.

### AGENTS.md
Audience: any AI coding agent (Claude Code reads `CLAUDE.md`; `AGENTS.md` is the vendor-neutral file and `CLAUDE.md` MUST contain only `@AGENTS.md` plus Claude-specific notes). Contents: the one-sentence purpose; the "simple" rule from Section 0; the file map from Section 5; the invariants that no change may break (post at most once; never post inline in the editor request; only `api.x.com` — **⚠ amendment 2:** this template originally read "only `api.x.com` and `upload.x.com`", which §8's correction and `SPEC.md` **INV-3** both forbid, for the reason §1.3 gives. The shipped `AGENTS.md` correctly lists **one** host; the Phase 8 revision this section prescribes must not reintroduce the second; every query prepared; every FR has a test); the commands to run tests, lint, and build; the rule that `STATE.md` and `CHANGELOG.md` update in the same commit as the code; the rule that a self-reported "tests pass" is not accepted without the command output; where the reviews live. Keep it under 120 lines. Write in Phase 4; revise after Phase 8.

Also create, though not on the owner's list: `SPEC.md` (Phase 1), `PLAN.md` and `STATE.md` (Phase 3), `CLAUDE.md` (Phase 4, imports `AGENTS.md`), `LICENSE` (Phase 4), `reviews/` directory (Phase 2 onward).

## 13. Open questions to seed OPENQUESTIONS.md

| ID | Question | Blocking |
|---|---|---|
| OQ-1 | Confirm the current X rate card, especially the URL-post price and the media-upload price. | yes |
| OQ-2 | Confirm `POST /2/media/upload` accepts OAuth 1.0a user context. Make one real call. | yes |
| OQ-3 | Confirm the current post length limit and URL character weight for the owner's account type. | yes |
| OQ-4 | Plugin name and slug. Check that the name does not conflict on WordPress.org and does not use "X" or "Twitter" as a leading word (trademark). | yes |
| OQ-5 | PHP floor: 8.1 or 8.2? | yes |
| OQ-6 | License. GPL-2.0-or-later is required if it ever goes to WordPress.org. Confirm. | yes |
| OQ-7 | Should the "Send test post" include a URL so it tests the exact billing path, at $0.20 per test? Recommendation: no; document the difference. | no |
| OQ-8 | Should scheduled WordPress posts (status `future`) use the delay from their scheduled time, or post at publish time + delay? Recommendation: publish time + delay, same as every other path. | no |
| OQ-9 | Should the plugin downscale large images, or reject them and post without an image? Recommendation: downscale with `wp_get_image_editor()`. | no |
| OQ-10 | Email on failure: on or off by default? Recommendation: off. | no |
| OQ-11 | Does the owner's host support system cron? If not, which external ping service? | no |

---

*End of brief. Begin Phase 0.*
