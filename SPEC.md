# SPEC.md — Social Relay

**APPROVED — 2026-09-05, by John Janney (owner).**

Approved with the open items in §17 noted, and with three decisions recorded at the same time: OPEN-4 accepted (add a "Check credentials" control), OPEN-13 accepted (18 plugin files stand).

---

**Status:** APPROVED. Phase 2 review complete, all 29 findings accepted and applied.
**Spec version:** 1.0
**Date:** 2026-09-05
**Review:** `reviews/spec-review-1.md` — 4 blocker, 13 major, 11 minor, 1 question. Every finding was accepted; none was declined. The response is summarised in §19.
**Inputs:** `PROJECTBRIEF.md` v0.4 (amendments 1 to 3), `DECISIONS.md` ADR-001..006 (all accepted), `OPENQUESTIONS.md` (no blocking row open).

> **Specification Gate — PASSED 2026-09-05.** The marker is at the top of this file. Changes from here on are amendments to an approved specification, and each one says so.

> **Amendment 1 — 2026-09-05.** Hashtags built from the post's own tags, added on the owner's instruction after the gate. It introduces **§7.6**, **FR-4.13**, the two `hashtags_*` rows in §3, the hashtag block in §7.2's composition, and tests **T-441** through **T-449**. `PROJECTBRIEF.md` was amended to v0.2 at the same time, which is why §1's scope statement no longer excludes them and why this is no longer a departure from the brief. Recorded as **ADR-005**; two unverified premises about how X renders hashtags are **OQ-20** and §17's **OPEN-14**.

> **Amendment 2 — 2026-09-05.** A manual "Post to X now" for any published post the automatic trigger never sent, added on the owner's instruction after the gate. It introduces **TR-16** in §10.1, **FR-2.6** in §13, tests **T-250** through **T-253** in §16.3, and one sentence in §1. TR-10 gains one side effect at the same time, for the reason given under TR-16. `PROJECTBRIEF.md` was amended to v0.4 (amendment 3, FR-2.6) at the same time, so this is not a departure from the brief. Recorded as **ADR-006** and §17's **OPEN-15**.

---

## 0. How to read this document

1. This is the implementation contract. Where it disagrees with `PROJECTBRIEF.md`, the brief wins unless this document names the disagreement explicitly and gives the evidence. Every such place is collected in §17. (Deliberately not stated as a count: the first draft said "four", §17 listed six, and four more were unlisted. A number here goes stale silently, which is the exact failure the promise exists to prevent.)
2. **MUST** is fixed. **SHOULD** is a default that an ADR may overturn. **OPEN** is an unresolved item and is listed in §17.
3. Every functional requirement in §13 carries at least one named test in §16. A requirement with no test is a defect in this document, not a matter of judgement.
4. Facts labelled **[MEASURED]** came from the live probe run of `bin/verify-x-api.php` on 2026-09-05 at 22:49 UTC. Those are the strongest facts in this document. Facts labelled **[DOC]** came from published documentation with a URL and read date. Facts labelled **[OPEN]** are not yet established.
5. Sizes, limits, and formats in this document are normative. Do not round them in code.

---

## 1. Scope

**Goal.** Publish the title, featured image, and permalink of each newly published WordPress post to one X account, after a configurable delay, with no duplicate posts and no silent failures.

**Not in scope for v1** (from brief §3 as amended, v0.2): other networks; multiple X accounts; OAuth 2.0 PKCE; message templates beyond a prefix and suffix; AI captions, URL shortening, UTM appending; post types other than `post`; analytics or any read endpoint used for engagement; multisite network activation; a Gutenberg sidebar panel.

Hashtags read from the post's own tags were on that list until **amendment 1** and are now in scope; see §7.6 and FR-4.13. Nothing is *generated* — every hashtag is a `post_tag` term the author typed — which is why AI captions remain excluded beside it.

The goal sentence describes the automatic path. Since **amendment 2** the owner can also send any *published* post of an enabled type by hand, from its edit screen, through the same scheduler and pipeline; see TR-16 and FR-2.6. It is a first send for a post the automatic trigger never reached, so INV-1 is unaffected.

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
- **INV-4** No value from a request, from the database, or from any external source is ever concatenated into SQL. Table names come only from `$wpdb` properties (`$wpdb->prefix`, `$wpdb->postmeta`, `$wpdb->posts`). Every value is a `prepare()` placeholder. *(Reworded after review finding 11: the original phrasing — "no interpolated variable ever reaches `$wpdb->query()`" — prohibited `{$wpdb->postmeta}`, which the mandatory compare-and-swap in §11.4 requires, so the security control would have failed the build on day one on the plugin's own required code.)*
- **INV-5** A failure is always recorded. The plugin never swallows an error into silence.
- **INV-6** The featured image never blocks the post. If the image cannot be uploaded, the text-and-URL post still goes out.
- **INV-7** A post is never left in a non-terminal state with nothing scheduled to move it. Every path that writes `scheduled` or `sending` either has a live event, or is reachable by the reconciliation pass in §11.6. *(Added after review findings 3 and 4: both blockers were instances of this, and neither was catchable by any stated rule.)*

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
| `hashtags_enabled` | bool | `false` | Append the post's tags as hashtags. §7.6. |
| `hashtags_max` | int | `3` | `0` to `10`. |

Two further options, both autoload **no**:

| Option | Type | Purpose |
|---|---|---|
| `srl_usage` | array | API request counts. Structure in §12. |
| `srl_cron_last_run` | int | UTC timestamp of the last observed WP-Cron execution. §11.3. |

And one more, autoload **yes**, because it is read on every request:

| Option | Type | Purpose |
|---|---|---|
| `srl_db_version` | int | Schema version of the log table. Compared against `SRL_DB_VERSION` on `plugins_loaded`; see §5.2. Separate from `srl_settings['schema_version']`, which versions the settings array, not the table. |

### 3.1 Delay bounds

The stored pair `(delay_value, delay_unit)` **MUST** resolve to between **0 and 259200 seconds** (72 hours), per FR-1.2. Validation happens on the resolved seconds, not on the raw number, so `delay_value = 4321` with `delay_unit = minutes` is rejected as exceeding 72 hours rather than silently accepted.

**MUST:** the delay is capped at 72 hours and the cap is not merely a UI constraint. Uploaded media expires after 86400 seconds **[MEASURED]**, which is why §8.2 requires the image to be uploaded at send time and not earlier. A delay longer than 24 hours is therefore fine; a *design* that uploaded early would not be.

### 3.2 Sanitization

Every field is sanitized on save and validated before use.

- `enabled`, `email_on_failure` — cast to bool.
- `delay_value` — `absint()`, then range-checked against §3.1.
- `delay_unit` — whitelist match; anything else falls back to `minutes`.
- `prefix`, `suffix` — `sanitize_text_field()`, then rejected if weighted length exceeds 60.
- `hashtags_max` — `absint()`, then rejected above 10. A value of 0 is valid and means the same as the switch being off.
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
| `_srl_sending_since` | int | UTC timestamp written by the claim in §11.4. Without it the staleness rule in §10.2 cannot be implemented, because no other field answers "how long has this been in `sending`?" — `_srl_scheduled_at` is the schedule time, and "not before" semantics mean a post may sit in `scheduled` long past it. *(Review finding 4.)* |
| `_srl_media_id` | string | Media id from a successful upload, reused across retries. |
| `_srl_media_uploaded_at` | int | UTC timestamp of that upload. Media expires after 86400 s **[MEASURED]**, so a retry within the backoff window reuses the id rather than paying for a second upload. *(Review finding 22.)* |

**MUST:** an absent `_srl_status` meta value is equivalent to `none`. Every guard treats "not set" and `'none'` identically. *(Review finding 2 noted the compare-and-swap in §11.4 quietly depended on this without it ever being stated.)*

**MUST:** `_srl_status` is the single source of truth for INV-1. No other value, and no in-memory flag, may be consulted to decide whether a post has already been sent.

---

## 5. Database schema

One custom table, `{$wpdb->prefix}srl_log`. Created on activation and on version upgrade via `dbDelta()`.

```sql
CREATE TABLE {$wpdb->prefix}srl_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  post_title varchar(255) NOT NULL DEFAULT '',
  provider varchar(32) NOT NULL DEFAULT 'x',
  event varchar(32) NOT NULL,
  http_status smallint(5) unsigned DEFAULT NULL,
  remote_id varchar(64) DEFAULT NULL,
  message text DEFAULT NULL,
  scheduled_at datetime DEFAULT NULL,
  sent_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY post_id (post_id),
  KEY created_at (created_at)
) {$charset_collate};
```

`{$charset_collate}` is `$wpdb->get_charset_collate()`. `dbDelta()` requires `require_once ABSPATH . 'wp-admin/includes/upgrade.php'`, which matters because the upgrade check in §5.2 can run on a front-end request where that file is not loaded.

Three columns exist because of review finding 17. A log row must be **self-contained**: reading the title and times from current post meta at render time loses them for a deleted post, and shows a reposted post's *new* sent time against every historical row. `post_title` is a snapshot at write time, not a live lookup.

`event varchar(32)`, not `varchar(20)`: review finding 13 caught that `credentials_unreadable` is 22 characters. On a strict-mode MySQL the insert would have been rejected; on a non-strict server it would have been silently truncated to a value matching no query — so the single audit record of the salt-rotation state was the one row that could not be stored.

Notes that are requirements, not style:

- `dbDelta()` is whitespace-sensitive. Two spaces after `PRIMARY KEY`, one space around each column definition, `KEY` not `INDEX`. Getting this wrong makes `dbDelta` recreate the table on every upgrade check.
- `created_at` stores **UTC**, written with `gmdate( 'Y-m-d H:i:s' )`. It is never `current_time()`, which returns site-local time.
- `event` is one of: `scheduled`, `sent`, `failed`, `cancelled`, `retry`, `test`, `credentials_unreadable`.
- `message` is truncated to **2048 bytes** before insert, on a UTF-8 character boundary so the column never holds a split multi-byte sequence.
- `post_id` is `0` for rows that are not about a post — the connectivity test (FR-1.5) and credential-state events.

### 5.2 Schema versioning

WordPress does **not** fire the activation hook when a plugin is updated in place, so "created on activation and on version upgrade" needs an explicit trigger. *(Review finding 19.)*

On `plugins_loaded`, the plugin compares the `srl_db_version` option with the `SRL_DB_VERSION` constant. On a mismatch it loads `wp-admin/includes/upgrade.php`, runs `dbDelta()` with the current schema, and writes the new version. On a fresh install the option is absent and the same path runs.

`SRL_DB_VERSION` is an integer, incremented whenever the DDL above changes. It is independent of the plugin version and of `srl_settings['schema_version']`, which versions the settings array.

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

The fingerprint is a hash **of the derived key**, never of the plaintext secret and never of the salt.

It reveals nothing about the credential. It is a 32-bit check value on the derived key, which does let an attacker holding the database test a guessed salt cheaply, and does link two database backups that share a key. Since `wp_salt()` is high-entropy this is not a practical weakening, and it buys an accurate diagnosis of salt rotation — the trade ADR-003 accepts. *(The first draft claimed it revealed "nothing usable about the key", which review finding 28 correctly called overstated. An absolute claim inside a design whose selling point is honesty about its limits was worth one sentence to fix.)*

To avoid it being a bare hash of the key, the fingerprint uses domain separation: `sodium_crypto_generichash( $key, 'srl-fingerprint', 32 )`, truncated to 4 bytes.

### 6.3 Decryption and the `credentials_unreadable` state

On every read:

1. If the value does not start with `srl1:`, treat it as **absent**. Do not attempt a legacy plaintext read.
2. Recompute the fingerprint from the current derived key. If it does not match the stored fingerprint, **do not attempt decryption**. Enter `credentials_unreadable`.
3. If `sodium_crypto_secretbox_open()` returns `false` despite a matching fingerprint, the ciphertext is corrupt or tampered. Enter `credentials_unreadable`.

In `credentials_unreadable` the plugin **MUST**:

- show a persistent admin notice, and the same message on the settings page, stating that the site's security salts appear to have changed and that re-entering the four keys will fix it;
- **refuse to schedule new sends**, rather than scheduling sends guaranteed to fail hours later;
- write one `credentials_unreadable` log row, not one per page load;
- leave already-scheduled events in place. They will fail at send time and be recorded normally. Cancelling them would destroy information the owner needs;
- **mark each affected post.** A post whose publish transition is refused for this reason gets `_srl_status = failed` and `_srl_last_error = 'credentials_unreadable'`, and a `failed` log row. FR-2.3 renders a distinct status line naming the salt problem and linking to the settings page. *(Review finding 24: without this the post's status line reads "Not scheduled", identical to the master switch being off, and the one global log row is written once rather than per post — so a post skipped during the outage left no trace anywhere. The owner will look at the post, not the settings page, when they wonder a week later why three articles never went out.)*

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
- **Weight 1** for a Unicode code point in `U+0000–U+10FF`, `U+2000–U+200D`, `U+2010–U+201F`, or `U+2032–U+2037`.
- **Weight 2** for every other code point.
- **Every URL weighs 23**, whatever its real length — not only the permalink. See §7.1.1.
- **`emojiParsingEnabled` is true**, so an emoji *sequence* counts once. A ZWJ family emoji is seven code points and weighs **2**, not 14. Counting its code points individually over-counts by a factor of six and truncates titles that would have fit.

**The numeric ranges above are normative. Script names are not.** The first draft glossed weight 2 as covering "CJK, Hangul, Thai, Devanagari, and emoji". Thai is U+0E00–U+0E7F and Devanagari is U+0900–U+097F — both sit inside the weight-1 range `0–4351` printed two lines above. An implementer coding the gloss rather than the numbers would have halved the usable title length for Thai and Hindi sites, and written a test asserting the wrong answer. *(Review finding 10.)* CJK, Hangul and emoji are correctly weight 2.

#### 7.1.1 Every URL, not just the permalink

`twitter-text` extracts **every** URL in the text and replaces each with 23, and its matcher fires on bare hostnames with a valid TLD, not only on `http(s)://` prefixes. The first draft weighted only the appended permalink as a URL and counted the title character by character, which under-counts:

| Title contains | Plugin counted | X counts | Error |
|---|---|---|---|
| `WordPress.com` | 13 | 23 | **−10** |
| `Why Notion.so beats Trello.com` | literal | +2 × 23 | **−20** |
| a 90-character URL | 90 | 23 | +67 (harmless) |

Under-counting is the fatal direction: the text passes the plugin's own ≤ 280 check and X returns HTTP 400. *(Review finding 8.)*

**Specified:** `weighted_length()` first splits the **whole composed text** into URL and non-URL segments, then weighs each segment.

- A scheme-qualified URL (`https?://…`) weighs exactly **23**. X definitely shortens these.
- A bare `host.tld[/path]` candidate weighs **`max( 23, its literal weighted length )`**. If X linkifies it the cost is 23; if not, the cost is literal. Taking the larger can only truncate early, never overflow.
- The TLD must be at least two letters, and the candidate must not be preceded by `@` or a word character. This keeps `i.e.` and `e.g.` out, which would otherwise each be charged 23.
- Everything else is weighed code point by code point per §7.1.

**MUST:** §16.6 asserts the counter against `twitter-text`'s own published weighted-length fixtures, not only against the plugin's own function. A test that measures the counter with the counter cannot catch a counting error.

### 7.2 The composition

```
{prefix} {title} {suffix} {hashtags}\n{permalink}
```

- A single space joins each part to the next. If any part is empty, its space is omitted too; the text never begins or ends with a stray space, and never contains a double space from an absent part.
- The hashtag block is a single space-separated run, placed after the suffix and before the newline. It is built per §7.6 and is empty unless `hashtags_enabled` is on.
- Exactly one `\n` separates the text block from the permalink. It weighs 1.
- The permalink is the **last** element and is never modified, never shortened, and never truncated.

### 7.3 The budget

```
budget_for_text = 280 - 23 (permalink) - 1 (newline) = 256
```

The prefix and suffix are capped at 60 weighted characters each (§3). With both at maximum and both joining spaces present, the worst case leaves `256 - 60 - 60 - 2 = 134` weighted characters for the title. The title is therefore always allotted at least 134.

The hashtag block does **not** reduce that floor, because §7.6 drops hashtags before the title loses anything. The 134 guarantee survives the feature unchanged.

### 7.4 Truncation

**MUST:** only the title is ever truncated. Never the URL, never the prefix, never the suffix.

1. Compute the weighted length of the full composition.
2. If it is ≤ 280, use it unchanged.
3. Otherwise compute `title_budget = 256 - weighted(prefix) - weighted(suffix) - (joining spaces)`, then reduce further by 1 to reserve room for the ellipsis.
4. **NFC-normalize** the text. Then walk **code points**, applying the §7.1 ranges, except that a substring matching the emoji pattern counts as one unit of weight 2. Stop at the last position that fits. When cutting, never split a grapheme cluster, an emoji sequence, or a multi-byte character.

   *The first draft said "walk by grapheme cluster" throughout, which is what `twitter-text` does not do. Grapheme clusters and emoji sequences are different sets, and the difference under-counts: `a` followed by five combining marks is one cluster, so the plugin charged 1 where X charges 6 — an under-count of 5 per occurrence, and therefore an HTTP 400. The same mechanism hits Vietnamese, Thai with tone marks, and Indic conjuncts at smaller magnitudes. Normalization is the step that makes the code-point rule deterministic, and the first draft never mentioned Unicode normalization at all. (Review finding 9.) The cluster rule was introduced to fix the ZWJ over-count and it does fix that; the fix belongs in emoji detection and in the cut position, not in the counting walk.*

   Emoji detection is pinned rather than left to a Unicode property, because `\p{Extended_Pictographic}` is not compiled into every PCRE2 build (it is absent from PCRE2 10.39, which ships with Ubuntu 22.04). A multi-code-point cluster is treated as a single weight-2 emoji when it contains any of: U+200D (ZWJ), U+FE0F (VS16), a regional indicator U+1F1E6–U+1F1FF, a skin-tone modifier U+1F3FB–U+1F3FF, or U+20E3 (keycap). A single code point needs no special case: every emoji code point already falls outside the weight-1 ranges and so already weighs 2.
5. Prefer a word boundary: if a space exists within the final 12 weighted characters of the truncation point, cut there instead, so the result does not end mid-word.
6. Append `…` (U+2026). **It weighs 2, not 1** — U+2026 sits in the gap between the weight-one ranges, which end at 8223 and resume at 8242, so it takes the default weight of 200. Reserve the ellipsis by *measuring* it, never by assuming; hard-coding a reserve of 1 overflows the limit by exactly one unit on every truncated post, which is a 100% failure rate on the longest titles. Three periods would weigh 3, so the single code point is still the right choice.

**MUST:** the algorithm is conservative. If a code point's weight is uncertain, treat it as 2. Truncating one character early is invisible. Overflowing produces an HTTP 400 from X and a `failed` post, which is the failure this whole section exists to avoid.

### 7.5 Read at send time

Per FR-4.3, the title and permalink are read **at send time**, not at schedule time. The owner may have corrected a typo during the delay, and the shipped post must reflect the correction.

---

## 7.6 Hashtags from post tags

Implements FR-4.13. Off by default; the block is empty unless `hashtags_enabled` is on.

The post's `post_tag` terms are read **at send time**, for the same reason §7.5 reads the title then: a tag corrected during the delay must reach X.

### 7.6.1 One tag to one hashtag

X ends a hashtag at the first character outside `[letter, digit, underscore]`. This makes the conversion a repair, not a reformatting: shipping the tag `co-op` with its spaces merely stripped produces the hashtag `#co`, and `rock 'n' roll` produces `#rock`. Both are wrong hashtags rather than ugly ones, and both would ship silently.

**Specified:**

1. NFC-normalize and trim the term name.
2. Split on every run of characters that is not a Unicode letter, digit, mark, or underscore.
3. For each word: if it contains **no** upper-case letter, upper-case its first character. Otherwise leave it exactly as the author typed it.
4. Concatenate the words with no separator, and prepend `#`.
5. Produce **nothing** if the result is empty or consists only of digits and underscores.

| Term name | Result | Why |
|---|---|---|
| `machine learning` | `#MachineLearning` | PascalCase join |
| `iPhone SE` | `#iPhoneSE` | rule 3's exception; unconditional capitalisation gives the wrong `#IphoneSe` |
| `co-op` | `#CoOp` | punctuation removed, not left to truncate the hashtag |
| `rock 'n' roll` | `#RockNRoll` | as above |
| `Web 2.0` | `#Web20` | digits are kept; the period is not |
| `café society` | `#CaféSociety` | X accepts non-ASCII letters |
| `2026` | *(none)* | rule 5 |

**Why PascalCase rather than lower-case or underscores.** Three reasons, in order of weight. It survives punctuation, which lower-case-and-strip does not. A screen reader segments `#MachineLearning` into two words and reads `#machinelearning` as one unpronounceable run, so this is an accessibility difference rather than a stylistic one. And it is the convention on X, so the output reads as deliberate. Underscores are legal in an X hashtag but are unconventional and cost a weighted character each.

**[DOC], confirmed 2026-09-05.** Both behavioural claims above are stated by X. Its Help Center article *Help with hashtags and replies* says **"punctuation marks (commas, periods, semicolons, apostrophes, question marks, exclamation marks, etc.) will end your hashtag wherever punctuation occurs"**, and gives `#it'sfun` → `#it` as the worked example — the same failure as `co-op` → `#co`, which is what step 2 above exists to prevent. The same article says **"if you write #1 or #123 the hashtag will not be hyperlinked and is therefore not searchable"** and that `#123go` **"will work correctly"**, which confirms both halves of rule 5. <https://help.x.com/en/using-x/replies-not-showing-up-and-hashtag-problems>.

*Not [MEASURED].* `help.x.com` returns HTTP 403 to automated fetch, so this is the article's wording as quoted consistently by two independent searches, not a page read first-hand, and no live post was ever inspected. Neither claim is load-bearing for INV-1 or the 280 limit — if either were wrong the cost is a hashtag that reads differently, never a failed post. `OPENQUESTIONS.md` **OQ-20**, now closed.

### 7.6.2 The list

Term names are converted in the order the taxonomy returns them, empties are discarded, duplicates are collapsed **case-insensitively** (X treats `#WordPress` and `#wordpress` as one hashtag, and two distinct terms can normalise onto one), and the first `hashtags_max` survivors are kept.

### 7.6.3 The budget

Two caps, in this order.

1. **The block cap.** The hashtag block may not exceed **60 weighted characters**, the same cap the prefix and suffix carry and for the same reason: it is fixed text charged against the title's budget. Hashtags are added in order until the next one would breach the cap, and the walk then **stops** rather than skipping ahead, so the block is always a prefix of the tag order and never silently reorders what the owner sees.
2. **The fit.** Hashtags are then dropped **whole, from the end**, until the title fits without truncation. A hashtag cut in half is a different hashtag, not a shorter one, so the block is never truncated the way a title is. And a tag is worth less than the words the author wrote, so the block goes first.

**MUST:** a title long enough to truncate on its own sheds every hashtag before §7.4 runs, and the resulting text is then byte-identical to what the same title produced before this feature existed. §16.6 asserts that equality directly.

---

## 8. API contract

Host is `api.x.com` for every call (INV-3). Transport is `wp_remote_post()` / `wp_remote_get()`, never cURL directly, so that `pre_http_request` can intercept every call in tests.

All examples below are **[MEASURED]** — real requests and real responses captured on 2026-09-05 at 22:49 UTC, API version `2.168`. Identifying values are replaced with placeholders; structure and field names are verbatim.

### 8.1 Authentication

OAuth 1.0a HMAC-SHA1, user context, per ADR-001. Every request carries an `Authorization: OAuth ...` header built as specified in RFC 5849.

**MUST — the rule that breaks signers:** the request body is included in the signature base string **only** when the body is `application/x-www-form-urlencoded`. This plugin sends JSON and multipart bodies exclusively, so **no body parameter is ever signed**. Only the `oauth_*` parameters, plus any query-string parameters, enter the base string.

**MUST:** `multipart/form-data` is **not** `application/x-www-form-urlencoded`. Its parts are never signed. The phrasing above invites the opposite conclusion, so it is stated outright. *(Review finding 15.)*

**The test vector, pinned here rather than referenced.** OQ-12 and brief §5 both require it, and the first draft only pointed at it. From RFC 5849 §3.4.1.1, with the duplicate `a3` parameter removed because an associative array cannot express a duplicate key and this plugin never sends one:

```
POST&http%3A%2F%2Fexample.com%2Frequest&a2%3Dr%2520b%26a3%3D2%2520q%26b5%3D%253D%2525
3D%26c%2540%3D%26c2%3D%26oauth_consumer_key%3D9djdj82h48djs9d2%26oauth_nonce%3D7d8f3e4
a%26oauth_signature_method%3DHMAC-SHA1%26oauth_timestamp%3D137131201%26oauth_token%3Dk
kk9d7dh3k39sjv7
```

(Line-wrapped for the page; the value is a single unbroken string.) X's own published signing example could not be located in 2026 documentation, which is why the RFC vector is used — brief §5 names it as the fallback.

Every media response carried `x-access-level: read-write` **[MEASURED]**. An App set to read-only will fail here, not at post time.

### 8.2 Upload the image — `POST /2/media/upload`

One-shot upload, per ADR-002 as amended. **MUST NOT** use the three-step `initialize` / `append` / `finalize` flow in v1; both work **[MEASURED]**, and §0 requires the design with fewer moving parts.

**Request** — `multipart/form-data`:

| Field | Value |
|---|---|
| `media_category` | `tweet_image` |
| `media` | the image bytes, with a filename and a correct `Content-Type` |

**MUST — how the request is actually sent.** WordPress's HTTP API has **no** multipart file support. Passing an array as `body` to `wp_remote_post()` serializes it with `http_build_query()` and sets `Content-Type: application/x-www-form-urlencoded`, which would send mangled binary in a form-encoded body — rejected by X, and under §8.1's rule that body would then have to be *signed*, so the request would also fail to authenticate. *(Review finding 15: this is the one endpoint actually proven to work, and the transport detail that makes it work was unstated.)*

Therefore: the multipart body is assembled **by hand** as a raw string with an explicitly generated boundary; `headers['Content-Type']` is set to `multipart/form-data; boundary=…`; and the body is passed to `wp_remote_post()` as a **string**, never an array.

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

1. **MIME allowlist.** Accept `image/jpeg`, `image/png`, `image/webp` only. Anything else — including SVG, which many sites enable, and animated GIF, which needs a different `media_category` — is skipped with `_srl_image_omitted_reason = 'unsupported_type'`. GIF support is a deliberate v1 omission, not an oversight. *(Review finding 23: `media_category=tweet_image` was hard-coded for whatever the attachment happened to be.)*
2. **Prefer the `large` intermediate, not the original.** Resolve via `wp_get_attachment_image_src( $id, 'large' )` and take its file, falling back to `get_attached_file()`. The original upload on a camera-sourced site is routinely 6–12 MB, which would make the downscale path the *normal* path; WordPress has already generated an intermediate under the limit. **MUST NOT** fetch over HTTP; the site may be behind basic auth, a staging password, or a CDN.
3. If the file is missing or unreadable, skip the image and set `_srl_image_omitted`.
4. **Bounded downscale.** If the file exceeds **5 MB** **[DOC]**, resize with `wp_get_image_editor()` to a maximum dimension of 2048 px, write to a temporary file, and re-check `filesize()`. If it still exceeds the limit, resize once more to 1200 px and re-check. Give up after two passes with `_srl_image_omitted_reason = 'too_large'`. *(The first draft said "downscale" with no target, no iteration and no re-check. `resize()` takes dimensions, not a byte budget, and `set_quality()` only affects JPEG, so a 6 MB PNG resized to 1200 px may still exceed 5 MB.)*
5. Delete the temporary file after the request, on success and on failure alike.
6. If `wp_get_image_editor()` is unavailable, or downscaling fails, skip the image and set `_srl_image_omitted`.

**MUST:** these all degrade to "post without the image" per INV-6 — but ADR-002 exists because the owner asked for the featured image. Silently omitting it on most posts satisfies the letter of INV-6 and defeats its purpose, which is why steps 1, 2 and 4 are specified rather than left to the implementer.

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

Applies to the `POST /2/tweets` call. The media call has **no retry tier of its own** — see §9.5. `attempts` is `_srl_attempts` **after** the current attempt is counted.

**One initial attempt plus up to three retries — four attempts in total.** *(Review finding 5: the first draft gated retries on `attempts < 3`, which yields 3 attempts and 2 retries and makes the 60-minute backoff unreachable, while §9.1, FR-4.8, T-451 and T-452 all encode the other reading. Brief FR-4.8 says "maximum 3 retries, then failed", so the tests and the brief agreed with each other and the matrix was the outlier.)*

| Condition | Retry? | Resulting status | Notes |
|---|---|---|---|
| HTTP 200 / 201, body parses, `data.id` present | — | `sent` | Store `_srl_remote_id`, `_srl_sent_at`. |
| HTTP 429 | yes, if `attempts <= 3` | `scheduled`, else `failed` | Backoff §9.1. Honour `x-rate-limit-reset` if it is further out than the backoff. |
| HTTP 500–599 | yes, if `attempts <= 3` | `scheduled`, else `failed` | Backoff §9.1. |
| Transport error or timeout (`WP_Error`) | yes, if `attempts <= 3` | `scheduled`, else `failed` | **Not in the brief; specified here.** See §9.2. |
| HTTP 401 | **no** | `failed` | Credentials or App permissions. Admin notice per FR-4.9. Log the body. |
| HTTP 403 | **no** | `failed` | As 401. Commonly a read-only App. |
| HTTP 400 with a duplicate-content error | **no** | `failed`, reason `duplicate` | FR-4.11. See §9.3. |
| Any other HTTP 4xx | **no** | `failed` | Log the body. FR-4.10. |
| HTTP 2xx, body does not parse as JSON, or `data.id` is absent | **no** | `failed`, reason `malformed_response` | **Deliberately not retried.** See §9.4. |

### 9.5 The media call has no retry tier

**Any** non-2xx status or transport error on `POST /2/media/upload` immediately sets `_srl_image_omitted` with the status or error as the reason, and proceeds to `POST /2/tweets`. Media outcomes never touch `_srl_attempts` and never change `_srl_status`.

*(Review finding 6. The first draft said the media call "uses the same matrix with one difference". The matrix's non-terminal outcomes reschedule the whole send, so a 429 on the media endpoint meant either "delay the tweet by 5 minutes" or "omit the image and continue" — the document supported both and chose neither. Worse, `_srl_attempts` is one counter shared by both calls, so three media hiccups could exhaust the budget without `POST /2/tweets` ever being called, and the post would fail with an error about the image. That is the exact opposite of INV-6.)*

### 9.1 Backoff

Retry delays are **5 minutes, 15 minutes, 60 minutes**, in that order, following failed attempts 1, 2 and 3. The fourth failure is terminal. A retry is scheduled with `wp_schedule_single_event()` exactly as the original send was — **and its return value is checked**, per §11.6 — and writes a `retry` log row.

**A retry reuses an already-uploaded image.** If `_srl_media_id` is set and `_srl_media_uploaded_at` is less than 86400 seconds old **[MEASURED]**, the retry attaches the stored id instead of uploading again. Re-uploading would pay for the same image up to four times and would skew the media-to-post ratio in §12's counter — the one number the owner uses to decide whether the plugin's accounting can be trusted. *(Review finding 22.)*

Because the delay is a WP-Cron schedule, these are also "not before" times (§11.1). A 5-minute backoff on a site with a one-minute system cron fires at 5 to 6 minutes.

### 9.2 Transport errors — a gap in the brief

Brief §4 FR-4.8 through FR-4.11 enumerate HTTP status codes and say nothing about a request that never produced one: a DNS failure, a TLS failure, a connection reset, or a timeout. `wp_remote_post()` returns a `WP_Error` in all of these, and code that only inspects `wp_remote_retrieve_response_code()` reads that as `0` and falls through to whichever branch is last.

**Specified:** a `WP_Error` is treated as a retryable failure, on the same backoff as a 5xx. The `WP_Error` code and message are logged with `http_status` left `NULL`, which is what distinguishes a transport failure from an HTTP failure in the log.

This is a specification decision filling a genuine gap, not a reinterpretation. It is listed in §17 as OPEN-1 so the reviewer sees it rather than absorbing it.

### 9.3 Duplicate content, and why it is a safety net

X rejects a post whose text matches one posted recently. FR-4.11 treats this as terminal, which is right.

It is also the backstop that makes retrying safe. A 5xx or a timeout may mean the post was actually created and only the response was lost. Retrying such a request risks a second post, which would break INV-1. X's duplicate rejection catches exactly that case.

**An unverified dependency, stated rather than buried.** This argument rests on X's duplicate-detection window, whose duration is neither documented by X nor measured by the Phase 0 probe. Once the 60-minute backoff becomes reachable (§9.1), it is a long time to assume a duplicate check still applies; if the window is shorter than the backoff, a retry after a lost response produces a second post — an INV-1 violation arriving as a success rather than an error. This is the only place where the top invariant depends on external behaviour rather than on the plugin's own state. *(Review finding 26.)* **Phase 6 acceptance measures it**: post, delete, and repost identical text at 5, 30 and 90 minutes, then pin the result here. If the window proves shorter than 60 minutes, the backoff is capped at the measured window rather than at an invented number. Tracked as OPEN-11.

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
none / cancelled ──"Post to X now" + confirm, post is publish──▶ scheduled (delay 0)
scheduled ──"Cancel scheduled post"──▶ cancelled
```

### 10.1 Transition table

Every row has at least one test in §16.

| # | From | Trigger | To | Side effects |
|---|---|---|---|---|
| TR-1 | `none`, `cancelled`, `failed` | `transition_post_status` to `publish`, from a status that is not `publish`, passing every guard in §10.3 | `scheduled` | Write `_srl_scheduled_at`, schedule one event (checking its return, §11.6), log `scheduled`. |
| TR-2 | any | Same transition, but a guard in §10.3 rejects it | unchanged | Nothing scheduled. A skip with a cost implication is logged; an ordinary "switch is off" is not. |
| TR-3 | `scheduled` | Post leaves `publish`, is trashed, or is deleted | `cancelled` | Clear the scheduled event, log `cancelled`. |
| TR-4 | `scheduled` | Owner clicks "Cancel scheduled post" | `cancelled` | Clear the event, log `cancelled`. |
| TR-5 | `scheduled` | Cron event fires, post still `publish` | `sending` | Compare-and-swap per §11.4. |
| TR-6 | `scheduled` | Cron event fires, post no longer `publish` | `cancelled` | Log `cancelled`. No API call. |
| TR-7 | `sending` | HTTP 2xx with a parseable id | `sent` | Store `_srl_remote_id`, `_srl_sent_at`, log `sent`. |
| TR-8 | `sending` | Retryable failure, `attempts < 3` | `scheduled` | Increment `_srl_attempts`, schedule backoff, log `retry`. |
| TR-9 | `sending` | Terminal failure, or `attempts = 3` | `failed` | Store `_srl_last_error`, log `failed`, raise notice. |
| TR-10 | `sent` or `failed` | "Repost now", confirmed | `scheduled` | Reset `_srl_attempts` to 0, set `_srl_enabled` to `1` (amendment 2; see TR-16), schedule at delay 0, log `scheduled`. |
| TR-11 | any | A second cron event fires for a post not in `scheduled` | unchanged | Exit without an API call. INV-1. Log nothing. |
| TR-12 | `sending` | `_srl_sending_since` is more than 15 minutes old, observed by the scan in §11.7 | `failed` | `_srl_last_error = 'stalled'`, log `failed`, raise notice. **No automatic retry.** |
| TR-13 | `sent` | A publish transition fires again (unpublish-republish, private-republish, untrash) | `sent` | **No-op.** Nothing scheduled. The meta box explains why, and offers "Repost now" as the only path. |
| TR-14 | `sending` | Post is trashed or unpublished while a send is in flight | `sending` → resolved by TR-7/TR-9 | Mark the intent; **do not** clear the in-flight attempt and do not attempt to unsend. The send completes or fails on its own, and the result is recorded. |
| TR-15 | `scheduled` | `wp_schedule_single_event()` returned `false`, or the event is later found missing | `failed` | `_srl_last_error = 'schedule_failed'` or `'event_lost'`, log `failed`, raise notice. §11.6. |
| TR-16 | `none` (or absent), `cancelled`, or `scheduled` | "Post to X now", confirmed, and the post's status is `publish` | `scheduled` | Set `_srl_enabled` to `1`; clear any pending event; reset `_srl_attempts` and clear `_srl_last_error`, `_srl_sending_since` and the stored media id; write `_srl_scheduled_at = now`; schedule at delay 0 (checking the return, §11.6); log `scheduled` with a message naming the owner. **Never** from `sent`, `sending` or `failed`: the first two are INV-1, and `failed` already has "Repost now". Added by amendment 2. |

**TR-16 notes.** This is the owner's path for a post the automatic trigger never reached: one published before the plugin existed, one skipped by G-6, G-7 or G-8, one published with a switch off, or one cancelled by hand. None of §10.3's guards applies except G-3, which holds because the meta box is registered only for enabled types, and G-1's requirement that the post be published, checked directly. G-4 and G-6 to G-8 exist to detect the *absence* of intent; a confirmed click on one post is that intent. G-9 is not checked either: the failure it predicts surfaces within a minute at delay 0, recorded as `credentials_unreadable` by the publisher, and the meta box already explains that state.

Three details are load-bearing:

1. **`_srl_enabled` is set to `1`.** The button submits the whole meta box form, and `save()` runs first (priority 10, §11.5). If the checkbox was unticked — the default on a site whose master switch is off — `save()` has just stored `0`, and §11.5 part 3 would cancel the send at the next cron run with "Per-post switch was turned off". A confirmed click outranks a checkbox, so the handler overwrites it. TR-10 gains the same side effect because it had the same trap.
2. **`scheduled` is an accepted origin, and the pending event is cleared first.** The button never renders in `scheduled`, but `save()`'s reconcile step (§11.5) can schedule a fresh post at the *default* delay in the same request that carries the click — a post skipped by G-6 the day before, say. Without this the click would be swallowed by G-5 and the owner who asked for "now" would get "in an hour". Clearing first also keeps the count of events at one and stays clear of the ten-minute duplicate suppression in §11.6.
3. **`sent` is refused even though "Repost now" would accept it.** The two buttons partition the states so that a stale form — rendered when the post was `none`, submitted after another request sent it — cannot produce a second paid post without the confirmation FR-2.5 requires.

### 10.3 Scheduling guards

Every one of these must pass before TR-1 fires. The first draft had only the first four, which is what made review findings 2 and 7 possible.

| # | Guard | Why |
|---|---|---|
| G-1 | `$new_status === 'publish' && $old_status !== 'publish'` | Brief §1.5. Excludes edits to published posts. |
| G-2 | Not an autosave or a revision | Revisions and autosaves carry status `inherit`, so G-1 already excludes them; this is belt and braces. |
| G-3 | Post type is in `srl_post_types` | Brief §3. Ships as `post` only. |
| G-4 | Master switch on, and per-post switch on (read per §11.5) | FR-1.3, FR-2.1. |
| G-5 | **`_srl_status` is `none`, absent, `cancelled`, or `failed`** | **INV-1.** Never when it is `sent`, `sending`, or `scheduled`. |
| G-6 | **Not `defined( 'WP_IMPORTING' ) && WP_IMPORTING`** | An importer must not spend the owner's money. |
| G-7 | **Not a bulk edit** (`isset( $_REQUEST['bulk_edit'] )`) without deliberate opt-in | Bulk-publishing 40 drafts fires 40 transitions in one request, and the meta box is not rendered in bulk edit. |
| G-8 | **`post_date_gmt` is within the freshness window** (default 24 hours) | "Newly published" in brief §1.5 means new, not merely newly-transitioned. |
| G-9 | Credentials are readable (§6.3) | Otherwise the send is certain to fail hours later. |

**Why G-5 exists (review finding 2, blocker).** The only stated guard was G-1 plus the switches. Nothing consulted `_srl_status`, and TR-1's "From: `none`" was a description, not a rule. Every one of these is ordinary editorial behaviour and every one produced a duplicate paid post:

- publish → draft (`cancelled`) → publish again.
- publish → trash → untrash. `wp_untrash_post()` restores to draft by default and to `publish` when the `wp_untrash_post_set_previous_status` filter is used, which many sites and plugins do.
- Already `sent`, then publish → private → publish, or publish → future → publish when an editor reschedules a live post. **The post goes to X a second time**, without the confirmation click FR-2.5 makes the sole path to a second post.
- A post duplicated by a clone plugin inherits `_srl_status = sent` and `_srl_remote_id`, so the meta box shows "Sent" linking to the *original's* X post.

X's duplicate rejection does **not** save these cases, because the title is usually corrected in between, so the text differs.

**Why G-6, G-7 and G-8 exist (review finding 7, major).** `transition_post_status` fires identically for bulk edit, Quick Edit, `wp_insert_post()` from an importer or migration or WP-CLI or the REST API. A 500-post migration into a site with the plugin enabled schedules 500 sends of posts dated years ago — **$100 at the URL rate**, plus a flood of near-simultaneous events that collide with X's rate limits and cascade into 429 retries. This is the only failure mode in the document that costs real money with no human intending a post.

**MUST:** a skip under G-6, G-7 or G-8 writes a `cancelled` log row with the guard name as the reason. A skip under G-4 does not, because "the switch is off" is the normal state of a disabled plugin and would fill the log. The distinction is that G-6 to G-8 skips are *surprising* and the owner must be able to find out why nothing posted.

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

**MUST:** the argument array is **always** `array( (int) $post_id )`, at every schedule site and every clear site, without exception.

WordPress matches events by `md5( serialize( $args ) )`, which is **type-sensitive**: `array( 123 )` and `array( '123' )` hash differently. Scheduling takes the id from `$post->ID` (always an int); clearing takes it from `$_POST` or `$_GET` in the cancel handler, or from a hook argument that has often passed through `sanitize_text_field()` on the way, yielding a string. A single missing cast leaves a live event behind while the meta says `cancelled`, and that stale event then silently swallows the next schedule through WordPress's 10-minute duplicate window (§11.6). *(Review finding 16: the first draft warned about the argument array's shape and stopped one level above the mechanism that actually bites.)*

**MUST:** all scheduling and clearing goes through one pair of helper methods that perform the cast, so no call site can get it wrong independently. T-323 schedules with an int and attempts to clear with the string form, asserting the helper normalizes before calling WordPress.

### 11.3 Cron health

A recurring event `srl_heartbeat` runs on a custom one-minute schedule, registered through `cron_schedules`. Its handler does one thing: `update_option( 'srl_cron_last_run', time(), false )`.

**Why a heartbeat rather than recording the time of the incoming request:** the health panel must answer "is WP-Cron executing?", not "did something request `wp-cron.php`?". Those differ precisely in the case that matters. If a caching layer serves `wp-cron.php` from cache, requests succeed, nothing executes, and a request-time metric would show green while every scheduled post silently stalls. A heartbeat can only be written by code that actually ran.

**The observer effect, and why `DISABLE_WP_CRON` is part of the reading.** On a site that has *not* set `DISABLE_WP_CRON` — precisely the misconfigured population this panel exists to detect — loading any wp-admin page calls `wp_cron()`, which spawns a loopback request that runs due events including `srl_heartbeat`. The owner opens the settings page, sees a warning, refreshes, and sees green, because their own page load caused the heartbeat. Their delayed posts still stall for hours between visitors, which is the actual condition, and the panel now denies it. A metric a refresh can turn green teaches the owner to distrust it. *(Review finding 20.)*

Panel states — three, not two:

| Condition | Display |
|---|---|
| `DISABLE_WP_CRON` is true **and** the heartbeat is within the threshold | **Green.** Real cron is running. Show the timestamp. |
| `DISABLE_WP_CRON` is false, whatever the heartbeat says | **Unverified.** "WP-Cron is visitor-triggered, so delays will be approximate." Link to `INSTALLATION.md` step 7. Never green, because the freshness reading cannot be trusted. |
| `DISABLE_WP_CRON` is true and the heartbeat is stale or never set | **Warning.** Real cron is configured but is not running. |

**Stated cost.** The heartbeat is roughly 1,440 option writes per day, forever, on whatever host the site runs. That is defensible for a diagnostic that cannot otherwise be obtained, but it is a real cost and is recorded here rather than discovered later.

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

The result has **three** meanings, not two. The first draft collapsed them into "exit, log nothing", which turned a transient database error into a permanently stuck post with no evidence anywhere. *(Review finding 18.)*

| Result | Meaning | Action |
|---|---|---|
| `1` | This process owns the send. | Write `_srl_sending_since = time()`. Proceed. |
| `0` | Either another worker claimed it first, or the status was not `scheduled`. | Re-read with `get_post_meta()`. If it is now `sending`, another worker has it: exit silently. Otherwise log the observed status and exit. |
| `false` | **The query errored** — deadlock, lock timeout, lost connection. `false === 1` is false, so the first draft abandoned the send with no log row, no status change and no retry, leaving the post in `scheduled` forever. | Log a `failed` row carrying `$wpdb->last_error`, and **leave the status as `scheduled`** so the reconciliation pass in §11.6 picks it up. |
| `>= 2` | Duplicate `_srl_status` meta rows, possible after a plugin-driven duplication or an import. | Log and abort. This is a data defect the owner must see, not something to paper over. |

**MUST:** the check is `1 === $claimed`, using a strict comparison. `$claimed == 1` is true for `true` and for `'1'`, and loose comparison is what made the `false` case invisible in the first place.

After a successful claim, `wp_cache_delete( $post_id, 'post_meta' )` clears the object cache, which the direct UPDATE bypassed. Omitting this leaves stale meta in a persistent object cache for the rest of the request.

This is the one place where a direct `$wpdb->query()` is correct rather than a violation of INV-4 — and it is still fully prepared, so INV-4 holds as written.

### 11.5 The meta box save path

**This is where the first draft was most wrong.** *(Review finding 1, blocker.)*

In `wp_insert_post()`, `wp_transition_post_status()` runs **before** `do_action( 'save_post' )`. Meta boxes save on `save_post`. So at the moment the scheduler runs, `_srl_enabled` and `_srl_delay_override` still hold the values from before this request, or do not exist at all for a post being published for the first time. Unchecking "Post to X" and pressing Publish in the same request therefore did **not** prevent scheduling, and a delay override typed in the same request was ignored.

In the block editor the gap is wider. The publish transition happens inside the REST request to `/wp/v2/posts/{id}`, while a classic meta box's fields are submitted afterwards in a *separate* `post.php?meta-box-loader=1` request. Brief §3 rules out a Gutenberg sidebar panel because "a classic meta box… works in both editors" — true for display, false for this ordering.

The consequence: the owner unchecks the box, publishes, and the post goes to X anyway at $0.20. This is the one control the brief gives the author to prevent an unwanted paid post.

**Specified, in three parts:**

1. **Save.** The meta box fields are saved on `save_post`, with nonce verification and an `edit_post` capability check for that specific post id.
2. **Read-through at schedule time.** The `transition_post_status` handler, when the meta box nonce is present and valid in `$_POST`, reads `_srl_enabled` and `_srl_delay_override` **from `$_POST`** rather than from stored meta. When the nonce is absent — a REST publish, WP-CLI, a bulk edit — it falls back to stored meta.
3. **Re-check at send time.** The publisher re-reads `_srl_enabled` immediately before sending and cancels (TR-3) if it is now `'0'`. This closes the block editor's separate-request case: the delay guarantees the meta has landed long before the send.

**MUST:** T-201 and T-211 drive the real request path — `wp_insert_post()` with `$_POST` populated and the nonce set — not pre-seeded meta. A test that seeds meta and then transitions the post passes against the broken design, which is exactly what the first draft's tests would have done.

### 11.6 Scheduling can fail, and the failure must be caught

`wp_schedule_single_event()` returns `false` in at least four situations. The first draft treated every scheduling site as a statement whose result did not matter. *(Review finding 3, blocker.)*

- An identical hook-and-args event is already due within **10 minutes** of the requested timestamp — WordPress's built-in duplicate suppression. This is not theoretical: TR-10 ("Repost now", delay 0), TR-16 ("Post to X now", delay 0) and TR-8 (5-minute backoff) all re-schedule the same hook with the same args, so any residual event for that post id silently swallows the new schedule.
- The `pre_schedule_event` filter short-circuits — what host-level cron replacements and cron-control plugins use.
- The `schedule_event` filter returns a falsey event.
- The `cron` option write fails.

Meanwhile FR-3.3 requires `_srl_status = scheduled` and `_srl_scheduled_at` to be written in the same request. If the schedule call failed and the meta write succeeded, the post sits permanently in `scheduled`, the meta box says "Scheduled for {time}", and **no event exists**. Nothing would ever move it: §10.2's staleness rule watches only `sending`.

**MUST:**

1. The return value of `wp_schedule_single_event()` is checked at **every** call site. On `false`: set `_srl_status = failed` with `_srl_last_error = 'schedule_failed'`, write a `failed` log row, raise the FR-5.2 notice (TR-15).
2. **Reconciliation.** The `srl_heartbeat` handler (§11.7) also looks for posts in `scheduled` whose `_srl_scheduled_at` is more than one hour past and for which `wp_next_scheduled( 'srl_send_post', array( (int) $post_id ) )` is `false`. Those move to `failed` with reason `event_lost` (TR-15). This is the safety net for the cases where the schedule call returned `true` and the event later vanished — a `cron` option overwritten by another process, a migration, a manual cron flush.

The project's goal is "no duplicate posts and **no silent failures**", and INV-5 says a failure is always recorded. Without this, a post reads "Scheduled" forever with no upper time bound.

### 11.7 The reconciliation scan

One scan, in the `srl_heartbeat` handler, doing three things. The daily prune is too slow for a 15-minute staleness rule, and `admin_init` only runs when someone is looking — which is the wrong trigger for a condition whose whole point is that nobody is looking.

| Check | Condition | Action |
|---|---|---|
| Stalled send | `_srl_status = 'sending'` and `_srl_sending_since` older than 15 min | TR-12: `failed`, reason `stalled`. |
| Lost event | `_srl_status = 'scheduled'`, `_srl_scheduled_at` more than 1 h past, and `wp_next_scheduled()` is false | TR-15: `failed`, reason `event_lost`. |

**The query is pinned, because an unindexed scan every minute is a real cost.** The scan uses `WP_Query` with `post_status => 'any'`, `posts_per_page => 20`, `no_found_rows => true`, `fields => 'ids'`, and a `meta_query` on `_srl_status IN ('sending','scheduled')`. WordPress indexes `postmeta.meta_key`, so this is an index scan over a small set, not a table scan; the plugin only ever has a handful of posts in those two states. The 20-row bound means a pathological backlog is worked through over successive minutes rather than in one request.

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
| FR-1.5 | "Send test post" button, URL-free, shows the response | Posts a **timestamped** string containing no URL; renders the response body unmodified in content inside `<pre><code>`, passed through `esc_html()`; writes a `test` log row with `post_id = 0` | T-120, T-121, T-122 |
| FR-1.9 | "Check credentials" button, beside "Send test post" | Calls `GET /2/users/me`; publishes **nothing**; reports the account handle on success and the status on failure; writes a `test` log row with `post_id = 0` | T-123, T-124, T-125 |
| FR-1.6 | Cron health panel | Green within the threshold, warning beyond it, warning when never set (§11.3) | T-130, T-131, T-132 |
| FR-1.7 | Usage counter for the current calendar month, by endpoint | Reflects every call including failures (§12) | T-140, T-141 |
| FR-1.8 | Log of the last 50 rows, newest first, filterable to attempt events (`sent`, `failed`, `retry`) | Each row is self-contained: `post_title`, `scheduled_at`, `sent_at`, `event`, `http_status`, and `remote_id` or the message, all read from the log row itself | T-150, T-151, T-152 |

**FR-1.9 note.** Added 2026-09-05 on the owner's decision (OPEN-4). `GET /2/users/me` costs about $0.010 **[DOC]** against the test post's $0.015, and — the point — it puts nothing on the timeline. It is the control an owner reaches for when they want to know whether the four keys work, which is most of the time. FR-1.5 remains the only thing that proves the *posting* path end to end, and the settings page says which is which so the cheaper button is not mistaken for the fuller check.

**FR-1.5 notes.**

The test post is published to the timeline and bills at the URL-free rate of $0.015. The settings page states both facts beside the button, because a button labelled "test" that costs money and posts publicly must say so before it is clicked.

**The string is timestamped, not fixed.** *(Review finding 21.)* Brief FR-1.5 says "a fixed test string", and §9.3 says — correctly, as a load-bearing part of the INV-1 argument — that X rejects a post whose text matches one posted recently. The two are individually right and jointly wrong: the second press of the button returns a duplicate error and reports a failed connectivity check. Since this is the owner's only credential check, and the mechanism OPEN-5 relies on to settle `/2/tweets` versus `/2/posts`, a false failure sends them to regenerate keys that were fine. The text is therefore `Social Relay connectivity test {YYYY-MM-DD HH:MM} UTC` — still URL-free, still cheap, and different on every press. This is a deviation from a brief FR and is listed in §17 as OPEN-12.

**"Verbatim" never means unescaped.** The first draft's acceptance said "renders the response body verbatim", which contradicts SEC-6 and SEC-9 and is a reflected XSS in a `manage_options` screen — the highest-value XSS target a plugin has. An upstream error body is attacker-influenceable in the general case: a hijacked DNS answer, a captive portal, a proxy interception page. *(Review finding 12.)* Throughout this document, "raw" and "verbatim" mean **unmodified text**, never unescaped markup.

### FR-2 Per-post control — meta box

| ID | Requirement | Acceptance | Tests |
|---|---|---|---|
| FR-2.1 | "Post to X" checkbox, defaulting from the master switch | Unchecking before publish prevents scheduling | T-200, T-201 |
| FR-2.2 | Per-post delay override | Blank uses the default; a set value overrides it; the 72-hour bound applies equally | T-210, T-211 |
| FR-2.3 | Read-only status line | Renders each of Not scheduled / Scheduled for {time} / Sent {time} with a link / Failed with a reason | T-220 |
| FR-2.4 | "Cancel scheduled post", visible only while `scheduled` | Clears the event and sets `cancelled` | T-230, T-231 |
| FR-2.5 | "Repost now", visible only when `sent` or `failed`, requiring confirmation | The only path to a second post; resets attempts; schedules at delay 0 | T-240, T-241, T-242 |
| FR-2.6 | "Post to X now", visible only when the post is `publish` and its status is `none` or `cancelled`, requiring confirmation | A first send for a post the automatic path never reached; sets the per-post switch on; schedules at delay 0 through the scheduler; refused from `sent`, `sending`, `failed` and from any unpublished post. Amendment 2. | T-250, T-251, T-252, T-253 |

All three buttons require a nonce and the `edit_post` capability for the specific post. Times display in the site's timezone; they are stored in UTC.

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
| FR-4.13 | Append the post's tags as hashtags, per §7.6, when enabled | Off by default; multi-word tags join in PascalCase; hashtags are dropped whole before the title is truncated | T-441 through T-449 |

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
- **SEC-5** Every query is prepared, per INV-4. **The primary control is PHPCS's `WordPress.DB.PreparedSQL` and `WordPress.DB.PreparedSQLPlaceholders` sniffs**, which run in CI and block the merge. A grep is the backstop, and its rule is stated exactly rather than described: flag any `$wpdb->query(`, `get_results(`, `get_var(`, `get_row(` whose first argument is a string literal containing `$`, **other than** `$wpdb->prefix`, `$wpdb->postmeta` and `$wpdb->posts`. *(Review finding 11: the first draft's grep would have failed the build on §11.4's own mandatory compare-and-swap and on every log query, and a security control that must be weakened on day one to let the build pass is a control nobody trusts by Phase 5.)* **Static check S-1.**
- **SEC-6** Every input is sanitized; every output is escaped with `esc_html`, `esc_attr`, or `esc_url` as appropriate. Enforced by PHPCS's `WordPress.Security.EscapeOutput` sniff, not by a runtime test — escaping is a static property of source code and cannot be asserted at run time. **Static check S-2.** Where this document says a value is shown "raw" or "verbatim", it means unmodified **text**, escaped for output; no HTML from any API response is ever interpreted.
- **SEC-7** Outbound requests go to `api.x.com` only. A test asserts that no other host is ever requested, by intercepting `pre_http_request` and failing on any other host. **T-622.**
- **SEC-8** `uninstall.php` removes the options, all `_srl_*` post meta, the log table, and every scheduled event. **T-630.**
- **SEC-9** A response body written to the log is truncated to 2048 bytes and is never trusted as HTML. **T-623.**

**Stated limit, per ADR-003.** This is defence in depth. The key derives from `wp-config.php`, so anyone holding both the database and the filesystem can decrypt. `README.md` says so plainly rather than implying more protection than exists.

---

## 15. Hooks

### 15.1 Actions the plugin registers

*(Review finding 29: the first draft gave names only. Priorities and argument counts decide correctness here, and unstated details get implemented three different ways across three sessions.)*

| Hook | Callback signature | Priority | `accepted_args` | Purpose |
|---|---|---|---|---|
| `transition_post_status` | `( string $new_status, string $old_status, WP_Post $post )` | 10 | **3** | Serves **both** TR-1 (publish → schedule) **and** TR-3 (leaving `publish` → cancel). |
| `save_post` | `( int $post_id, WP_Post $post, bool $update )` | 10 | 3 | Meta box fields (§11.5). Nonce and `edit_post` checked first. |
| `srl_send_post` | `( int $post_id )` | 10 | 1 | The single scheduled send. |
| `srl_heartbeat` | `()` | 10 | 0 | Cron health beat plus the reconciliation scan (§11.3, §11.7). |
| `srl_prune_log` | `()` | 10 | 0 | Daily log and usage pruning (§5.1, §12). |
| `before_delete_post` | `( int $post_id, WP_Post $post )` | 10 | 2 | Clears the event on permanent deletion. **MUST** guard on post type and skip revisions: this fires for every post type and for every revision deleted during ordinary editing. |
| `cron_schedules` | `( array $schedules )` | 10 | 1 | Registers the one-minute schedule. |

**MUST — the argument order.** `transition_post_status` passes `( $new_status, $old_status, $post )`. Reversing the first two produces a plugin that fires on *un*publish and never on publish. No listed test would obviously catch it: T-303 (`publish_to_publish_schedules_nothing`) passes under the reversed reading too. T-300 through T-302 are what catch it, and only because they assert a schedule was created.

**`wp_trash_post` is deliberately not used.** It fires *before* the status changes, and trashing already fires `transition_post_status` with `publish → trash`, which the same handler catches for the unpublish case. Registering both would double-handle. *(The first draft listed `wp_trash_post` and assigned `transition_post_status` only to "Scheduling entry point", so the hook that actually handles unpublish was never named.)*

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
| T-122 `test_test_post_text_differs_between_invocations` | FR-1.5, review finding 21 |
| T-123 `test_check_credentials_publishes_nothing` | FR-1.9 |
| T-124 `test_check_credentials_reports_the_account_handle` | FR-1.9 |
| T-125 `test_check_credentials_reports_failure_without_posting` | FR-1.9 |
| T-108 `test_credentials_unreadable_marks_affected_post_failed` | §6.3, review finding 24 |
| T-130 `test_cron_health_green_within_threshold` | FR-1.6 |
| T-131 `test_cron_health_warns_beyond_threshold` | FR-1.6 |
| T-132 `test_cron_health_warns_when_never_run` | FR-1.6 |
| T-133 `test_heartbeat_updates_last_run_option` | §11.3 |
| T-140 `test_usage_counter_increments_on_success` | FR-1.7 |
| T-141 `test_usage_counter_increments_on_failure` | FR-1.7, FR-4.12 |
| T-150 `test_log_panel_returns_last_50_newest_first` | FR-1.8 |
| T-151 `test_log_row_is_self_contained_after_post_deletion` | FR-1.8, review finding 17 |
| T-152 `test_every_event_value_round_trips_through_the_column` | §5, review finding 13 |
| T-153 `test_dbdelta_upgrade_from_version_1_preserves_rows` | §5.2, review finding 19 |

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
| T-250 `test_send_now_button_visible_only_for_published_unsent_posts` | FR-2.6 |
| T-251 `test_send_now_schedules_at_zero_delay_and_sets_the_switch` | FR-2.6, TR-16 |
| T-252 `test_send_now_is_ignored_from_sent_sending_failed_and_unpublished` | FR-2.6, TR-16, INV-1 |
| T-253 `test_send_now_replaces_an_event_scheduled_in_the_same_request` | TR-16, §11.5 |

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
| T-306 `test_republish_after_cancel_schedules_again` | G-5, TR-1 |
| T-307 `test_republish_after_sent_is_a_no_op` | G-5, TR-13, INV-1 |
| T-308 `test_untrash_then_publish_does_not_resend` | G-5, TR-13, INV-1 |
| T-309 `test_wp_importing_is_skipped_and_logged` | G-6 |
| T-312 `test_bulk_edit_is_skipped_and_logged` | G-7 |
| T-313 `test_post_older_than_freshness_window_is_skipped` | G-8 |
| T-314 `test_unchecked_box_in_same_request_prevents_scheduling` | §11.5, FR-2.1 |
| T-315 `test_delay_override_in_same_request_is_used` | §11.5, FR-2.2 |
| T-316 `test_schedule_failure_sets_failed_not_scheduled` | §11.6, TR-15 |
| T-317 `test_lost_event_is_reconciled_to_failed` | §11.6, TR-15, INV-7 |
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
| T-404 `test_post_stalled_in_sending_becomes_failed_not_retried` | §10.2, TR-12 |
| T-405 `test_claim_returning_false_logs_and_leaves_status_scheduled` | §11.4, review finding 18 |
| T-406 `test_duplicate_status_meta_aborts_and_logs` | §11.4, review finding 18 |
| T-407 `test_enabled_flag_rechecked_at_send_time` | §11.5 |
| T-408 `test_trashed_during_send_does_not_double_handle` | TR-14 |
| T-417 `test_media_failure_does_not_consume_retry_budget` | §9.5, INV-6, review finding 6 |
| T-418 `test_retry_reuses_media_id_within_expiry` | §9.1, review finding 22 |
| T-419 `test_unsupported_mime_is_skipped_with_reason` | §8.2, review finding 23 |
| T-429 `test_multipart_body_is_a_string_not_an_array` | §8.2, review finding 15 |
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
| T-433 `test_url_inside_the_title_is_weighed_as_23` | §7.1.1, review finding 8 |
| T-434 `test_bare_domain_in_title_is_weighed_conservatively` | §7.1.1 |
| T-435 `test_abbreviations_are_not_treated_as_domains` | §7.1.1 |
| T-436 `test_combining_marks_are_counted_per_codepoint_after_nfc` | §7.4, review finding 9 |
| T-437 `test_counter_matches_twitter_text_published_fixtures` | §7.1.1 |
| T-438 `test_thai_and_devanagari_weigh_one` | §7.1, review finding 10 |
| T-439 `test_adjacent_zwj_emoji_are_not_merged` | §7.4 — PCRE2's `\X` merges them, a 278-unit under-count |
| T-440b `test_invalid_url_host_is_weighed_literally_not_as_23` | §7.1.1 — X does not shorten an over-long host |
| T-428 `test_empty_prefix_and_suffix_produce_no_double_spaces` | §7.2 |

### 16.6.1 Hashtags

| Test | Covers |
|---|---|
| T-441 `test_multi_word_tag_becomes_pascal_case_hashtag` | FR-4.13, §7.6.1 |
| T-442 `test_existing_capitalisation_in_a_tag_is_preserved` | FR-4.13, §7.6.1 rule 3 |
| T-443 `test_punctuation_inside_a_tag_is_removed_not_left_to_truncate_the_hashtag` | FR-4.13, §7.6.1 — the `#co` failure |
| T-444 `test_all_digit_tag_produces_no_hashtag` | FR-4.13, §7.6.1 rule 5 |
| T-445 `test_hashtags_are_deduplicated_case_insensitively_and_capped_by_count` | FR-4.13, §7.6.2 |
| T-446 `test_hashtag_block_is_capped_at_sixty_weighted` | FR-4.13, §7.6.3 cap 1 |
| T-447 `test_hashtags_are_dropped_whole_before_the_title_is_truncated` | FR-4.13, §7.6.3 cap 2, §7.3 |
| T-448 `test_hashtags_appear_after_the_suffix_and_before_the_url` | FR-4.13, §7.2 |
| T-449 `test_post_tags_become_hashtags_at_send_time` | FR-4.13, §7.5 |

### 16.7 Error handling

| Test | Covers |
|---|---|
| T-430 `test_media_ids_included_when_image_present` | FR-4.6 |
| T-431 `test_media_key_absent_entirely_when_no_image` | FR-4.6, §8.3 |
| T-440 `test_success_stores_id_status_sent_at_and_log_row` | FR-4.7, TR-7 |
| T-450 `test_429_retries_with_five_minute_backoff` | FR-4.8, TR-8 |
| T-451 `test_500_retries_three_times_then_fails_on_fourth_attempt` | FR-4.8, TR-9 |
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
| T-501 `test_plugin_source_contains_no_unguarded_error_log_call` | FR-5.1, static check S-3 |
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
| T-624 `test_event_column_accepts_credentials_unreadable` | SEC-9, review finding 13 |
| T-622 `test_no_request_to_any_host_other_than_api_x_com` | SEC-7, INV-3 |
| T-623 `test_logged_body_is_truncated_and_not_trusted_as_html` | SEC-9 |
| T-630 `test_uninstall_removes_options_meta_table_and_events` | SEC-8 |

### 16.9 Signer tests

*(Review finding 14: the highest-risk code in the plugin had no test anywhere in §16, while §16.9 asserted full coverage — because the assertion only checked FR and TR identifiers, and the signer has no FR of its own. A signer bug produces HTTP 401, which §9 routes to `failed` with a notice saying "credentials need attention", sending the owner to regenerate keys that were never the problem. That is the same misattribution failure ADR-003's `credentials_unreadable` design exists to prevent, arriving through a different door.)*

These run in the **unit** suite, without WordPress.

| Test | Covers |
|---|---|
| T-900 `test_base_string_matches_rfc_5849_worked_example` | §8.1, the vector pinned there |
| T-901 `test_encoding_is_rfc_3986_not_urlencode` | §8.1 — a space as `+` is the classic bare-401 bug |
| T-902 `test_authorization_header_signature_is_correct` | §8.1 |
| T-903 `test_json_body_is_not_included_in_the_signature` | §8.1 |
| T-904 `test_multipart_body_is_not_included_in_the_signature` | §8.1, review finding 15 |
| T-905 `test_query_parameters_are_signed` | §8.1 |
| T-906 `test_nonce_differs_between_calls` | §8.1 — a repeated nonce is grounds for rejection |
| T-907 `test_is_complete_requires_all_four_values` | §8.1 |
| T-908 `test_malformed_url_throws` | §8.1 |

### 16.10 Static checks

Three items in the first draft's test list were not tests. *(Review finding 25.)* Escaping is a static property of source code and cannot be asserted at run time; a grep is not a PHPUnit test; and asserting `error_log` emptiness is polluted by any notice from core or another plugin in the bootstrap.

| ID | Check | Mechanism |
|---|---|---|
| S-1 | No unprepared SQL | PHPCS `WordPress.DB.PreparedSQL`, plus the backstop grep whose exact rule is in SEC-5 |
| S-2 | All output escaped | PHPCS `WordPress.Security.EscapeOutput` |
| S-3 | No `error_log(` in plugin source outside a `WP_DEBUG` guard | grep over `includes/`, `admin/`, `social-relay.php` |
| S-4 | Only `api.x.com` appears as an outbound host in source | grep for `https://` in plugin source |

### 16.11 Coverage assertion

Per brief §10, coverage is reported but no percentage target is set.

The first draft's CI check was **self-referential and could never fail**: it asserted that every `T-n` identifier in this document appears in the test list, and every `T-n` is *defined* in the test list. It proved nothing about the suite. *(Review finding 25.)*

**Specified, in two halves:**

1. Every `FR-x.y` in §13 and every `TR-n` in §10.1 appears at least once in §16. (Checks the spec against itself — still worth having.)
2. **Every `T-n` in §16 corresponds to a method of that exact name in the test suite**, found by grepping `tests/`, and every `test_` method in the suite corresponds to a `T-n` in §16. (Checks the spec against the code — this is the half that decays.)

Both halves run in CI and block the merge.

---

## 17. Open items for the Specification Gate

Everything in this document that goes beyond `PROJECTBRIEF.md`, or that remains unpinned. Deliberately not stated as a count — review finding 27 caught that §0 claimed four while §17 listed six and four more were unlisted.

Rows OPEN-7 to OPEN-10 are departures from the brief that were resolved by evidence and were nonetheless missing from the first draft's list. They are listed for visibility, not because they are unresolved.

| ID | Item | Where | Recommendation |
|---|---|---|---|
| **OPEN-1** | Transport errors and timeouts are not covered by FR-4.8..4.11. Specified as retryable, like a 5xx. | §9.2 | Accept. `wp_remote_post()` returns `WP_Error` for DNS, TLS, reset and timeout, and unhandled these fall through to whichever branch is last — a silent failure that INV-5 forbids. |
| **OPEN-2** | X's pixel limits for `tweet_image` are not pinned. Only the 5 MB byte limit is documented. | §8.2 | Downscale on bytes alone in v1, and record the observed limit during Phase 6 acceptance. Guessing a pixel bound is worse than not enforcing one. |
| **OPEN-3** | A post stuck in `sending` after a crash has no exit in the brief's state machine. Specified as `failed` reason `stalled` after 15 minutes, with no automatic retry. | §10.2 | Accept. Without it a crashed send is invisible forever. Not auto-retrying is deliberate: a crash mid-send is indistinguishable from a lost response, and retrying risks breaking INV-1. |
| **OPEN-4** | FR-1.5's "Send test post" publishes publicly and costs $0.015. `GET /2/users/me` proves credentials for about $0.010 without posting. | §8.4 | **RESOLVED 2026-09-05 — owner accepted.** FR-1.5 stands unchanged and a second "Check credentials" control is added beside it as **FR-1.9**. This is an addition to the brief, made on the owner's explicit decision rather than unilaterally. |
| **OPEN-5** | The create-post path is `/2/tweets` or `/2/posts` (OQ-15). Not probed, because it costs money and publishes. | §8.3 | Hold it in one constant; settle at Phase 6 via FR-1.5. |
| **OPEN-6** | The cron staleness threshold of 5 minutes assumes Hostinger can run cron every minute (OQ-18). | §11.3 | Keep 5 minutes, as one named constant. Revisit if hPanel's minimum turns out to be 5 minutes, in which case it becomes 15. |
| **OPEN-7** | **Host allowlist reduced to one host.** Brief §8 requires `api.x.com` **and** `upload.x.com`; INV-3 allows only the first. | INV-3 | Resolved by OQ-13: the full flow was proven against `api.x.com` alone, and `upload.x.com` is the legacy v1.1 host brief §1.3 forbids building on. Listed because it changes a brief MUST. |
| **OPEN-8** | **One-shot upload instead of chunked.** Brief §1.3 calls chunked "the recommended path"; §8.2 says MUST NOT use it in v1. | §8.2 | Resolved by OQ-14: both were proven to work, and §0's simplicity constraint requires the one with fewer moving parts. Chunked stays documented as the fallback. |
| **OPEN-9** | **Prefix and suffix are 60 *weighted* characters**, not 60 characters. Brief FR-1.4 says "≤ 60 characters". | §3 | Accept. Measuring the field in the same units the post is measured in is the only way the §7.3 budget arithmetic holds. It does mean a 40-emoji prefix is now rejected. |
| **OPEN-10** | **PHP floor is 8.2**, not the brief's 8.1. | §18 | Resolved by OQ-5 on 2026-09-05. Changes the CI matrix from "PHP 8.1 and latest" to 8.2 and latest. |
| **OPEN-11** | **X's duplicate-detection window is unmeasured**, and §9.3's INV-1 safety argument depends on it. | §9.3 | Measure in Phase 6: post, delete, repost identical text at 5, 30 and 90 minutes. If the window is shorter than 60 minutes, cap the backoff at the measured value. *(Review finding 26.)* |
| **OPEN-12** | **The test post string is timestamped**, not fixed. Brief FR-1.5 says "a fixed test string". | §13 FR-1.5 | Accept. A fixed string is rejected as a duplicate on the second press, so the owner's only credential check reports failure for a working credential. *(Review finding 21.)* |
| **OPEN-14** | **Hashtags from post tags**, added after the Specification Gate on the owner's instruction. Brief §3 v0.1 listed hashtag generation as a non-goal. | §7.6, FR-4.13 | **RESOLVED 2026-09-05 — owner asked for it, and then asked for the brief to be amended.** `PROJECTBRIEF.md` is now v0.2: §3 carries amendment 1 and §4 carries FR-4.13, so the spec and the brief agree again. Recorded as **ADR-005**, which keeps the original non-goal wording. Listed here for visibility, like OPEN-7 to OPEN-10, not because it is unresolved. The two premises about how X renders hashtags were **OQ-20**, closed 2026-09-05 by X's own Help Center wording (§7.6.1); they are `[DOC]` rather than `[MEASURED]`, and neither could fail a send either way. |
| **OPEN-13** | **18 PHP files under `includes/` and `admin/`, against the brief's "target: fewer than 15".** | §5 of the brief | **RESOLVED 2026-09-05 — owner accepted 18.** Reasoning retained below. | **Owner's call.** Three of the extras — `class-crypto.php`, `class-oauth1.php`, `class-text.php` — exist to be WordPress-free so the unit suite can run without Docker. That is not decoration: it is how the three counting defects in §7 were caught, and folding them back into their callers would make them untestable without a database. Two more, `class-post-payload.php` and `class-send-result.php`, are named in the brief's own §5 prose but were given no files. The remaining one is `class-notices.php`. Consolidating to 15 is possible and would cost testability; I did not do it unilaterally because "target" is softer than MUST but is still the owner's number. |
| **OPEN-15** | **A manual send for any published post**, added after the Specification Gate on the owner's instruction. Brief §1.5 and §2 describe only the automatic trigger. | §10.1 TR-16, §13 FR-2.6 | **RESOLVED 2026-09-05 — owner asked for it, and the brief was amended in the same change.** `PROJECTBRIEF.md` is v0.4: §4 carries amendment 3 and FR-2.6, so the spec and the brief agree. Recorded as **ADR-006**. Listed for visibility, like OPEN-14. |

### Carried from `OPENQUESTIONS.md`

Still open, none blocking: **OQ-1b** media-upload pricing, **OQ-15** the create-post path, **OQ-18** every-minute cron on Hostinger, **OQ-19** whether Apps still sit inside Projects. OQ-18 and OQ-19 need one sentence each from the owner. OQ-1b and OQ-15 are measurements belonging to later phases by the brief's own design.

---

## 18. What this document does not decide

Named so that their absence is visible rather than assumed.

- **File-by-file implementation order.** That is `PLAN.md`, Phase 3.
- **Exact admin page markup and styling.** Implementation detail, constrained only by the escaping rules in SEC-6.
- **The precise wording of admin notices**, except where §6.3 forbids a specific phrasing for a specific reason.
- **CI configuration.** Phase 4, constrained by brief §10 and by the PHP 8.2 floor decided in OQ-5, which changes the matrix from the brief's "PHP 8.1 and latest" to **8.2 and latest**.

---

## 19. Phase 2 review response

`reviews/spec-review-1.md` returned 29 findings: 4 blocker, 13 major, 11 minor, 1 question. **All 29 were accepted and applied. None was declined**, so no ADR was opened under the brief's §11 Phase 8 rule.

The review's central observation is worth recording verbatim, because it names the failure mode rather than the symptoms:

> the spec reasons about WordPress as a set of clean state transitions and does not reason about WordPress as a request lifecycle.

All four blockers were instances of that, and each would have passed the test named to cover it. That is the part worth remembering: the tests were not weak by accident, they were derived from the same wrong model as the design.

| # | Finding | Severity | Resolved in |
|---|---|---|---|
| 1 | `transition_post_status` fires before `save_post`, so the meta box cannot affect its own publish | blocker | §11.5 |
| 2 | Republished, untrashed or cloned posts send again — INV-1 unguarded at scheduling | blocker | §10.3 G-5, TR-13 |
| 3 | `wp_schedule_single_event()` return never checked | blocker | §11.6, TR-15, INV-7 |
| 4 | The `sending` staleness rule had no timestamp, no trigger and no transition row | blocker | §4, §10.2, TR-12, §11.7 |
| 5 | Retry budget made the 60-minute backoff unreachable | major | §9 matrix, §9.1 |
| 6 | Media errors could consume the tweet's retry budget, contradicting INV-6 | major | §9.5 |
| 7 | Bulk edit, imports and programmatic publishing unguarded — a 500-post import costs $100 | major | §10.3 G-6..G-8 |
| 8 | A URL inside the title was under-counted | major | §7.1.1 |
| 9 | Grapheme-cluster counting without NFC under-counts combining sequences | major | §7.4 |
| 10 | §7.1's prose put Thai and Devanagari at weight 2, contradicting its own ranges | major | §7.1 |
| 11 | SEC-5's grep would fail the build on §11.4's own required query | major | INV-4, SEC-5, S-1 |
| 12 | FR-1.5's "verbatim" response rendering is an admin XSS | major | FR-1.5, SEC-6 |
| 13 | `event varchar(20)` cannot hold `credentials_unreadable` (22 chars) | major | §5 |
| 14 | The OAuth signer had no test anywhere in §16 | major | §8.1 vector, §16.9 |
| 15 | `wp_remote_post()` cannot send multipart natively | major | §8.1, §8.2 |
| 16 | Event args matched by `md5(serialize())` — an int/string mismatch clears nothing | major | §11.2 |
| 17 | FR-1.8's panel could not be rendered from the schema | major | §5, FR-1.8 |
| 18 | The compare-and-swap collapsed three outcomes into one silent exit | minor | §11.4 |
| 19 | No DB version mechanism, so `dbDelta()` never runs on update | minor | §3, §5.2 |
| 20 | Cron panel shows false green to exactly the sites it should warn | minor | §11.3 |
| 21 | The fixed test string is rejected as a duplicate on second use | minor | FR-1.5, OPEN-12 |
| 22 | A retry re-uploads the image, corrupting the reconciliation counter | minor | §4, §9.1 |
| 23 | No MIME allowlist and no achievable downscale rule | minor | §8.2 |
| 24 | `credentials_unreadable` left no trace on the affected post | minor | §6.3 |
| 25 | §16.9's coverage assertion was self-referential and could never fail | minor | §16.10, §16.11 |
| 26 | INV-1 depends on X's unmeasured duplicate window | question | §9.3, OPEN-11 |
| 27 | §0 claimed four deviations; there were at least ten | minor | §0, §17 |
| 28 | The key fingerprint claim was overstated | minor | §6.2 |
| 29 | Hook priorities, signatures and `accepted_args` unstated | minor | §15.1 |

The review also recorded 14 areas checked and found sound, including the compare-and-swap concept, the weight-range conversion, the budget arithmetic, the UTC discipline, the secret envelope's cryptography, §8.2's measured response-parsing rules, and §9.4's refusal to retry an unparseable 2xx. Those are listed in the review file and are not restated here.
