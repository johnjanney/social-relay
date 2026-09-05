# reviews/spec-review-1.md — Independent review of `SPEC.md` (Phase 2)

**Reviewer:** independent Claude session, review-only, fresh context.
**Inputs read in full:** `PROJECTBRIEF.md` v0.1, `SPEC.md` 1.0-draft.1 (2026-09-05). `DECISIONS.md` and `OPENQUESTIONS.md` read for context only and not reviewed as deliverables.
**Date:** 2026-09-05.

## Overall assessment

This is an unusually careful specification. The evidence discipline is real — the `[MEASURED]` API captures, the `data.id`-only rule, the `h`/`w` versus `height`/`width` trap, the media-expiry reasoning, the honest admission that the usage counter is lossy — and several sections (the secret envelope in §6, the error matrix's refusal to retry a malformed 2xx in §9.4, the `api.x.com`-only host rule) are better than what most shipped plugins do. The weakness is concentrated in exactly one place: **the spec reasons about WordPress as a set of clean state transitions and does not reason about WordPress as a request lifecycle.** Four defects follow from that, and each of them breaks a stated invariant in production while passing the test named to cover it: the per-post meta box cannot influence the publish it is attached to, because `transition_post_status` fires before the meta box is saved (Finding 1); a re-published post sends a second time, because nothing consults `_srl_status` before scheduling (Finding 2); `wp_schedule_single_event()`'s return value is never checked, so a post can sit in `scheduled` forever with no log row (Finding 3); and the `sending` staleness rule has no timestamp to measure, no hook to run in, and no row in the transition table (Finding 4). Beyond those, the retry budget contradicts itself arithmetically (Finding 5), the media-failure path contradicts INV-6 (Finding 6), the weighted-length algorithm has two counting errors that produce the exact HTTP 400 the section exists to prevent (Findings 8, 9), and SEC-5 as written fails the build on §11.4's own required code (Finding 11). None of this is unfixable; all of it is cheaper to fix now than in Phase 5. I recommend the gate is **not** passed until Findings 1–4 are resolved in the document.

---

## Findings

### Finding 1: `transition_post_status` fires before the meta box is saved, so FR-2.1 and FR-2.2 cannot work

**Severity:** blocker
**Location:** §13 FR-3.1 detail (L568); §13 FR-2.1, FR-2.2 (L551–552); §10.1 TR-1/TR-2 (L424–425); tests T-201, T-211 (L692, L694)

**Problem:** In `wp_insert_post()`, `wp_transition_post_status()` is called *before* `do_action( 'save_post' )` — meta boxes save on `save_post` (or `edit_post`), which runs afterwards. So at the moment the scheduler runs, `_srl_enabled` and `_srl_delay_override` still hold the values from *before* this request, or do not exist at all for a post being published for the first time. Unchecking "Post to X" and pressing Publish in the same request therefore does not prevent scheduling, and a delay override typed in the same request is not used to compute `scheduled_at`. In the block editor the gap is wider still: the publish transition happens inside the REST request to `/wp/v2/posts/{id}`, while a classic meta box's fields are submitted afterwards in a *separate* `post.php?meta-box-loader=1` request. Brief §3 rules out a Gutenberg sidebar panel on the grounds that "a classic meta box… works in both editors", which is true for display and false for this ordering.

The spec does not name the hook on which meta box values are saved anywhere, so this ordering is invisible in the document. T-201 (`test_unchecked_post_is_not_scheduled`) and T-211 will be written as "`update_post_meta( $id, '_srl_enabled', '0' )`, then transition the post" — which passes, while the real editor path fails.

**Why it matters:** The owner unchecks the box, publishes, and the post goes to X anyway, at $0.20. This is the one control the brief gives the author to prevent an unwanted paid post, and as specified it does not work in either editor. A per-post delay override is silently ignored for the same reason.

**Suggested fix:** Add a subsection to §11 specifying the save path explicitly: (a) the meta box fields are saved on `save_post` with nonce verification; (b) the `transition_post_status` handler, when the meta box nonce is present in `$_POST`, reads `_srl_enabled` and `_srl_delay_override` from `$_POST` rather than from stored meta; (c) the publisher re-reads `_srl_enabled` at send time and cancels (TR-3, `cancelled`) if it is now `'0'`, which closes the block editor's second-request case because the delay guarantees the meta has landed by then; and (d) T-201/T-211 must drive the real request path (`wp_insert_post` with `$_POST` populated), not pre-seeded meta.

---

### Finding 2: An unpublished-then-republished post sends a second time — INV-1 has no guard at scheduling

**Severity:** blocker
**Location:** §10.1 transition table (L422–434); §13 FR-3.1 detail (L568); §2.1 INV-1 (L46)

**Problem:** The only stated scheduling guard is `$new === 'publish' && $old !== 'publish'` plus the two switches and the post type. Nothing consults the current `_srl_status`. TR-1's "From" column says `none`, but no rule anywhere enforces that, and the reachable paths that arrive at a publish transition with a non-`none` status are ordinary editorial behaviour:

- publish → draft (TR-3, `cancelled`) → publish again. Status is `cancelled`; the guard passes; a new event is scheduled.
- publish → trash (TR-3, `cancelled`) → untrash. `wp_untrash_post()` restores to draft by default and to `publish` when the `wp_untrash_post_set_previous_status` filter is used (many sites and plugins do); either way a later publish transition fires.
- Already `sent`, then publish → private → publish (or publish → future → publish when an editor re-schedules a live post). Status is `sent`; the guard passes; **the post is sent to X a second time**, without the confirmation click that FR-2.5 makes the sole path to a second post.
- A post duplicated by a "clone post" plugin inherits `_srl_status = sent` and `_srl_remote_id`; the meta box then shows "Sent" with a link to the *original's* X post, and on publish the clone hits the same hole.

There are also no transition rows for `cancelled → *`, `failed → publish`, or `sending → trashed` (a post trashed while a send is in flight: TR-3 clears an event that has already fired, the send completes, and X now points at a 404). And §4 never states that absent `_srl_status` meta is equivalent to `none`, which the CAS in §11.4 quietly depends on.

**Why it matters:** INV-1 is the plugin's top invariant and the brief's §1.5 hard requirement. Unpublish-and-republish is a normal correction workflow; as specified it produces a duplicate paid post. X's duplicate rejection will *not* save this case, because the title was usually corrected in between, so the text differs.

**Why it matters more than it looks:** the failure is silent and delayed — it shows up on the timeline an hour later, not in the editor.

**Suggested fix:** Make the scheduling guard read `_srl_status` and refuse unless it is `none`/absent, `cancelled`, or `failed` (the owner's re-attempt), and **never** when it is `sent`, `sending`, or `scheduled`. Add explicit transition rows for `cancelled → scheduled` (republish), `sent → sent` (republish is a no-op, with a meta box notice explaining why), and `sending → cancelled` (trashed mid-send: mark it, but do not attempt to unsend).

---

### Finding 3: `wp_schedule_single_event()` can return `false`, and the spec never checks it

**Severity:** blocker
**Location:** §11.1 (L452); §11.2 (L454–458); §9.1 (L377–381); §10.1 TR-1, TR-8, TR-10 (L424, L431, L433)

**Problem:** Every scheduling site in the document is written as a statement, not as a call whose result matters. `wp_schedule_single_event()` returns `false` in at least four situations: (a) an identical hook-and-args event is already due within **10 minutes** of the requested timestamp — WP's built-in duplicate suppression; (b) the `pre_schedule_event` filter short-circuits, which is what host-level cron replacements and cron-control plugins use; (c) the `schedule_event` filter returns a falsey event; (d) the `cron` option write fails. Meanwhile FR-3.3 requires `_srl_status = scheduled` and `_srl_scheduled_at` to be written in the same request. If the schedule call failed and the meta write succeeded, the post is now permanently in `scheduled`, the meta box says "Scheduled for {time}", and no event exists. Nothing will ever move it: §10.2's staleness rule only watches `sending`, and no cron pass scans for `scheduled` posts whose event has vanished.

The 10-minute window is not theoretical here: TR-10 ("Repost now", delay 0) and TR-8 (5-minute backoff) both re-schedule the *same hook with the same args*, and any residual event for that post id — for instance one left behind by the argument-type mismatch in Finding 16 — will silently swallow the new schedule.

**Why it matters:** The project's stated goal is "no duplicate posts and **no silent failures**", and INV-5 says "a failure is always recorded". This is a silent failure with no upper time bound, visible to the owner only as a post that says "Scheduled" forever.

**Suggested fix:** Specify that the return of `wp_schedule_single_event()` is checked at every call site; on `false`, the meta is set to `failed` with reason `schedule_failed`, a `failed` log row is written and the FR-5.2 notice is raised. Additionally specify a reconciliation step in the existing daily `srl_prune_log` pass (or the heartbeat): any post in `scheduled` whose `_srl_scheduled_at` is more than one hour past and for which `wp_next_scheduled( 'srl_send_post', array( (int) $post_id ) )` is false is moved to `failed` reason `event_lost`.

---

### Finding 4: The `sending` staleness rule is unimplementable — no timestamp, no trigger, no table row

**Severity:** blocker
**Location:** §10.2 (L436–442); §4 post meta schema (L102–113); §10.1 (L422–434); T-404 (L727); §17 OPEN-3 (L806)

**Problem:** §10.2 specifies that "a post that has been in `sending` for more than 15 minutes is considered crashed" and that "the next cron pass moves it to `failed` with reason `stalled`". Three things needed to implement that sentence are absent from the document:

1. **No timestamp records when `sending` began.** §4 lists `_srl_scheduled_at` (set at schedule time, and for a retry it is the *retry's* schedule time) and `_srl_sent_at` (only written on success). Neither answers "how long has this post been in `sending`?" `_srl_scheduled_at` is the closest proxy and is wrong: with the "not before" semantics of §11.1, a post can legitimately be in `scheduled` far past `_srl_scheduled_at` before it ever enters `sending`.
2. **There is no "next cron pass" for that post.** The `srl_send_post` event was consumed when it fired (WP unschedules before invoking), and the crashed run never scheduled a successor. So no event exists that would revisit this post. Something must *scan* for stalled posts, and the spec never says which hook does it (`srl_heartbeat`? `srl_prune_log`? `admin_init`?) or how the query is shaped — a `meta_query` on `_srl_status = 'sending'` across all posts is the obvious implementation and is exactly the kind of unindexed scan that a spec should pin deliberately.
3. **The transition is missing from §10.1 and from the §10 diagram**, although §10.1 asserts "Every row has at least one test in §16" and §16.9 asserts every transition is named. T-404 (`test_post_stalled_in_sending_becomes_failed_not_retried`) cannot be written against this document, because there is nothing to set up: the test cannot put a post into "`sending` since 20 minutes ago".

**Why it matters:** §10.2 correctly identifies that without this rule a crashed send is invisible forever — a silent failure INV-5 forbids. As written, the rule is prose that no implementer can turn into code without inventing the missing pieces, and different implementers will invent different ones.

**Suggested fix:** Add `_srl_sending_since` (int, UTC) to §4, written by the same claim that sets `sending`; add a row TR-12 (`sending` → `failed`, trigger: stale claim, side effects: `_srl_last_error = 'stalled'`, log `failed`, notice) to §10.1 and to the diagram; and name the hook that scans, with its query, in §11 — the daily `srl_prune_log` is too slow for a 15-minute rule, so the `srl_heartbeat` handler is the natural home.

---

### Finding 5: The retry budget contradicts itself — the 60-minute backoff can never fire

**Severity:** major
**Location:** §9 error matrix (L366–368); §9.1 (L379); §10.1 TR-8/TR-9 (L431–432); §13 FR-4.8 (L581); T-451, T-452 (L758–759)

**Problem:** §9 defines `attempts` as `_srl_attempts` **after** the current attempt is counted, and gates retry on `attempts < 3`. Trace it: attempt 1 fails → `attempts = 1` → retry at +5 min. Attempt 2 fails → `attempts = 2` → retry at +15 min. Attempt 3 fails → `attempts = 3` → `3 < 3` is false → `failed`. That is **3 total attempts and 2 retries**, and the 60-minute backoff in §9.1 is unreachable. But §9.1 says the delays apply "for attempts 1, 2 and 3", FR-4.8's acceptance says "A fourth failure produces `failed`, not a fourth retry", T-451 is named `test_500_retries_then_fails_on_fourth_attempt`, and T-452 is `test_backoff_sequence_is_5_15_60_minutes`. Two of the named tests cannot pass against the matrix, and brief FR-4.8 ("maximum 3 retries, then `failed`" — i.e. 4 attempts) agrees with the tests, not the matrix.

**Why it matters:** An implementer following §9 ships a plugin that gives up after 3 attempts spanning 20 minutes, and CI goes red on two tests whose names encode the other reading. Worse, whichever is "fixed" to match the other is a coin flip, and the difference is whether a one-hour X outage produces a `failed` post or a delivered one.

**Suggested fix:** Change the matrix condition to `attempts <= 3` (equivalently `attempts < 4`) and TR-9's terminal condition to `attempts = 4`, so that 1 initial attempt plus 3 retries at 5/15/60 minutes matches §9.1, FR-4.8 and both test names.

---

### Finding 6: The media-upload error path contradicts INV-6 and can consume the tweet's retry budget

**Severity:** major
**Location:** §9, paragraph after the matrix (L375); §2.1 INV-6 (L51); §13 FR-4.4 (L577); §12 (L521)

**Problem:** "The **media upload** call uses the same matrix with one difference: every terminal outcome sets `_srl_image_omitted` and continues to the post." The matrix's non-terminal outcomes are 429, 5xx and transport errors, whose "Resulting status" column is `scheduled` — i.e. reschedule the whole send with backoff. So a 429 on the media endpoint means: reschedule the entire post for 5 minutes' time (delaying the tweet), *or* omit the image and continue (per INV-6). The spec supports both readings and picks neither. Two further consequences are unstated: `_srl_attempts` is a single counter shared by both calls, so three media hiccups exhaust the budget without `POST /2/tweets` ever being called, and the post then fails with an error that is about the image; and each rescheduled attempt re-uploads the image, because there is no `_srl_media_id` meta (see Finding 22).

**Why it matters:** INV-6 says the featured image never blocks the post. Under the "same matrix" reading it blocks it for up to 20 minutes and can fail it outright, which is the opposite of the invariant and of FR-4.4.

**Suggested fix:** State in §9 that the media call has **no retry tier of its own**: any non-2xx or transport outcome on `POST /2/media/upload` immediately sets `_srl_image_omitted` with the status/error as the reason and proceeds to `POST /2/tweets`. Media outcomes never touch `_srl_attempts` and never change `_srl_status`.

---

### Finding 7: Bulk edit, imports and programmatic publishing have no guard — a single import can cost hundreds of dollars

**Severity:** major
**Location:** §13 FR-3.1 and its detail (L563, L568); §10.1 TR-1/TR-2 (L424–425); §15.2 (L631–634)

**Problem:** The transition guard excludes autosaves, revisions and disabled post types, and nothing else. `transition_post_status` fires identically for:

- **Bulk edit** — selecting 40 drafts in the posts list and setting Status to Published fires 40 transitions in one request. Every one schedules a send, because `_srl_enabled` defaults from the master switch and the meta box is not rendered in bulk edit.
- **Quick Edit** — same, one post at a time, with the same absent-meta-box problem as Finding 1.
- **Programmatic publishing** — `wp_insert_post( array( 'post_status' => 'publish' ) )` from an importer, a feed aggregator, a migration script, WP-CLI, or the REST API. The WordPress importer sets `WP_IMPORTING`; nothing in the spec looks at it. A 500-post migration into a site with the plugin enabled schedules 500 sends of posts dated years ago.

At $0.20 per URL post (brief §1.1), 500 posts is $100 and a flood of near-simultaneous events that will also collide with X's rate limits, producing a cascade of 429 retries.

**Why it matters:** This is the only failure mode in the document that costs the owner real money without any human intending a post. It is also the one an owner would never forgive.

**Suggested fix:** Add to the FR-3.1 guard, and to §10.1 TR-2: skip when `defined( 'WP_IMPORTING' ) && WP_IMPORTING`; skip when the request is a bulk edit (`isset( $_REQUEST['bulk_edit'] )`) unless a deliberate opt-in is present; and skip when `post_date_gmt` is more than a configurable freshness window (default 24 hours) behind `time()`, since "newly published" in brief §1.5 means new, not merely newly-transitioned. Log each skip at `cancelled`/`skipped` so the behaviour is visible rather than mysterious.

---

### Finding 8: A URL inside the title is counted by character, but X counts it as 23 — the algorithm under-counts and overflows

**Severity:** major
**Location:** §7.1 (L233); §7.3 (L246–252); §7.4 (L254–265); T-427 (L747)

**Problem:** §7 treats "the URL" as a single known quantity — the permalink, weight 23, appended last — and weights the title character by character. X does not: `twitter-text` extracts **every** URL in the text and replaces each with `transformedURLLength` = 23. Its URL matcher fires on bare hostnames with a valid TLD, not only on `http(s)://` prefixes. So:

- A title containing `WordPress.com` (13 chars) is counted by the plugin as 13 and by X as **23** — an under-count of 10.
- "Why Notion.so beats Trello.com for X" contains two such tokens: the plugin under-counts by 20.
- A title containing a long URL is over-counted instead, which merely truncates earlier than necessary — harmless, but it means the error is bidirectional and cannot be hand-waved as conservative.

Under-counting is the fatal direction: the composed text passes the plugin's ≤ 280 check and X returns HTTP 400. §7.4's closing "MUST" says the algorithm is conservative precisely because "overflowing produces an HTTP 400 from X and a `failed` post, which is the failure this whole section exists to avoid" — and this is a path to exactly that. T-427 cannot catch it, because it asserts the result is ≤ 280 using the plugin's own weight function, which is the thing that is wrong.

**Why it matters:** Titles containing domain names are common on any site that writes about software, products or companies. Each one is a `failed` post that the owner must repost by hand.

**Suggested fix:** Specify that the weighted-length function first extracts URL-like substrings from the *whole composed text* using an explicit, pinned matcher (state the rule: scheme-prefixed URLs, plus bare `host.tld/…` where the TLD is in a pinned list, matching `twitter-text`'s extractor closely enough to be conservative), counts each as 23, and weights only the remainder character by character; and that when in doubt a candidate token is counted as 23 (the conservative direction, since 23 ≥ the length of any short domain). Additionally require §16.6 to assert against `twitter-text`'s own published `validate.yml` weighted-length fixtures rather than against the plugin's own counter.

---

### Finding 9: Grapheme-cluster counting without NFC normalization under-counts combining sequences

**Severity:** major
**Location:** §7.1 (L234); §7.4 step 4 (L261); T-423, T-426 (L743, L746)

**Problem:** §7.4 says to "walk the title by grapheme cluster, accumulating weight". `twitter-text` does something different: it NFC-normalizes the text, then walks **code points** applying the range weights, with one exception — a substring matched by its emoji regex (which is what `emojiParsingEnabled` turns on) counts as a single weight-2 unit. Grapheme clusters and emoji sequences are not the same set. Concretely:

- `e` + U+0301 (combining acute), a decomposed "é", is one grapheme cluster. The plugin counts 1. X normalizes to NFC → one code point → 1. Agreement, by luck.
- `a` + five combining marks (no NFC composition exists) is one grapheme cluster. The plugin counts 1; X counts **6** (each mark is < U+10FF, weight 1). **Under-count of 5 per occurrence → overflow → HTTP 400.** Decorative "zalgo" text is a novelty, but Vietnamese, Thai with tone marks, and Indic conjunct sequences hit the same mechanism at smaller magnitudes.
- Regional-indicator flag emoji: two code points, weight 2 each by range, but one emoji sequence → 2. The cluster rule gives 2. Agreement — this is the case T-423 tests, and it is the case that works.

The spec never mentions Unicode normalization at all, which is the step that makes the code-point rule deterministic.

**Why it matters:** Same as Finding 8 — a silent under-count is a `failed` post. The cluster rule was chosen to fix the ZWJ over-count and it does; it introduces a smaller under-count on a different class of text.

**Suggested fix:** Restate §7.4 step 4 as: NFC-normalize the composed text; then walk code points, applying the §7.1 ranges; treat a substring matched by the pinned emoji pattern as a single unit of weight 2; never cut inside a grapheme cluster when truncating (that part of step 4 is correct and should stay). Pin the emoji pattern's source and version in §7.1 the way the `v3.json` config is pinned.

---

### Finding 10: §7.1's prose contradicts the config it quotes — Thai and Devanagari weigh 1, not 2

**Severity:** major
**Location:** §7.1 (L231–232)

**Problem:** The range conversion itself is correct: 4351 = U+10FF, 8192–8205 = U+2000–U+200D, 8208–8223 = U+2010–U+201F, 8242–8247 = U+2032–U+2037. The prose that follows is not. It says weight 2 "covers CJK, Hangul, **Thai, Devanagari**, and emoji". Thai is U+0E00–U+0E7F and Devanagari is U+0900–U+097F; both are inside the weight-1 range `0–4351` that the same section prints two paragraphs earlier. CJK, Hangul and emoji are correctly listed as weight 2.

**Why it matters:** §0 item 5 says "Sizes, limits, and formats in this document are normative. Do not round them in code." An implementer who codes the prose list rather than the numeric ranges will halve the usable title length for Thai and Hindi sites, and — since the same paragraph is the natural source for the test fixtures — write a test that asserts the wrong answer and locks it in.

**Suggested fix:** Delete "Thai, Devanagari" from the weight-2 sentence, and add one sentence stating that the numeric ranges, not the script names, are normative; the script names are illustration only.

---

### Finding 11: SEC-5's build check and INV-4 fail on §11.4's own required code

**Severity:** major
**Location:** §2.1 INV-4 (L49); §14 SEC-5 (L605); T-620 (L786); §11.4 (L482–500)

**Problem:** INV-4 says "No interpolated variable ever reaches `$wpdb->query()`", and SEC-5/T-620 specify a build-time grep for "`$wpdb->query(` with an interpolated variable" that "fails the build". The compare-and-swap in §11.4 is `$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET … ", $post_id ) )` — a `$wpdb->query()` call whose SQL string contains the interpolated variable `{$wpdb->postmeta}`. §11.4 asserts "it is still fully prepared, so INV-4 holds as written", but INV-4 as literally written prohibits the interpolation, not the unprepared value. Either the grep is written as specified and CI fails on the plugin's only mandatory raw query, or it is loosened to exempt `$wpdb->` properties, at which point it no longer describes what SEC-5 claims and must be re-specified. The same loosening also has to exempt `{$wpdb->prefix}srl_log`, which every log read and the prune query will interpolate.

**Why it matters:** A security control that must be weakened on day one to let the build pass is a control nobody trusts by Phase 5. And a grep for "interpolated variable" with no stated pattern is not a specification — it is a hint.

**Suggested fix:** Rewrite INV-4 as "no value from a request, the database, or any external source is ever concatenated into SQL; table names come only from `$wpdb` properties; every value is a `prepare()` placeholder", and rewrite SEC-5 to state the grep's exact rule: flag `$wpdb->query(`, `get_results(`, `get_var(`, `get_row(` calls whose first argument is a string literal containing `$` **other than** `$wpdb->prefix`/`$wpdb->postmeta`/`$wpdb->posts`, and require that PHPCS's `WordPress.DB.PreparedSQL` sniff runs as the primary check with the grep as a backstop.

---

### Finding 12: FR-1.5 requires rendering the X response body "verbatim" — that contradicts SEC-6 and SEC-9 and is an XSS vector

**Severity:** major
**Location:** §13 FR-1.5 acceptance (L540) and note (L545); §14 SEC-6, SEC-9 (L606, L609); T-621, T-623 (L787, L789)

**Problem:** FR-1.5's acceptance criterion is "renders the response body **verbatim**". SEC-6 requires every output to be escaped and SEC-9 says a logged body "is never trusted as HTML". These cannot both be satisfied by an implementer reading the acceptance criterion literally — and "verbatim" is the word an implementer will follow, because it is stated as the acceptance test for the requirement. An error body from an upstream endpoint is attacker-influenceable in the general case (a hijacked DNS answer, a captive-portal or proxy interception page, an error page from an intermediary), and it is rendered into a `manage_options` screen.

**Why it matters:** Reflected script execution in wp-admin, in the one screen that only a full administrator can reach, is the highest-value XSS target a plugin has. It is also a contradiction the reviewer in Phase 7 will raise again if it survives.

**Suggested fix:** Change FR-1.5's acceptance to "renders the response body **unmodified in content** inside `<pre><code>`, passed through `esc_html()`; no HTML from the response is ever interpreted", and add a sentence to SEC-6 stating that "raw"/"verbatim" anywhere in this document means unmodified text, never unescaped markup.

---

### Finding 13: `event varchar(20)` cannot store `credentials_unreadable` (22 characters)

**Severity:** major
**Location:** §5 schema (L128) and the enumerated values (L143); §6.3 (L190)

**Problem:** The column is `event varchar(20)`. The enumerated values on L143 include `credentials_unreadable`, which is 22 characters. Under MySQL's default strict mode (which WordPress does not disable for this class of error) the `INSERT` is rejected outright; under a non-strict server it is silently truncated to `credentials_unreadab`, which then never matches any query filtering on the value. §6.3 requires exactly one such row to be written — "write one `credentials_unreadable` log row, not one per page load" — so the single record of the salt-rotation state is the row that cannot be stored.

**Why it matters:** The credentials-unreadable design exists to convert a silent, misattributed failure into a visible one (OQ-17). Its audit trail is the first casualty of a column that is two characters too narrow. It is also the kind of defect that appears only on a real MySQL server and not in a test that mocks the log writer.

**Suggested fix:** Widen the column to `varchar(32)` (which also leaves headroom for `duplicate_on_retry`, `malformed_response`, `schedule_failed`, `stalled` if those ever move from reason to event), and add a test asserting that every value in §5's enumeration round-trips through the table unchanged.

---

### Finding 14: The OAuth 1.0a signer — the highest-risk code in the plugin — has no test in §16

**Severity:** major
**Location:** §8.1 (L279–287); §16 in full (L654–794); brief §5 (L157); OQ-12

**Problem:** §16.9 claims "Every FR in §13 and every transition TR-1..TR-11 in §10.1 appears here", and it does. But §16 contains no test for the request signer: nothing for the signature base string, nothing for percent-encoding of `oauth_*` parameters, nothing for the nonce/timestamp, nothing for the `Authorization` header serialization, and — most importantly — nothing for §8.1's load-bearing rule that **the body is signed only when it is `application/x-www-form-urlencoded`, so this plugin never signs a body**. `OPENQUESTIONS.md` OQ-12 explicitly instructs that the RFC 5849 §3.4.1.1 vector "is therefore the test vector for the `class-x-provider.php` unit test; pin it in `SPEC.md`", and the vector is not pinned in `SPEC.md` — §8.1 only refers to it. Brief §5 makes the same requirement.

**Why it matters:** A signer bug produces HTTP 401, which §9's matrix routes to `failed` with an admin notice saying "credentials need attention" — sending the owner to the X Console to regenerate keys that were never the problem. This is the same misattribution failure that ADR-003's whole `credentials_unreadable` design exists to prevent, arriving through a different door and with no test to catch it.

**Suggested fix:** Add a §16.10 with at least: `test_signature_base_string_matches_rfc5849_vector` (with the expected base string and signature pinned verbatim in §8.1), `test_json_body_is_not_included_in_the_signature`, `test_multipart_body_is_not_included_in_the_signature`, `test_query_parameters_are_included_and_sorted`, and `test_oauth_percent_encoding_of_reserved_characters`.

---

### Finding 15: `wp_remote_post()` cannot send `multipart/form-data` natively, and the obvious implementation breaks both the upload and the signature

**Severity:** major
**Location:** §8.2 request table (L293–298); §8.1 (L283); §8.5 (L355)

**Problem:** §8.2 specifies a `multipart/form-data` request with a `media` part carrying image bytes plus a filename and Content-Type, sent through the WordPress HTTP API (§8, L275, mandates `wp_remote_post()` so `pre_http_request` can intercept). WordPress's HTTP API has **no** multipart file support: passing an array as `body` serializes it with `http_build_query()` and sets `Content-Type: application/x-www-form-urlencoded`. An implementer following §8.2 literally will therefore produce a form-encoded body containing raw (and mangled) binary — which X rejects, and which per §8.1's own rule would then have to be *included in the signature base string*, so the request also fails to authenticate. The document nowhere says that the multipart body must be assembled by hand.

There is a second trap in the same area: `multipart/form-data` is a form content type but is **not** `application/x-www-form-urlencoded`, so §8.1's "only when the body is form-urlencoded" rule correctly excludes it — but the phrasing invites an implementer to conclude the opposite.

**Why it matters:** This is the one endpoint that was actually probed and proven to work; the spec should not leave the transport detail that makes it work unstated. Discovering it in Phase 5 costs a session of debugging OAuth signatures that are not the problem.

**Suggested fix:** Add to §8.2 a normative paragraph: the multipart body is constructed as a raw string with an explicitly generated boundary; `headers['Content-Type']` is set to `multipart/form-data; boundary=…`; the body is passed to `wp_remote_post()` as a string, never an array. Add one sentence to §8.1: "`multipart/form-data` is not `application/x-www-form-urlencoded`; its parts are never signed."

---

### Finding 16: Event-argument matching is by `md5( serialize( $args ) )`, so an int/string mismatch silently clears nothing

**Severity:** major
**Location:** §11.2 (L454–458); T-323 (L717); §13 FR-3.4 (L566)

**Problem:** §11.2 correctly warns that WordPress "matches events by hook **and** arguments", but stops at the shape of the array. The match is `md5( serialize( $args ) )`, which is **type-sensitive**: `array( 123 )` and `array( '123' )` produce different hashes. The paths that schedule and the paths that clear obtain the post id from different places — scheduling from `$post->ID` (always an int), clearing from `$_POST`/`$_GET` in the "Cancel scheduled post" handler and from `wp_trash_post`'s `$post_id` argument (an int, but often passed through `sanitize_text_field()` on the way, yielding a string). A single missing cast leaves a live event behind that later fires against a trashed or unpublished post, and TR-3's "Clear the scheduled event" is a no-op that reports success.

T-323 (`test_clear_uses_identical_argument_array`) will be written with an int on both sides and will pass, because a unit test does not go through the admin request that introduces the string.

**Why it matters:** It is the precise failure §11.2 was written to prevent, one level below where the warning stops, and it is invisible: the meta says `cancelled`, the cron array still holds the event, and the send is only stopped later by FR-4.1's re-read (which happens to save it here — but not for the "Repost now" case, where the stale event silently suppresses the new schedule through the 10-minute duplicate window of Finding 3).

**Suggested fix:** State in §11.2 that the argument array is **always** `array( (int) $post_id )`, at every schedule and every clear site, and add a test that schedules with an int and attempts a clear with the string form to assert the plugin's helper normalizes before calling WordPress.

---

### Finding 17: FR-1.8's log panel cannot be rendered from the schema in §5

**Severity:** major
**Location:** §13 FR-1.8 (L543); §5 schema (L123–145); brief FR-1.8 (brief L93)

**Problem:** The acceptance criterion is "Shows post title, scheduled time, sent time, result, and the X post id or the error; ordered newest first". The table has `post_id`, `provider`, `event`, `http_status`, `remote_id`, `message`, `created_at` — and no scheduled time, no sent time, and no title. The panel can only recover those by reading current post meta and the current post title, which means:

- Rows for a post that has since been deleted show no title and no times (and `before_delete_post` removes the meta while the log rows remain by design).
- Rows for a post that has been reposted (FR-2.5) show the *current* `_srl_sent_at` against every historical row, so the old attempt appears to have been sent at the new time.
- Rows with `post_id = 0` (the FR-1.5 test post, `credentials_unreadable`) have neither.

Separately, "the last 50 **attempts**" and "the last 50 log rows" are different sets: §5's `event` enumeration includes `scheduled`, `cancelled` and `test`, which are not attempts. A busy site that schedules and cancels will fill the 50-row window with non-attempts and hide the failures the panel exists to surface.

**Why it matters:** FR-1.8 is one of the two places the owner ever sees what the plugin did (the other is the per-post meta box). A panel that loses the title and times for exactly the posts most likely to be investigated — deleted, reposted, or failed — does not do its job, and the gap is invisible until there is real history.

**Suggested fix:** Add `post_title varchar(255)` (denormalized snapshot at write time), `scheduled_at datetime NULL` and `sent_at datetime NULL` to the log table, so a row is self-contained; and restate FR-1.8 as "the last 50 log rows, newest first, with a filter for attempt events (`sent`, `failed`, `retry`)".

---

### Finding 18: The compare-and-swap collapses three different outcomes into one silent exit

**Severity:** minor
**Location:** §11.4 (L482–500); §2.1 INV-5 (L50)

**Problem:** I traced whether the `_srl_status` meta row can be absent when this UPDATE runs, since a missing row would make it affect 0 rows and stall the send. On every path specified in this document the row exists: TR-1 writes it before scheduling (FR-3.3), TR-8 and TR-10 write it before rescheduling, and the only path that deletes it (`before_delete_post`) also clears the event. **The row-existence concern is sound as specified** — with the caveat that it depends on the arg-matching fix in Finding 16, since a stale event surviving a permanent delete would fire against a post with no meta at all.

The real defect is what `$claimed === 0` means. Three distinct conditions produce a non-1 result and §11.4 treats them identically as "another process claimed it — exit, log nothing":

1. Another worker claimed it first (correct: exit silently).
2. `$wpdb->query()` returned **`false`** because the query errored (deadlock, table lock timeout, connection loss). `false === 1` is false, so the send is abandoned with no log row, no status change and no retry — the post stays in `scheduled` forever. That is a silent failure INV-5 forbids.
3. Duplicate `_srl_status` meta rows (possible after a plugin-driven duplication or an import) return `2`, which also is not `1`, so a perfectly valid post is never sent.

**Why it matters:** Case 2 turns a transient database error into a permanently stuck post with no evidence anywhere. It is rare per-post and inevitable across a site's lifetime.

**Suggested fix:** Specify the three-way branch: `false` → log a `failed` row with the `$wpdb->last_error` and leave the status as `scheduled` for the reconciliation pass in Finding 3; `0` → verify with a follow-up `get_post_meta()` whether the status is now `sending` (another worker: exit silently) or something else (log it); `>= 2` → log and abort, since duplicate meta is a data defect the owner must see.

---

### Finding 19: No database-version mechanism, so `dbDelta()` never runs on a plugin update

**Severity:** minor
**Location:** §5 (L121); §3 options list (L59–78); §5 notes (L141)

**Problem:** §5 says the table is "Created on activation and on version upgrade via `dbDelta()`", but the document never defines the upgrade trigger. WordPress does **not** fire the activation hook when a plugin is updated in place, so "on version upgrade" needs an explicit stored DB version compared on load — and no such option exists: §3 lists `srl_settings`, `srl_usage`, `srl_cron_last_run`, and `schema_version` *inside* `srl_settings`, which §3.1/§3.2 scope to settings migration, not to the schema. The snippet also uses `{$charset_collate}` without stating that it comes from `$wpdb->get_charset_collate()`, and does not mention that `dbDelta()` requires `require_once ABSPATH . 'wp-admin/includes/upgrade.php'` — which matters because the natural place to run the upgrade check is a front-end request where that file is not loaded.

**Why it matters:** The moment Finding 13's column widening ships as 1.0.1, existing installs will not receive it, and the `credentials_unreadable` row keeps failing on precisely the sites that already have data worth keeping.

**Suggested fix:** Add an option `srl_db_version` (autoload yes) to §3, and specify in §5 that on `plugins_loaded` the stored value is compared with `SRL_DB_VERSION`; on mismatch the plugin loads `upgrade.php`, runs `dbDelta()` with `$wpdb->get_charset_collate()`, and updates the option. Add a test that an upgrade from a table built at version 1 reaches the version-2 shape without data loss.

---

### Finding 20: The cron health panel can show a false green to exactly the sites it is meant to warn

**Severity:** minor
**Location:** §11.3 (L460–474); brief §1.4 (brief L51)

**Problem:** The heartbeat design is right, and the reasoning about a cached `wp-cron.php` is genuinely good. The gap is the observer effect: on a site that has **not** set `DISABLE_WP_CRON` — the misconfigured population the panel exists to detect — loading any wp-admin page calls `wp_cron()`, which spawns a loopback request that runs due events, including `srl_heartbeat`. The owner opens Settings → Social Relay, sees a warning, refreshes, and sees green, because their own page load caused the heartbeat to run. Their delayed posts still stall for hours between visits, which is the actual condition, and the panel now denies it.

A second, smaller point: a one-minute recurring `update_option()` is ~1,440 option writes per day forever, on a shared host, for a diagnostic. That is defensible but should be a stated cost rather than an accident.

**Why it matters:** The panel's entire value is telling an owner the truth about a condition they cannot otherwise observe. A metric that a refresh can turn green teaches the owner to distrust it.

**Suggested fix:** Have the panel report `DISABLE_WP_CRON`'s value alongside the heartbeat, and specify three states rather than two: green only when `DISABLE_WP_CRON` is true **and** the heartbeat is fresh; "unverified — WP-Cron is visitor-triggered, delays will be approximate" when `DISABLE_WP_CRON` is false regardless of heartbeat freshness; warning otherwise. Also state the heartbeat's write cost in §11.3.

---

### Finding 21: FR-1.5's fixed test string will be rejected as a duplicate on the second use

**Severity:** minor
**Location:** §13 FR-1.5 (L540); §9.3 (L391–397); brief FR-1.5 (brief L90)

**Problem:** FR-1.5 posts "a fixed test string", and §9.3 states — correctly, and as a load-bearing part of the INV-1 argument — that "X rejects a post whose text matches one posted recently". The second press of the button, whether five minutes or a week later, therefore returns a duplicate error and the settings page reports a failed connectivity check. The two sections are individually right and jointly wrong.

**Why it matters:** "Send test post" is the owner's only credential check (INSTALLATION step 6) and the mechanism §17 OPEN-5 relies on to settle `/2/tweets` versus `/2/posts`. If retesting after fixing a credential reports failure, the owner has no way to tell a bad key from a duplicate rejection — and the natural next move is to regenerate keys that were fine.

**Suggested fix:** Specify the test string as a fixed prefix plus a UTC timestamp — e.g. `Social Relay connectivity test 2026-09-05 22:49 UTC` — which remains URL-free and cheap, and add a test `test_test_post_text_differs_between_invocations`.

---

### Finding 22: A retry re-uploads the image, doubling media cost and corrupting the counter it is reconciled against

**Severity:** minor
**Location:** §9.1 (L377–381); §8.2 (L311); §12 (L504–524); §4 (L102–113)

**Problem:** A retry restarts the pipeline; there is no `_srl_media_id` in §4, so the image is uploaded again on each attempt. §8.2 records that a media id is valid for 86400 seconds — comfortably longer than the 5/15/60-minute backoff — so the re-upload is avoidable. The cost of media calls is `OQ-1b`-open, so the plugin may be paying three times for one image; and §12's counter, whose entire purpose is reconciliation against the X invoice, will show a media/tweet ratio that neither the owner nor the invoice can explain.

**Why it matters:** Small money, but it lands specifically in the number the owner uses to check whether the plugin's accounting can be trusted.

**Suggested fix:** Add `_srl_media_id` and `_srl_media_uploaded_at` to §4; on a retry, reuse the stored media id when it is less than 86,400 seconds old, and re-upload otherwise. Note the reuse rule in §9.1 so it is not lost in the meta table.

---

### Finding 23: Image handling has no MIME allowlist and no achievable downscale rule

**Severity:** minor
**Location:** §8.2 "Image preparation before upload" (L314–319); §17 OPEN-2 (L805); OQ-9

**Problem:** Three gaps in five steps:

1. **No MIME restriction.** `get_attached_file()` on the featured image returns whatever the attachment is. A site whose featured images are SVG (common with SVG-upload plugins), or WebP, or an animated GIF, will send bytes that `media_category=tweet_image` may reject — and for GIF the correct category is a different one. The spec hard-codes `tweet_image` for everything.
2. **"Downscale" has no target and cannot reliably reduce bytes.** `wp_get_image_editor()->resize()` takes dimensions, not a byte budget, and `set_quality()` only affects JPEG. A 6 MB PNG resized to 1200px wide may still exceed 5 MB, and the spec specifies no iteration, no target dimension, and no re-check of the resulting file size.
3. **The full-size original is the wrong source.** `get_attached_file()` returns the original upload, which on a modern camera-sourced site is routinely 6–12 MB, so the downscale path becomes the *normal* path. WordPress has already generated a `large` intermediate that is under the limit.

**Why it matters:** FR-4.4's fallback means these all degrade to "post without the image" rather than failing the send — but the brief's whole reason for ADR-002 was that the owner asked for the featured image to be posted. Silently omitting it on most posts satisfies the letter of INV-6 and defeats the point.

**Suggested fix:** Specify an allowlist of `image/jpeg`, `image/png`, `image/webp` (and `image/gif` mapped to the GIF media category, or omitted in v1 with a stated reason); specify that the source is `wp_get_attachment_image_src( $id, 'large' )`'s file, falling back to the original; and specify the downscale as a bounded loop — resize to a stated max dimension, re-`filesize()`, and give up after two passes with `_srl_image_omitted_reason = 'too_large'`.

---

### Finding 24: `credentials_unreadable` refuses to schedule but leaves no trace on the post

**Severity:** minor
**Location:** §6.3 (L186–193); §10.1 TR-2 (L425); §13 FR-2.3 (L553)

**Problem:** §6.3 requires the plugin to "refuse to schedule new sends" in this state. What the *post* then looks like is unspecified: no status is assigned, TR-2's "Nothing scheduled, nothing logged" is the closest matching row, and FR-2.3's status line will read "Not scheduled" — identical to a post where the master switch is simply off. The one `credentials_unreadable` log row is written once globally, not per affected post, so a post published during the outage leaves no record anywhere that it was skipped for this reason.

**Why it matters:** The whole point of the ADR-003 mitigation is that the owner learns the true cause. The settings page tells them; the post does not, and the post is where they will look a week later when they wonder why three articles never went out.

**Suggested fix:** Specify that in this state the transition handler sets `_srl_status = failed` with `_srl_last_error = 'credentials_unreadable'`, writes a `failed` log row, and that FR-2.3 renders a distinct status line naming the salt problem and linking to the settings page. Add a test to §16.2 beside T-106.

---

### Finding 25: §16.9's coverage assertion is self-referential and proves nothing

**Severity:** minor
**Location:** §16.9 (L794); T-620, T-621, T-501 (L786, L787, L775)

**Problem:** The stated CI check is that "every `FR-` and every `T-n` identifier in this document appears at least once in the test list". Every `T-n` identifier in the document is *defined* in the test list, so that half of the check passes unconditionally and can never fail. Nothing in the check connects a `T-n` to a test method that exists in the suite, which is the property that actually decays as the spec changes.

Related: three named tests are not unit tests and cannot be written as described. `T-621 test_all_output_is_escaped` cannot be asserted at runtime — escaping is a static property of source code. `T-620 test_no_unprepared_wpdb_query_in_source` is a grep (see Finding 11). `T-501 test_no_error_log_writes_in_normal_operation` requires redirecting `error_log` and asserting emptiness, which will be polluted by any notice from WordPress core or another plugin in the test bootstrap.

**Why it matters:** §16.9 is the mechanism the document nominates to keep itself honest, and as written it is the one check guaranteed to stay green.

**Suggested fix:** Restate the check as: every `FR-x.y` and `TR-n` in §13/§10.1 appears in §16, **and** every `T-n` in §16 corresponds to a method whose name matches the listed one in the test suite (grep the suite, not the spec). Move T-620 and T-621 out of §16 into a "static checks" subsection backed by PHPCS sniffs (`WordPress.Security.EscapeOutput`, `WordPress.DB.PreparedSQL`), and re-scope T-501 to assert that the plugin's own code contains no `error_log(` call outside a debug guard.

---

### Finding 26: §9.3 makes X's undocumented duplicate window the guarantee for INV-1

**Severity:** question
**Location:** §9.3 (L391–397); §9 matrix retry rows (L366–368)

**Problem:** §9.3's argument is that retrying after a 5xx or a timeout is safe because "X's duplicate rejection catches exactly that case" — a lost response for a post that was actually created. That is the right reasoning, and it rests entirely on a behaviour that is neither documented by X nor measured by the Phase 0 probe: the duplicate window's duration is unknown. The 60-minute backoff of §9.1 (once Finding 5 is fixed and it becomes reachable) is a long time to assume a duplicate check still applies. If the window is shorter than the backoff, a retry after a lost response produces a second post — the exact INV-1 violation the plugin exists to prevent, arriving as a success rather than an error.

**Why it matters:** This is the only place where the top invariant depends on an unverified external behaviour rather than on the plugin's own state.

**Suggested fix:** Either add a row to §17 naming this as an accepted residual risk with the mitigation being §9.3's `duplicate_on_retry` notice, or — better — add an item to the Phase 6 acceptance checklist that measures the duplicate window (post, delete, repost the same text at 5, 30 and 90 minutes), and pin the result in §9.3. If the window turns out to be shorter than 60 minutes, cap the backoff at the measured window rather than at an invented number.

---

### Finding 27: §0 claims four deviations from the brief; there are at least ten, and four are not in §17

**Severity:** minor
**Location:** §0 item 1 (L14); §17 (L800–809); §18 (L824); §2.1 INV-3 (L48); §8.2 (L291); §3 (L69–70)

**Problem:** §0 says "Where it disagrees with `PROJECTBRIEF.md`, the brief wins unless this document names the disagreement explicitly… There are four such places, all collected in §17." §17 lists **six**. And at least four further departures from the brief are not in §17 at all:

- **Host allowlist.** Brief §8 requires `api.x.com` **and** `upload.x.com`; INV-3 reduces it to one host. Well justified by OQ-13, but it is a change to a brief MUST and it is the one INV a reviewer will check against the brief.
- **Chunked versus one-shot upload.** Brief §1.3 says "chunked upload is the recommended path"; §8.2 says **MUST NOT** use it. Justified by OQ-14; not listed.
- **Prefix/suffix length.** Brief FR-1.4 says "≤ 60 characters"; §3 makes it 60 *weighted*, so a 40-emoji prefix is now rejected. Small, but it changes an acceptance criterion.
- **PHP floor.** Mentioned only in §18's final line, not in §17.

**Why it matters:** §0's promise is the mechanism by which the owner can trust that nothing was changed silently, and a miscount is the first thing that erodes it. The owner reading §17 at the gate will believe they are seeing every deviation.

**Suggested fix:** Move the four to §17 as OPEN-7..10 (marked "resolved by evidence, listed for visibility"), and change §0 item 1 to "all collected in §17" without a number, so the sentence cannot go stale again.

---

### Finding 28: The `srl1:` key fingerprint is a 32-bit verification oracle, not "nothing usable about the key"

**Severity:** minor
**Location:** §6.2 (L172, L176)

**Problem:** The envelope stores the first 4 bytes of `sodium_crypto_generichash( key, '', 32 )` in the clear, and §6.2 states it "reveals nothing about the credential and nothing usable about the key". The first half is true. The second is overstated: the fingerprint is a cheap (one BLAKE2b) offline verification oracle for a guessed key — it confirms a candidate `wp_salt('auth')` without touching the ciphertext, and it links database backups that share a key. Because `wp_salt()` values are long and random, brute force is infeasible and the design is fine in practice; the absolute claim is what is wrong, and it is exactly the kind of sentence that gets quoted back in a security review.

**Why it matters:** ADR-003 is explicit that this is defence in depth with a stated limit. An overstated claim inside a design whose selling point is honesty about its limits is worth one sentence to fix.

**Suggested fix:** Reword to: "The fingerprint reveals nothing about the credential. It is a 32-bit check value on the derived key, which lets an attacker holding the database test a guessed salt cheaply; since `wp_salt()` is high-entropy this is not a practical weakening, and it buys an accurate diagnosis of salt rotation, which is the trade ADR-003 accepts." Optionally derive the fingerprint from `generichash(key, 'srl-fingerprint')` with a domain-separation personalization so it is not a bare hash of the key.

---

### Finding 29: Hook registration details that decide correctness are unstated

**Severity:** minor
**Location:** §15.1 (L617–625); §13 FR-3.4 (L566); §10.1 TR-3 (L426)

**Problem:** §15.1 lists hooks with no priorities and no `accepted_args`, which matters here more than usual:

- `transition_post_status` passes three arguments in the order `( $new_status, $old_status, $post )`; §13's guard is written as `$new === 'publish' && $old !== 'publish'`, which is right only if the callback signature and `accepted_args = 3` match. The document never states the signature, and reversing the first two parameters produces a plugin that fires on *un*publish and never on publish — a plausible bug that no listed test would obviously catch, because T-303 (`publish_to_publish_schedules_nothing`) also passes under the reversed reading.
- `wp_trash_post` fires *before* the status changes and is redundant here, because trashing already fires `transition_post_status` (`publish` → `trash`) — which the FR-3.4 handler must catch anyway for the unpublish case. §15.1 assigns `transition_post_status` only to "Scheduling entry point", so the hook that actually handles unpublish is never named.
- `before_delete_post` fires for **every** post type and for revisions; without a guard the handler runs on every revision deletion during normal editing.

**Why it matters:** None of these is subtle once written down; all of them are the kind of thing that goes unstated and is then implemented three different ways across three sessions in Phase 5.

**Suggested fix:** Give §15.1 columns for callback signature, priority and `accepted_args`; state that `transition_post_status` serves both TR-1 and TR-3 (unpublish); drop `wp_trash_post` as redundant or state why it is kept; and require a post-type/revision guard on `before_delete_post`.

---

## Areas checked and found sound

Stated explicitly so the owner knows they were examined rather than skipped.

- **The compare-and-swap concept in §11.4.** A single conditional `UPDATE` is the right primitive for the claim, `update_post_meta()` is correctly rejected as non-atomic, and the `wp_cache_delete( $post_id, 'post_meta' )` afterwards uses the correct cache group and is genuinely required. I traced whether the `_srl_status` row can be missing when it runs and found no reachable path in the specified design (Finding 18 covers the residual issue, which is result-handling, not row existence).
- **The weight-range conversion in §7.1.** `4351 = U+10FF`, `8192–8205 = U+2000–U+200D`, `8208–8223 = U+2010–U+201F`, `8242–8247 = U+2032–U+2037` are all correct against the quoted `v3.json`. Only the prose gloss is wrong (Finding 10).
- **The budget arithmetic in §7.3.** `280 − 23 − 1 = 256` and `256 − 60 − 60 − 2 = 134` are correct, the "never truncate the URL" rule is right, and reserving one weighted unit for U+2026 rather than three periods is the right call.
- **The delay bounds in §3.1.** 259,200 s = 72 h, and validating resolved seconds rather than the raw number (so 4321 minutes is rejected) is correct and is the kind of thing usually got wrong.
- **UTC discipline.** `gmdate()` for `created_at`, integer UTC timestamps in meta, site-timezone rendering only at display — consistent everywhere I checked, including the deliberate rejection of `current_time()`.
- **The secret envelope's cryptography.** `sodium_crypto_secretbox` with a fresh `random_bytes` nonce per encryption, a generichash-to-length key derivation rather than truncation or padding, a version byte for future migration, and refusing decryption on a fingerprint mismatch rather than attempting it — all correct. Finding 28 is about one sentence of prose, not the construction.
- **§8.2's response-parsing rules.** `data.id` only, `image.h`/`image.w` versus `height`/`width` treated as absent, and refusing to compare `data.size` against the local file size are three real traps caught by measurement rather than assumption. This is the strongest part of the document.
- **§9.4's refusal to retry an unparseable 2xx.** Correct, and correctly reasoned: preserving INV-1 at the cost of an occasionally pessimistic status is the right trade.
- **§12's honesty about the lossy counter.** Stating the read-modify-write race rather than adding a lock is the right call under the "simple" constraint, and saying so in `README.md` is the right disclosure.
- **INV-2 and FR-3.2.** Always routing through the scheduler, even at delay 0, is correct and keeps one code path. (One edge worth a line in `INSTALLATION.md` rather than a finding: with `ALTERNATE_WP_CRON` defined, cron runs inside a visitor's request, so a send could execute in a human-facing request. The mandated `DISABLE_WP_CRON` + system cron setup makes this moot.)
- **Protected meta.** The `_srl_` prefix does make the keys protected and hidden from the custom-fields UI, and since none is registered with `register_post_meta()`, none is exposed through the REST API. §4's claim is accurate.
- **Multisite.** `$wpdb->postmeta` and `$wpdb->prefix` resolve per site, so §11.4's raw SQL and the log table are correct on a per-site activation. The only residual is that `uninstall.php` cleans the current site only, which is consistent with the brief's exclusion of network activation and does not need a change in v1.
- **Log message truncation** to 2048 bytes on a UTF-8 character boundary, stored in a `text` column: correct, and the boundary requirement is the part usually missed.
- **`transition_post_status` and autosaves/revisions.** Revisions and autosaves are inserted with status `inherit`, so the `$new === 'publish'` guard excludes them without a separate check; T-304 is a sound belt-and-braces test. `wp_publish_post()` (the `future` → `publish` path) does fire the transition, so T-302 is achievable.
