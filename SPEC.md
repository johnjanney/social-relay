# SPEC.md — Social Relay

**Status:** DRAFT — awaiting Phase 2 review and the Specification Gate.
**Spec version:** 1.0-draft.1
**Date:** 2026-09-05
**Inputs:** `PROJECTBRIEF.md` v0.1, `DECISIONS.md` ADR-001..004 (all accepted), `OPENQUESTIONS.md` (no blocking row open).

> **Specification Gate.** When the owner approves this document, they write `APPROVED` and the date at the top of this file. Until then no plugin code is written. This line is the gate marker; do not remove it.

---

## 0. How to read this document

1. This is the implementation contract. Where it disagrees with `PROJECTBRIEF.md`, the brief wins unless this document names the disagreement explicitly and gives the evidence. There are four such places, all collected in §17.
2. **MUST** is fixed. **SHOULD** is a default that an ADR may overturn. **OPEN** is an unresolved item and is listed in §17.
3. Every functional requirement in §13 carries at least one named test in §16. A requirement with no test is a defect in this document, not a matter of judgement.
4. Facts labelled **[MEASURED]** came from the live probe run of `bin/verify-x-api.php` on 2026-09-05 at 22:49 UTC. Those are the strongest facts in this document. Facts labelled **[DOC]** came from published documentation with a URL and read date. Facts labelled **[OPEN]** are not yet established.
5. Sizes, limits, and formats in this document are normative. Do not round them in code.

---

## 1. Scope

**Goal.** Publish the title, featured image, and permalink of each newly published WordPress post to one X account, after a configurable delay, with no duplicate posts and no silent failures.

**Not in scope for v1** (from brief §3, unchanged): other networks; multiple X accounts; OAuth 2.0 PKCE; message templates beyond a prefix and suffix; hashtag generation, AI captions, URL shortening, UTM appending; post types other than `post`; analytics or any read endpoint used for engagement; multisite network activation; a Gutenberg sidebar panel.

**Target shape.** Fewer than 15 PHP files. No build step. No Composer runtime dependencies. No bundled scheduler.

---

## 2. Terminology and invariants

| Term | Meaning |
|---|---|
| **send** | One complete attempt to publish one WordPress post to X, including any media upload. |
| **attempt** | One execution of the send pipeline. A retry is a new attempt against the same post. |
| **scheduled time** | The UTC timestamp at which the single cron event is registered to fire. |
| **send time** | The moment the pipeline actually runs, which is at or after the scheduled time, never before. |
| **weighted length** | X's character count, in which most characters weigh 1 and others weigh 2. Defined precisely in §7. |

### 2.1 Invariants

These hold at every point in the codebase. A change that breaks one of these is a defect regardless of what else it achieves. They are repeated in `AGENTS.md` in Phase 4.

- **INV-1** A WordPress post is sent to X **at most once**, ever, unless the owner explicitly requests a repost through FR-2.5. Enforced by persisted post meta, never by in-memory state.
- **INV-2** The plugin **never** calls the X API during an editor save request, or during any request that a human is waiting on. Every send happens inside a cron event.
- **INV-3** The plugin makes outbound requests to **`api.x.com` only**. The host is a compile-time constant. It is never read from settings, filters, or the database.
- **INV-4** Every database query goes through `$wpdb->prepare()`. No interpolated variable ever reaches `$wpdb->query()`.
- **INV-5** A failure is always recorded. The plugin never swallows an error into silence.
- **INV-6** The featured image never blocks the post. If the image cannot be uploaded, the text-and-URL post still goes out.

---

## 3. Configuration schema

One option, `srl_settings`, holding an array. Autoload **yes** — it is read on every admin request that renders the meta box.

| Key | Type | Default | Constraint |
|---|---|---|---|
| `schema_version` | int | `1` | Bumped only when a migration is required. See §3.2. |
| `enabled` | bool | `false` | Master switch. **MUST** default off, so an unconfigured install never fires. |
| `api_key` | string | `''` | Encrypted envelope, §6. |
| `api_secret` | string | `''` | Encrypted envelope, §6. |
| `access_token` | string | `''` | Encrypted envelope, §6. |
| `access_token_secret` | string | `''` | Encrypted envelope, §6. |
| `delay_value` | int | `60` | `0` to the maximum implied by `delay_unit`. |
| `delay_unit` | string | `'minutes'` | One of `minutes`, `hours`. |
| `prefix` | string | `''` | Maximum 60 characters, weighted (§7). |
| `suffix` | string | `''` | Maximum 60 characters, weighted (§7). |
| `email_on_failure` | bool | `false` | OQ-10, resolved: off. |

Two further options, both autoload **no**:

| Option | Type | Purpose |
|---|---|---|
| `srl_usage` | array | API request counts. Structure in §12. |
| `srl_cron_last_run` | int | UTC timestamp of the last observed WP-Cron execution. §11.3. |

### 3.1 Delay bounds

The stored pair `(delay_value, delay_unit)` **MUST** resolve to between **0 and 259200 seconds** (72 hours), per FR-1.2. Validation happens on the resolved seconds, not on the raw number, so `delay_value = 4321` with `delay_unit = minutes` is rejected as exceeding 72 hours rather than silently accepted.

**MUST:** the delay is capped at 72 hours and the cap is not merely a UI constraint. Uploaded media expires after 86400 seconds **[MEASURED]**, which is why §8.2 requires the image to be uploaded at send time and not earlier. A delay longer than 24 hours is therefore fine; a *design* that uploaded early would not be.

### 3.2 Sanitization

Every field is sanitized on save and validated before use.

- `enabled`, `email_on_failure` — cast to bool.
- `delay_value` — `absint()`, then range-checked against §3.1.
- `delay_unit` — whitelist match; anything else falls back to `minutes`.
- `prefix`, `suffix` — `sanitize_text_field()`, then rejected if weighted length exceeds 60.
- The four credential fields — see §6.4 for the replace-or-keep rule. A submitted empty value means *keep the stored value*, not *erase it*, because the form never renders the real secret.

---

## 4. Post meta schema

All keys are prefixed `_srl_` and are therefore protected meta, invisible in the custom-fields UI.

| Key | Type | Values |
|---|---|---|
| `_srl_enabled` | `'1'` / `'0'` | Per-post checkbox (FR-2.1). |
| `_srl_delay_override` | int minutes, or absent | Blank means use the default (FR-2.2). |
| `_srl_status` | string | `none`, `scheduled`, `sending`, `sent`, `failed`, `cancelled`. |
| `_srl_scheduled_at` | int | UTC timestamp. |
| `_srl_sent_at` | int | UTC timestamp. |
| `_srl_remote_id` | string | The X post id. |
| `_srl_attempts` | int | Count of completed attempts. Starts at 0. |
| `_srl_last_error` | string | Human-readable reason, ≤ 500 characters. |
| `_srl_image_omitted` | `'1'` / absent | Set when the post went out without its featured image (FR-4.4). |
| `_srl_image_omitted_reason` | string | Why, ≤ 200 characters. Only meaningful when the above is set. |

**MUST:** `_srl_status` is the single source of truth for INV-1. No other value, and no in-memory flag, may be consulted to decide whether a post has already been sent.

---

## 5. Database schema

One custom table, `{$wpdb->prefix}srl_log`. Created on activation and on version upgrade via `dbDelta()`.

```sql
CREATE TABLE {$wpdb->prefix}srl_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  provider varchar(32) NOT NULL DEFAULT 'x',
  event varchar(20) NOT NULL,
  http_status smallint(5) unsigned DEFAULT NULL,
  remote_id varchar(64) DEFAULT NULL,
  message text DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY post_id (post_id),
  KEY created_at (created_at)
) {$charset_collate};
```

Notes that are requirements, not style:

- `dbDelta()` is whitespace-sensitive. Two spaces after `PRIMARY KEY`, one space around each column definition, `KEY` not `INDEX`. Getting this wrong makes `dbDelta` recreate the table on every upgrade check.
- `created_at` stores **UTC**, written with `gmdate( 'Y-m-d H:i:s' )`. It is never `current_time()`, which returns site-local time.
- `event` is one of: `scheduled`, `sent`, `failed`, `cancelled`, `retry`, `test`, `credentials_unreadable`.
- `message` is truncated to **2048 bytes** before insert, on a UTF-8 character boundary so the column never holds a split multi-byte sequence.
- `post_id` is `0` for rows that are not about a post — the connectivity test (FR-1.5) and credential-state events.

### 5.1 Retention

A daily cron event `srl_prune_log` deletes rows where `created_at` is older than **90 days**. The delete is bounded to 1000 rows per run so a neglected install cannot produce one enormous query; if a run hits the bound, the next day's run continues. Pruning is not required for correctness, only for size.

---

## 6. Secret storage envelope

Implements ADR-003, including the salt-rotation mitigation accepted on 2026-09-05.

### 6.1 Key derivation

```
key = sodium_crypto_generichash( wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES )
```

`wp_salt()` returns a string of arbitrary length, not a 32-byte key. It **MUST** be hashed to length, never truncated or padded.

### 6.2 Envelope format

Stored value is the ASCII string `srl1:` followed by base64 of:

| Offset | Bytes | Contents |
|---|---|---|
| 0 | 1 | Format version. `0x01`. |
| 1 | 4 | Key fingerprint. First 4 bytes of `sodium_crypto_generichash( key, '', 32 )`. Not secret. |
| 5 | 24 | Nonce, from `random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES )`. Fresh per encryption. |
| 29 | n | Ciphertext from `sodium_crypto_secretbox()`, which includes its own authentication tag. |

The fingerprint is a hash **of the derived key**, never of the plaintext secret and never of the salt. It reveals nothing about the credential and nothing usable about the key.

### 6.3 Decryption and the `credentials_unreadable` state

On every read:

1. If the value does not start with `srl1:`, treat it as **absent**. Do not attempt a legacy plaintext read.
2. Recompute the fingerprint from the current derived key. If it does not match the stored fingerprint, **do not attempt decryption**. Enter `credentials_unreadable`.
3. If `sodium_crypto_secretbox_open()` returns `false` despite a matching fingerprint, the ciphertext is corrupt or tampered. Enter `credentials_unreadable`.

In `credentials_unreadable` the plugin **MUST**:

- show a persistent admin notice, and the same message on the settings page, stating that the site's security salts appear to have changed and that re-entering the four keys will fix it;
- **refuse to schedule new sends**, rather than scheduling sends guaranteed to fail hours later;
- write one `credentials_unreadable` log row, not one per page load;
- leave already-scheduled events in place. They will fail at send time and be recorded normally. Cancelling them would destroy information the owner needs.

The message **MUST NOT** say "invalid credentials". The credentials in the X Console are fine. Saying otherwise sends the owner to the wrong place, which is the entire failure this mitigation exists to prevent.

### 6.4 Display and replacement

A stored secret is **never** rendered into an input value. The settings page shows a masked representation — first 4 characters, then a fixed-width mask, then the last 2, matching the convention already used in `bin/verify-x-api.php` — beside a "Replace" control. Submitting the form with a credential field left empty keeps the stored value. Clearing a credential requires an explicit "Remove" action with its own nonce.

---

## 7. Text composition and the weighted length algorithm

Implements FR-4.5. This is the single most error-prone piece of logic in the plugin, because it is easy to write something that looks right and truncates one character too late on a post containing an emoji.

### 7.1 The counting rules

**[DOC]** <https://docs.x.com/fundamentals/counting-characters>, read 2026-09-05: "Posts on X can contain up to **280 characters**"; "All URLs are wrapped with `t.co` shortener and count as **23 characters**, regardless of the original length."

The exact weights come from X's own `twitter-text` configuration, version 3, read 2026-09-05 from <https://raw.githubusercontent.com/twitter/twitter-text/master/config/v3.json>:

```json
{
  "version": 3,
  "maxWeightedTweetLength": 280,
  "scale": 100,
  "defaultWeight": 200,
  "emojiParsingEnabled": true,
  "transformedURLLength": 23,
  "ranges": [
    { "start": 0,    "end": 4351, "weight": 100 },
    { "start": 8192, "end": 8205, "weight": 100 },
    { "start": 8208, "end": 8223, "weight": 100 },
    { "start": 8242, "end": 8247, "weight": 100 }
  ]
}
```

Reduced to the rules this plugin implements, with every weight divided by `scale`:

- **Limit:** 280.
- **Weight 1** for a Unicode code point in `U+0000–U+10FF`, `U+2000–U+200D`, `U+2010–U+201F`, or `U+2032–U+2037`. This covers Latin, Latin Extended, Greek, Cyrillic, Hebrew, Arabic, and common punctuation and spacing.
- **Weight 2** for every other code point. This covers CJK, Hangul, Thai, Devanagari, and emoji.
- **A URL always weighs 23**, whatever its real length, and whether it is `http` or `https`.
- **`emojiParsingEnabled` is true**, which means an emoji *sequence* counts once. A ZWJ sequence such as a family emoji is many code points but weighs **2**, not 2 per code point. Counting its code points individually over-counts by a factor of six or more and truncates titles that would have fit.

### 7.2 The composition

```
{prefix} {title} {suffix}\n{permalink}
```

- A single space joins prefix to title and title to suffix. If prefix or suffix is empty, its space is omitted too; the text never begins or ends with a stray space, and never contains a double space from an absent part.
- Exactly one `\n` separates the text block from the permalink. It weighs 1.
- The permalink is the **last** element and is never modified, never shortened, and never truncated.

### 7.3 The budget

```
budget_for_text = 280 - 23 (permalink) - 1 (newline) = 256
```

The prefix and suffix are capped at 60 weighted characters each (§3). With both at maximum and both joining spaces present, the worst case leaves `256 - 60 - 60 - 2 = 134` weighted characters for the title. The title is therefore always allotted at least 134.

### 7.4 Truncation

**MUST:** only the title is ever truncated. Never the URL, never the prefix, never the suffix.

1. Compute the weighted length of the full composition.
2. If it is ≤ 280, use it unchanged.
3. Otherwise compute `title_budget = 256 - weighted(prefix) - weighted(suffix) - (joining spaces)`, then reduce further by 1 to reserve room for the ellipsis.
4. Walk the title by grapheme cluster, accumulating weight, and stop at the last cluster that fits. Never split a grapheme cluster, an emoji sequence, or a multi-byte character.
5. Prefer a word boundary: if a space exists within the final 12 weighted characters of the truncation point, cut there instead, so the result does not end mid-word.
6. Append `…` (U+2026, weight 1). Do not use three periods, which weigh 3.

**MUST:** the algorithm is conservative. If a code point's weight is uncertain, treat it as 2. Truncating one character early is invisible. Overflowing produces an HTTP 400 from X and a `failed` post, which is the failure this whole section exists to avoid.

### 7.5 Read at send time

Per FR-4.3, the title and permalink are read **at send time**, not at schedule time. The owner may have corrected a typo during the delay, and the shipped post must reflect the correction.

---

## 8. API contract

Host is `api.x.com` for every call (INV-3). Transport is `wp_remote_post()` / `wp_remote_get()`, never cURL directly, so that `pre_http_request` can intercept every call in tests.

All examples below are **[MEASURED]** — real requests and real responses captured on 2026-09-05 at 22:49 UTC, API version `2.168`. Identifying values are replaced with placeholders; structure and field names are verbatim.

### 8.1 Authentication

OAuth 1.0a HMAC-SHA1, user context, per ADR-001. Every request carries an `Authorization: OAuth ...` header built as specified in RFC 5849.

**MUST — the rule that breaks signers:** the request body is included in the signature base string **only** when the body is `application/x-www-form-urlencoded`. This plugin sends JSON and multipart bodies exclusively, so **no body parameter is ever signed**. Only the `oauth_*` parameters, plus any query-string parameters, enter the base string.

The signer is verified against the worked example in RFC 5849 §3.4.1.1. That vector, and the reason X's own published example is not used, are recorded in `OPENQUESTIONS.md` under OQ-12.

Every media response carried `x-access-level: read-write` **[MEASURED]**. An App set to read-only will fail here, not at post time.

### 8.2 Upload the image — `POST /2/media/upload`

One-shot upload, per ADR-002 as amended. **MUST NOT** use the three-step `initialize` / `append` / `finalize` flow in v1; both work **[MEASURED]**, and §0 requires the design with fewer moving parts.

**Request** — `multipart/form-data`:

| Field | Value |
|---|---|
| `media_category` | `tweet_image` |
| `media` | the image bytes, with a filename and a correct `Content-Type` |

**Response, HTTP 200** **[MEASURED]**:

```json
{"data":{"expires_after_secs":86400,"id":"20963700000000000000","image":{"h":16,"image_type":"image/png","w":16},"media_key":"3_20963700000000000000","size":100}}
```

Requirements derived from this response:

- Read the media id from **`data.id`** only. It is the one field stable across both upload paths.
- **MUST NOT** read `image.height` or `image.width`. The one-shot path returns `image.h` and `image.w`; only the chunked `finalize` returns the long forms **[MEASURED]**. Treat all dimension fields as optional and absent.
- **MUST NOT** compare `data.size` against the local file size. X reported `size: 100` for an 89-byte source PNG **[MEASURED]**. X's accounting is its own.
- `expires_after_secs` is **86400**. This is why the upload happens at send time (§3.1, ADR-002).
- Observed rate limit: `x-rate-limit-limit: 500` per 15-minute window on this endpoint, against 1875 for each chunked step **[MEASURED]**. Far above anything this plugin will do; recorded so a future bulk feature does not rediscover it.

**Image preparation before upload** (FR-4.4):

1. Resolve the featured image to a **local filesystem path** via `get_attached_file()`. **MUST NOT** fetch over HTTP; the site may be behind basic auth, a staging password, or a CDN.
2. If the file is missing or unreadable, skip the image and set `_srl_image_omitted`.
3. If the file exceeds **5 MB** **[DOC]**, or the pixel bounds pinned in §17 OPEN-2, downscale with `wp_get_image_editor()` into a temporary file. Delete the temporary file after the request, on success and on failure alike.
4. If `wp_get_image_editor()` is unavailable, or downscaling fails, skip the image and set `_srl_image_omitted`.

### 8.3 Create the post — `POST /2/tweets`

**[OPEN — OQ-15.]** The path is specified as `/2/tweets`. One secondary source referred to `/2/posts`. This was deliberately not probed, because creating a post costs money and publishes publicly. It is settled at Phase 6 by FR-1.5. The provider **MUST** hold the path in a single constant so the correction is one line.

**Request** — `application/json`:

```json
{"text":"Prefix Post title suffix\nhttps://example.com/post-slug/","media":{"media_ids":["20963700000000000000"]}}
```

The `media` key is omitted entirely when there is no image. It is never sent as `null` or as an empty array.

**Response, HTTP 201 or 200:**

```json
{"data":{"id":"1234567890123456789","text":"..."}}
```

Store `data.id` as `_srl_remote_id`. The public URL of the post is `https://x.com/i/web/status/{id}`, which is used for the meta box link in FR-2.3.

### 8.4 Connectivity check — `GET /2/users/me`

Not used by the plugin at runtime. Documented here because it is what `bin/verify-x-api.php` uses to prove the signer before touching media, and because §17 OPEN-4 proposes it as a cheaper credential check than FR-1.5's test post.

**Response, HTTP 200** **[MEASURED]**:

```json
{"data":{"id":"20638500000000000000","name":"Account Name","username":"account_handle"}}
```

Observed rate limit: 75 per 15-minute window **[MEASURED]**.

### 8.5 Timeouts

Every request sets an explicit timeout: **15 seconds** for `POST /2/tweets`, **30 seconds** for the media upload. WordPress defaults to 5 seconds, which is too short for an image upload on a slow connection, and an unset timeout in a cron context can hang a worker.

---

## 9. Error matrix

Applies to every X API call. `attempts` is `_srl_attempts` **after** the current attempt is counted.

| Condition | Retry? | Resulting status | Notes |
|---|---|---|---|
| HTTP 200 / 201, body parses, `data.id` present | — | `sent` | Store `_srl_remote_id`, `_srl_sent_at`. |
| HTTP 429 | yes, if `attempts < 3` | `scheduled`, else `failed` | Backoff §9.1. Honour `x-rate-limit-reset` if it is further out than the backoff. |
| HTTP 500–599 | yes, if `attempts < 3` | `scheduled`, else `failed` | Backoff §9.1. |
| Transport error or timeout (`WP_Error`) | yes, if `attempts < 3` | `scheduled`, else `failed` | **Not in the brief; specified here.** See §9.2. |
| HTTP 401 | **no** | `failed` | Credentials or App permissions. Admin notice per FR-4.9. Log the body. |
| HTTP 403 | **no** | `failed` | As 401. Commonly a read-only App. |
| HTTP 400 with a duplicate-content error | **no** | `failed`, reason `duplicate` | FR-4.11. See §9.3. |
| Any other HTTP 4xx | **no** | `failed` | Log the body. FR-4.10. |
| HTTP 2xx, body does not parse as JSON, or `data.id` is absent | **no** | `failed`, reason `malformed_response` | **Deliberately not retried.** See §9.4. |

The **media upload** call uses the same matrix with one difference: every terminal outcome sets `_srl_image_omitted` and continues to the post, per INV-6. A media failure never fails the send.

### 9.1 Backoff

Retry delays are **5 minutes, 15 minutes, 60 minutes**, in that order, for attempts 1, 2 and 3. A retry is scheduled with `wp_schedule_single_event()` exactly as the original send was, and writes a `retry` log row. Maximum 3 retries, then `failed`.

Because the delay is a WP-Cron schedule, these are also "not before" times (§11.1). A 5-minute backoff on a site with a one-minute system cron fires at 5 to 6 minutes.

### 9.2 Transport errors — a gap in the brief

Brief §4 FR-4.8 through FR-4.11 enumerate HTTP status codes and say nothing about a request that never produced one: a DNS failure, a TLS failure, a connection reset, or a timeout. `wp_remote_post()` returns a `WP_Error` in all of these, and code that only inspects `wp_remote_retrieve_response_code()` reads that as `0` and falls through to whichever branch is last.

**Specified:** a `WP_Error` is treated as a retryable failure, on the same backoff as a 5xx. The `WP_Error` code and message are logged with `http_status` left `NULL`, which is what distinguishes a transport failure from an HTTP failure in the log.

This is a specification decision filling a genuine gap, not a reinterpretation. It is listed in §17 as OPEN-1 so the reviewer sees it rather than absorbing it.

### 9.3 Duplicate content, and why it is a safety net

X rejects a post whose text matches one posted recently. FR-4.11 treats this as terminal, which is right.

It is also the backstop that makes retrying safe. A 5xx or a timeout may mean the post was actually created and only the response was lost. Retrying such a request risks a second post, which would break INV-1. X's duplicate rejection catches exactly that case.

**Specified:** when a duplicate error arrives on an attempt where `_srl_attempts > 0` — that is, on a retry rather than a first try — the plugin sets `failed` with reason `duplicate_on_retry`, and the admin notice says that the post **may already be on the timeline** and gives a link to check. Treating this identically to a first-attempt duplicate would tell the owner the post failed when it most likely succeeded.

### 9.4 A 2xx that cannot be parsed

If X returns success but the body is not JSON, or lacks `data.id`, the post was very likely created and the plugin merely cannot record its id. Retrying would risk a duplicate for no gain, so this is terminal. Status is `failed`, reason `malformed_response`, and the notice tells the owner to check the timeline before using "Repost now". This preserves INV-1 at the cost of one occasionally pessimistic status, which is the correct trade.

---

## 10. State machine

```
none ──publish + enabled──▶ scheduled ──event fires──▶ sending ──2xx──▶ sent
                                │                        │
                                │ unpublish/trash/delete ├──429/5xx/transport, attempts<3──▶ scheduled
                                ▼                        │
                            cancelled                    └──4xx, or attempts=3──▶ failed

sent / failed ──"Repost now" + confirm──▶ scheduled (delay 0)
scheduled ──"Cancel scheduled post"──▶ cancelled
```

### 10.1 Transition table

Every row has at least one test in §16.

| # | From | Trigger | To | Side effects |
|---|---|---|---|---|
| TR-1 | `none` | `transition_post_status` to `publish`, from a status that is not `publish`, with per-post and master switches on | `scheduled` | Write `_srl_scheduled_at`, schedule one event, log `scheduled`. |
| TR-2 | `none` | Same transition, but a switch is off, or the post type is not enabled | `none` | Nothing scheduled, nothing logged. |
| TR-3 | `scheduled` | Post leaves `publish`, is trashed, or is deleted | `cancelled` | Clear the scheduled event, log `cancelled`. |
| TR-4 | `scheduled` | Owner clicks "Cancel scheduled post" | `cancelled` | Clear the event, log `cancelled`. |
| TR-5 | `scheduled` | Cron event fires, post still `publish` | `sending` | Compare-and-swap per §11.4. |
| TR-6 | `scheduled` | Cron event fires, post no longer `publish` | `cancelled` | Log `cancelled`. No API call. |
| TR-7 | `sending` | HTTP 2xx with a parseable id | `sent` | Store `_srl_remote_id`, `_srl_sent_at`, log `sent`. |
| TR-8 | `sending` | Retryable failure, `attempts < 3` | `scheduled` | Increment `_srl_attempts`, schedule backoff, log `retry`. |
| TR-9 | `sending` | Terminal failure, or `attempts = 3` | `failed` | Store `_srl_last_error`, log `failed`, raise notice. |
| TR-10 | `sent` or `failed` | "Repost now", confirmed | `scheduled` | Reset `_srl_attempts` to 0, schedule at delay 0, log `scheduled`. |
| TR-11 | any | A second cron event fires for a post not in `scheduled` | unchanged | Exit without an API call. INV-1. Log nothing. |

### 10.2 The `sending` state is a lock, not a label

`sending` exists solely to make TR-11 possible. Any post found in `sending` when an event fires has either an attempt in flight or a crashed attempt, and in neither case may a second attempt begin.

**Specified:** a post that has been in `sending` for more than **15 minutes** is considered crashed. The next cron pass moves it to `failed` with reason `stalled`, and does **not** retry it automatically, because a crash mid-send cannot be distinguished from a send whose response was lost. The owner decides, via "Repost now". Without this rule a crashed send would stay in `sending` forever and never appear in any failure list — a silent failure, which INV-5 forbids.

This staleness rule is not in the brief. It is listed in §17 as OPEN-3.

---

## 11. Scheduling and cron health

Implements ADR-004 and brief §1.4.

### 11.1 "Delay" means "not before"

The plugin schedules with `wp_schedule_single_event( $timestamp, 'srl_send_post', array( $post_id ) )` and nothing else. WP-Cron fires at the first run at or after the scheduled time. `INSTRUCTIONS.md` states this tolerance in the owner's own words in Phase 6.

### 11.2 The single event argument

The event carries **only the post id**. It never carries the title, the permalink, the delay, or credentials. Everything else is read at send time, which is what makes FR-4.3 work and what keeps the cron array small.

**MUST:** `wp_clear_scheduled_hook( 'srl_send_post', array( $post_id ) )` is called with the identical argument array used to schedule. WordPress matches events by hook **and** arguments; a mismatch silently clears nothing, and TR-3 would then leave a live event behind that fires against a trashed post.

### 11.3 Cron health

A recurring event `srl_heartbeat` runs on a custom one-minute schedule, registered through `cron_schedules`. Its handler does one thing: `update_option( 'srl_cron_last_run', time(), false )`.

**Why a heartbeat rather than recording the time of the incoming request:** the health panel must answer "is WP-Cron executing?", not "did something request `wp-cron.php`?". Those differ precisely in the case that matters. If a caching layer serves `wp-cron.php` from cache, requests succeed, nothing executes, and a request-time metric would show green while every scheduled post silently stalls. A heartbeat can only be written by code that actually ran.

Panel states:

| Condition | Display |
|---|---|
| `srl_cron_last_run` is within the staleness threshold | Green. Show the timestamp. |
| Older than the threshold | Warning, with a link to `INSTALLATION.md` step 7. |
| Never set | Warning: cron has not run since the plugin was activated. |

**Threshold: 5 minutes**, per FR-1.6. **[OPEN — OQ-18]** This assumes Hostinger's cron can run every minute. If the host's minimum interval turns out to be 5 minutes, the threshold sits exactly on the boundary and will produce false warnings; it then becomes 3× the actual interval. The threshold **MUST** therefore be a single named constant, not a literal scattered through the panel code.

### 11.4 The double-fire guard

WP-Cron can fire the same event twice under concurrent requests. This is the mechanism that protects INV-1, and it is the highest-risk code in the plugin.

`update_post_meta()` is **not** atomic and **MUST NOT** be used for the claim. The transition from `scheduled` to `sending` is a compare-and-swap executed as a single SQL statement:

```php
$claimed = $wpdb->query(
    $wpdb->prepare(
        "UPDATE {$wpdb->postmeta}
            SET meta_value = 'sending'
          WHERE post_id = %d
            AND meta_key = '_srl_status'
            AND meta_value = 'scheduled'",
        $post_id
    )
);
```

- `$claimed === 1` means this process owns the send. Proceed.
- `$claimed === 0` means another process claimed it first, or the status was not `scheduled`. **Exit immediately, make no API call, log nothing.**

After a successful claim, `wp_cache_delete( $post_id, 'post_meta' )` clears the object cache, which the direct UPDATE bypassed. Omitting this leaves stale meta in a persistent object cache for the rest of the request.

This is the one place where a direct `$wpdb->query()` is correct rather than a violation of INV-4 — and it is still fully prepared, so INV-4 holds as written.

---

## 12. Usage accounting

Implements FR-1.7 and FR-4.12.

`srl_usage` holds:

```php
array(
    '2026-09' => array(
        'POST /2/tweets'       => 12,
        'POST /2/media/upload' => 10,
    ),
)
```

- The key is the UTC calendar month, `gmdate( 'Y-m' )`.
- The endpoint key is the method and path, with no host and no ids.
- **Every** API call increments its counter, success or failure, per FR-4.12. The count is of requests billed, not of requests that worked.
- Months older than 13 are dropped during the daily prune.

**Known imprecision, stated rather than hidden.** Two concurrent cron workers can read-modify-write this option and lose a count. The counter exists so the owner can reconcile against the X invoice, and a rare undercount by one does not defeat that. Adding a lock would cost more complexity than the accuracy is worth. `README.md` states that the counter is indicative and the Developer Console is authoritative.

---

## 13. Functional requirements, expanded

Each requirement lists its acceptance criteria. The test column names the tests in §16.

### FR-1 Settings — Settings → Social Relay

| ID | Requirement | Acceptance | Tests |
|---|---|---|---|
| FR-1.1 | Four credential fields, stored encrypted | Saved value is an `srl1:` envelope (§6.2); the plaintext never appears in the option, in the page HTML, or in any log row | T-101, T-102, T-103 |
| FR-1.2 | Default delay, with unit selector, 0–72 hours, default 60 minutes | A value resolving above 259200 seconds is rejected with a field error; a fresh install reads 60 minutes | T-110, T-111 |
| FR-1.3 | Master switch, default off | A freshly activated, unconfigured plugin schedules nothing on publish | T-112 |
| FR-1.4 | Optional prefix and suffix, ≤ 60 characters each | 61 weighted characters is rejected; 60 is accepted; weighting per §7 | T-113 |
| FR-1.5 | "Send test post" button, URL-free, shows the raw response | Posts a fixed string containing no URL; renders the response body verbatim; writes a `test` log row with `post_id = 0` | T-120, T-121 |
| FR-1.6 | Cron health panel | Green within the threshold, warning beyond it, warning when never set (§11.3) | T-130, T-131, T-132 |
| FR-1.7 | Usage counter for the current calendar month, by endpoint | Reflects every call including failures (§12) | T-140, T-141 |
| FR-1.8 | Log of the last 50 attempts | Shows post title, scheduled time, sent time, result, and the X post id or the error; ordered newest first | T-150 |

**FR-1.5 note.** The test post is published to the timeline and bills at the URL-free rate of $0.015. The settings page states both facts beside the button, because a button labelled "test" that costs money and posts publicly must say so before it is clicked.

### FR-2 Per-post control — meta box

| ID | Requirement | Acceptance | Tests |
|---|---|---|---|
| FR-2.1 | "Post to X" checkbox, defaulting from the master switch | Unchecking before publish prevents scheduling | T-200, T-201 |
| FR-2.2 | Per-post delay override | Blank uses the default; a set value overrides it; the 72-hour bound applies equally | T-210, T-211 |
| FR-2.3 | Read-only status line | Renders each of Not scheduled / Scheduled for {time} / Sent {time} with a link / Failed with a reason | T-220 |
| FR-2.4 | "Cancel scheduled post", visible only while `scheduled` | Clears the event and sets `cancelled` | T-230, T-231 |
| FR-2.5 | "Repost now", visible only when `sent` or `failed`, requiring confirmation | The only path to a second post; resets attempts; schedules at delay 0 | T-240, T-241, T-242 |

Both buttons require a nonce and the `edit_post` capability for the specific post. Times display in the site's timezone; they are stored in UTC.

### FR-3 Scheduling

| ID | Requirement | Acceptance | Tests |
|---|---|---|---|
| FR-3.1 | Schedule on the transition to `publish` | Fires for draft→publish, pending→publish, future→publish; never for publish→publish | T-300, T-301, T-302, T-303 |
| FR-3.2 | Delay 0 still goes through the scheduler | No API call occurs during the save request | T-310 |
| FR-3.3 | Write `_srl_status` and `_srl_scheduled_at` in the same request | Both present immediately after publish | T-311 |
| FR-3.4 | Unpublish, trash or delete clears the event and sets `cancelled` | No event remains for that post id and argument array | T-320, T-321, T-322 |

**FR-3.1 detail.** The hook is `transition_post_status`, and the guard is `$new === 'publish' && $old !== 'publish'`. Autosaves, revisions, and post types outside the enabled list are excluded before any other work. This is what makes T-303 (editing a published post posts nothing) pass.

### FR-4 Sending

| ID | Requirement | Acceptance | Tests |
|---|---|---|---|
| FR-4.1 | Re-read the post; cancel if not `publish`; exit if status is not `scheduled` | A double-fired event produces exactly one API call | T-400, T-401 |
| FR-4.2 | Set `sending` before the first API call, as a lock | Implemented as the compare-and-swap in §11.4 | T-402 |
| FR-4.3 | Read title and permalink at send time | A title changed during the delay appears in the post | T-403 |
| FR-4.4 | Upload the featured image; continue without it on failure | Media failure never fails the send; `_srl_image_omitted` records the reason | T-410, T-411, T-412, T-413 |
| FR-4.5 | Compose and truncate per §7 | Never exceeds 280 weighted; never truncates the URL | T-420 through T-427 |
| FR-4.6 | `POST /2/tweets` with text and `media_ids` when present | `media` key absent entirely when there is no image | T-430, T-431 |
| FR-4.7 | On 2xx store the id, set `sent` and `_srl_sent_at`, write a log row | All four effects occur | T-440 |
| FR-4.8 | 429 and 5xx retry with 5/15/60-minute backoff, max 3 | A fourth failure produces `failed`, not a fourth retry | T-450, T-451, T-452 |
| FR-4.9 | 401 and 403 do not retry; log the body; raise an admin notice | No retry event is scheduled | T-460, T-461 |
| FR-4.10 | Any other 4xx does not retry | Status `failed`, body logged | T-462 |
| FR-4.11 | Duplicate-content error sets `failed` reason `duplicate`, no retry | And `duplicate_on_retry` when attempts > 0, per §9.3 | T-470, T-471 |
| FR-4.12 | Every call increments the usage counter | Including failed calls | T-480 |

### FR-5 Logging and notices

| ID | Requirement | Acceptance | Tests |
|---|---|---|---|
| FR-5.1 | Custom log table; no `error_log` in normal operation | A successful run writes zero lines to `error_log` | T-500, T-501 |
| FR-5.2 | One dismissible admin notice per failed post, linking to the post | Dismissal persists; one notice per post, not per page load | T-510, T-511 |
| FR-5.3 | Optional failure email, default off | No email when off; one email per failure when on | T-520, T-521 |

---

## 14. Security requirements

Implements brief §8. Each is testable, and each has a test in §16.

- **SEC-1** The four secrets are encrypted at rest per §6. **T-600.**
- **SEC-2** No secret is ever rendered into an input value or any HTML attribute. **T-601.**
- **SEC-3** Every form and every AJAX action carries a nonce, verified before any state change. **T-610.**
- **SEC-4** Settings require `manage_options`. Meta box actions require `edit_post` for that specific post id. **T-611, T-612.**
- **SEC-5** Every query is prepared. A build-time check greps for `$wpdb->query(` with an interpolated variable and fails the build. **T-620.**
- **SEC-6** Every input is sanitized; every output is escaped with `esc_html`, `esc_attr`, or `esc_url` as appropriate. **T-621.**
- **SEC-7** Outbound requests go to `api.x.com` only. A test asserts that no other host is ever requested, by intercepting `pre_http_request` and failing on any other host. **T-622.**
- **SEC-8** `uninstall.php` removes the options, all `_srl_*` post meta, the log table, and every scheduled event. **T-630.**
- **SEC-9** A response body written to the log is truncated to 2048 bytes and is never trusted as HTML. **T-623.**

**Stated limit, per ADR-003.** This is defence in depth. The key derives from `wp-config.php`, so anyone holding both the database and the filesystem can decrypt. `README.md` says so plainly rather than implying more protection than exists.

---

## 15. Hooks

### 15.1 Actions the plugin registers

| Hook | Purpose |
|---|---|
| `transition_post_status` | Scheduling entry point (FR-3.1). |
| `srl_send_post` | The single scheduled event. One argument: post id. |
| `srl_heartbeat` | One-minute recurring cron health beat (§11.3). |
| `srl_prune_log` | Daily log and usage pruning (§5.1, §12). |
| `wp_trash_post`, `before_delete_post` | Cancellation (FR-3.4). |

### 15.2 Filters the plugin provides

Deliberately few. These exist to keep the provider interface extensible per brief §3, not to offer user-facing configuration.

| Filter | Signature | Purpose |
|---|---|---|
| `srl_post_types` | `array $types` | The post types that may be relayed. Ships as `array( 'post' )`. |
| `srl_should_send` | `bool $should, int $post_id` | Last-word veto before scheduling. Returning `false` prevents scheduling. |

**MUST NOT** add a filter that changes the outbound host. INV-3 is not negotiable through a filter.

### 15.3 The provider interface

```php
interface SRL_Provider {
    public function send( SRL_Post_Payload $payload ): SRL_Send_Result;
    public function id(): string;   // 'x'
}
```

`SRL_Post_Payload` carries: title, permalink, image path or null, prefix, suffix.
`SRL_Send_Result` carries: success flag, remote id or null, HTTP status or null, error code, error message, image-omitted flag and reason.

Adding a second network later is one new class implementing this interface. Nothing in the scheduler, the log, or the meta box refers to X directly.

---

## 16. Test list

Every FR in §13 and every transition in §10.1 appears here. Tests run under PHPUnit with the WordPress test suite via `wp-env`. Every X call is mocked through `pre_http_request`; **no test makes a network request**.

### 16.1 Fixtures

Required response fixtures, per brief §10: 2xx create, 2xx media, 429, 500, 401, 403, duplicate error, malformed JSON, timeout (`WP_Error`). Fixture bodies use the real shapes captured in §8.

### 16.2 Settings and encryption

| Test | Covers |
|---|---|
| T-101 `test_credentials_are_stored_as_srl1_envelope` | FR-1.1, SEC-1 |
| T-102 `test_plaintext_secret_never_appears_in_option_or_html` | FR-1.1, SEC-2 |
| T-103 `test_empty_credential_field_keeps_stored_value` | FR-1.1, §6.4 |
| T-104 `test_envelope_roundtrips_through_encrypt_decrypt` | §6.2 |
| T-105 `test_fingerprint_mismatch_enters_credentials_unreadable` | §6.3 |
| T-106 `test_credentials_unreadable_refuses_to_schedule` | §6.3 |
| T-107 `test_credentials_unreadable_notice_does_not_say_invalid_credentials` | §6.3 |
| T-110 `test_delay_above_72_hours_is_rejected` | FR-1.2 |
| T-111 `test_fresh_install_defaults_to_60_minutes` | FR-1.2 |
| T-112 `test_master_switch_defaults_off_and_blocks_scheduling` | FR-1.3 |
| T-113 `test_prefix_and_suffix_reject_61_weighted_characters` | FR-1.4 |
| T-120 `test_test_post_contains_no_url` | FR-1.5 |
| T-121 `test_test_post_writes_log_row_with_post_id_zero` | FR-1.5, FR-5.1 |
| T-130 `test_cron_health_green_within_threshold` | FR-1.6 |
| T-131 `test_cron_health_warns_beyond_threshold` | FR-1.6 |
| T-132 `test_cron_health_warns_when_never_run` | FR-1.6 |
| T-133 `test_heartbeat_updates_last_run_option` | §11.3 |
| T-140 `test_usage_counter_increments_on_success` | FR-1.7 |
| T-141 `test_usage_counter_increments_on_failure` | FR-1.7, FR-4.12 |
| T-150 `test_log_panel_returns_last_50_newest_first` | FR-1.8 |

### 16.3 Meta box

| Test | Covers |
|---|---|
| T-200 `test_meta_box_checkbox_defaults_from_master_switch` | FR-2.1 |
| T-201 `test_unchecked_post_is_not_scheduled` | FR-2.1, TR-2 |
| T-210 `test_blank_override_uses_default_delay` | FR-2.2 |
| T-211 `test_override_delay_wins_and_respects_72_hour_bound` | FR-2.2 |
| T-220 `test_status_line_renders_each_of_five_states` | FR-2.3 |
| T-230 `test_cancel_button_visible_only_when_scheduled` | FR-2.4 |
| T-231 `test_cancel_clears_event_and_sets_cancelled` | FR-2.4, TR-4 |
| T-240 `test_repost_button_visible_only_when_sent_or_failed` | FR-2.5 |
| T-241 `test_repost_requires_confirmation_and_nonce` | FR-2.5, SEC-3 |
| T-242 `test_repost_resets_attempts_and_schedules_at_zero_delay` | FR-2.5, TR-10 |

### 16.4 Scheduling

| Test | Covers |
|---|---|
| T-300 `test_draft_to_publish_schedules_one_event` | FR-3.1, TR-1 |
| T-301 `test_pending_to_publish_schedules_one_event` | FR-3.1, TR-1 |
| T-302 `test_future_to_publish_schedules_one_event` | FR-3.1, TR-1 |
| T-303 `test_publish_to_publish_schedules_nothing` | FR-3.1 |
| T-304 `test_autosave_and_revision_schedule_nothing` | FR-3.1 |
| T-305 `test_disabled_post_type_schedules_nothing` | FR-3.1, TR-2 |
| T-310 `test_zero_delay_still_goes_through_scheduler` | FR-3.2, INV-2 |
| T-311 `test_status_and_scheduled_at_written_in_same_request` | FR-3.3 |
| T-320 `test_unpublish_clears_event_and_cancels` | FR-3.4, TR-3 |
| T-321 `test_trash_clears_event_and_cancels` | FR-3.4, TR-3 |
| T-322 `test_delete_clears_event_and_cancels` | FR-3.4, TR-3 |
| T-323 `test_clear_uses_identical_argument_array` | §11.2 |

### 16.5 Sending

| Test | Covers |
|---|---|
| T-400 `test_double_fired_event_makes_exactly_one_api_call` | FR-4.1, INV-1, TR-11 |
| T-401 `test_event_on_unpublished_post_cancels_without_api_call` | FR-4.1, TR-6 |
| T-402 `test_claim_is_compare_and_swap_and_second_claim_returns_zero` | FR-4.2, TR-5, §11.4 |
| T-403 `test_title_edited_during_delay_is_used_at_send_time` | FR-4.3 |
| T-404 `test_post_stalled_in_sending_becomes_failed_not_retried` | §10.2 |
| T-410 `test_featured_image_is_read_from_filesystem_not_http` | FR-4.4 |
| T-411 `test_media_failure_still_publishes_text_post` | FR-4.4, INV-6 |
| T-412 `test_media_failure_sets_image_omitted_with_reason` | FR-4.4 |
| T-413 `test_oversize_image_is_downscaled_before_upload` | FR-4.4 |
| T-414 `test_missing_image_file_is_skipped_cleanly` | FR-4.4 |
| T-415 `test_media_id_read_from_data_id_only` | §8.2 |
| T-416 `test_temporary_downscaled_file_is_deleted_on_failure` | §8.2 |

### 16.6 Text composition

| Test | Covers |
|---|---|
| T-420 `test_composition_order_is_prefix_title_suffix_newline_url` | FR-4.5 |
| T-421 `test_url_weighs_23_regardless_of_real_length` | FR-4.5, §7.1 |
| T-422 `test_cjk_and_emoji_weigh_two` | §7.1 |
| T-423 `test_zwj_emoji_sequence_weighs_two_not_per_codepoint` | §7.1 |
| T-424 `test_long_title_is_truncated_with_single_ellipsis_character` | §7.4 |
| T-425 `test_url_is_never_truncated` | FR-4.5 |
| T-426 `test_truncation_never_splits_a_grapheme_cluster` | §7.4 |
| T-427 `test_result_never_exceeds_280_weighted_at_maximum_prefix_and_suffix` | §7.3 |
| T-428 `test_empty_prefix_and_suffix_produce_no_double_spaces` | §7.2 |

### 16.7 Error handling

| Test | Covers |
|---|---|
| T-430 `test_media_ids_included_when_image_present` | FR-4.6 |
| T-431 `test_media_key_absent_entirely_when_no_image` | FR-4.6, §8.3 |
| T-440 `test_success_stores_id_status_sent_at_and_log_row` | FR-4.7, TR-7 |
| T-450 `test_429_retries_with_five_minute_backoff` | FR-4.8, TR-8 |
| T-451 `test_500_retries_then_fails_on_fourth_attempt` | FR-4.8, TR-9 |
| T-452 `test_backoff_sequence_is_5_15_60_minutes` | §9.1 |
| T-453 `test_transport_error_is_retried_like_a_5xx` | §9.2 |
| T-454 `test_transport_error_logs_null_http_status` | §9.2 |
| T-460 `test_401_does_not_retry_and_raises_notice` | FR-4.9, TR-9 |
| T-461 `test_403_does_not_retry_and_raises_notice` | FR-4.9 |
| T-462 `test_other_4xx_does_not_retry` | FR-4.10 |
| T-470 `test_duplicate_on_first_attempt_fails_with_reason_duplicate` | FR-4.11 |
| T-471 `test_duplicate_on_retry_uses_duplicate_on_retry_and_warns_may_be_live` | §9.3 |
| T-472 `test_malformed_2xx_fails_without_retry` | §9.4 |
| T-480 `test_every_call_increments_usage_including_failures` | FR-4.12 |

### 16.8 Logging, notices, security

| Test | Covers |
|---|---|
| T-500 `test_log_row_written_for_each_event_type` | FR-5.1 |
| T-501 `test_no_error_log_writes_in_normal_operation` | FR-5.1 |
| T-502 `test_log_message_truncated_to_2048_bytes_on_char_boundary` | §5, SEC-9 |
| T-510 `test_one_dismissible_notice_per_failed_post` | FR-5.2 |
| T-511 `test_notice_dismissal_persists` | FR-5.2 |
| T-520 `test_no_email_when_disabled` | FR-5.3 |
| T-521 `test_one_email_per_failure_when_enabled` | FR-5.3 |
| T-600 `test_secrets_encrypted_at_rest` | SEC-1 |
| T-601 `test_no_secret_in_rendered_html` | SEC-2 |
| T-610 `test_missing_nonce_rejects_every_state_change` | SEC-3 |
| T-611 `test_settings_require_manage_options` | SEC-4 |
| T-612 `test_meta_box_actions_require_edit_post_for_that_post` | SEC-4 |
| T-620 `test_no_unprepared_wpdb_query_in_source` | SEC-5 |
| T-621 `test_all_output_is_escaped` | SEC-6 |
| T-622 `test_no_request_to_any_host_other_than_api_x_com` | SEC-7, INV-3 |
| T-623 `test_logged_body_is_truncated_and_not_trusted_as_html` | SEC-9 |
| T-630 `test_uninstall_removes_options_meta_table_and_events` | SEC-8 |

### 16.9 Coverage assertion

Every FR in §13 and every transition TR-1..TR-11 in §10.1 is named above. Per brief §10, coverage is reported but no percentage target is set. A CI step asserts that every `FR-` and every `T-n` identifier in this document appears at least once in the test list; the build fails if one does not. That check is what keeps this section honest as the spec changes.

---

## 17. Open items for the Specification Gate

Four decisions in this document go beyond the brief, plus two facts the brief left unpinned. None blocks writing the spec; all should be seen by the reviewer in Phase 2 and by the owner at the gate rather than absorbed silently.

| ID | Item | Where | Recommendation |
|---|---|---|---|
| **OPEN-1** | Transport errors and timeouts are not covered by FR-4.8..4.11. Specified as retryable, like a 5xx. | §9.2 | Accept. `wp_remote_post()` returns `WP_Error` for DNS, TLS, reset and timeout, and unhandled these fall through to whichever branch is last — a silent failure that INV-5 forbids. |
| **OPEN-2** | X's pixel limits for `tweet_image` are not pinned. Only the 5 MB byte limit is documented. | §8.2 | Downscale on bytes alone in v1, and record the observed limit during Phase 6 acceptance. Guessing a pixel bound is worse than not enforcing one. |
| **OPEN-3** | A post stuck in `sending` after a crash has no exit in the brief's state machine. Specified as `failed` reason `stalled` after 15 minutes, with no automatic retry. | §10.2 | Accept. Without it a crashed send is invisible forever. Not auto-retrying is deliberate: a crash mid-send is indistinguishable from a lost response, and retrying risks breaking INV-1. |
| **OPEN-4** | FR-1.5's "Send test post" publishes publicly and costs $0.015. `GET /2/users/me` proves credentials for about $0.010 without posting. | §8.4 | **Owner's call.** Proposal: keep FR-1.5 exactly as specified, and add a second, quieter "Check credentials" control beside it. This is an addition to the brief, so it is not being made unilaterally. |
| **OPEN-5** | The create-post path is `/2/tweets` or `/2/posts` (OQ-15). Not probed, because it costs money and publishes. | §8.3 | Hold it in one constant; settle at Phase 6 via FR-1.5. |
| **OPEN-6** | The cron staleness threshold of 5 minutes assumes Hostinger can run cron every minute (OQ-18). | §11.3 | Keep 5 minutes, as one named constant. Revisit if hPanel's minimum turns out to be 5 minutes, in which case it becomes 15. |

### Carried from `OPENQUESTIONS.md`

Still open, none blocking: **OQ-1b** media-upload pricing, **OQ-15** the create-post path, **OQ-18** every-minute cron on Hostinger, **OQ-19** whether Apps still sit inside Projects. OQ-18 and OQ-19 need one sentence each from the owner. OQ-1b and OQ-15 are measurements belonging to later phases by the brief's own design.

---

## 18. What this document does not decide

Named so that their absence is visible rather than assumed.

- **File-by-file implementation order.** That is `PLAN.md`, Phase 3.
- **Exact admin page markup and styling.** Implementation detail, constrained only by the escaping rules in SEC-6.
- **The precise wording of admin notices**, except where §6.3 forbids a specific phrasing for a specific reason.
- **CI configuration.** Phase 4, constrained by brief §10 and by the PHP 8.2 floor decided in OQ-5, which changes the matrix from the brief's "PHP 8.1 and latest" to **8.2 and latest**.
