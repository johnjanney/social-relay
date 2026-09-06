# Code review 1 — Social Relay plugin source

**Reviewed state:** commit `f4fa0aa` plus the uncommitted work in `includes/class-plugin.php` and `includes/class-post-meta.php` (the `handle_action()` / `handle_test_post()` additions). The tree moved three times during this review; line numbers are from that snapshot. Review-only: no source file was modified.

## Overall assessment

This is unusually careful code. The invariant-protecting mechanisms the spec review demanded are genuinely present and genuinely correct: the compare-and-swap in `SRL_Publisher::claim()` is a real atomic CAS with the right strict comparison and the right object-cache invalidation; `SRL_Scheduler::event_args()` really does funnel every schedule and clear through one int cast; the `SCHEDULABLE_FROM` guard really does close unpublish-republish; `wp_schedule_single_event()`'s return value really is checked at every call site; the crypto envelope is a correct XSalsa20-Poly1305 construction with a domain-separated fingerprint checked before decryption. Escaping, nonces and capability checks are clean throughout, and the SQL is all prepared. The failures are not in the places the spec review warned about — they are one layer below, in three recurring shapes. **First, measurement mismatches:** `SRL_Text::truncate()` measures with a different function than `compose()` budgets with, and the result overflows X's 280 limit on ordinary titles (Finding 1, blocker). **Second, branch-ordering mistakes:** the 403 arm of the error matrix runs before the duplicate arm, which makes `duplicate` and `duplicate_on_retry` — the entire safety net that SPEC §9.3 rests INV-1 on — unreachable in production (Finding 2). **Third, requirements that are wired but not terminated:** the failure notice has no dismissal path, three of the four `failed` transitions never raise the notice the spec calls a MUST, FR-4.4's downscaling does not exist, and the "Send test post" handler has no button. Several tests pass for the wrong reason and would not have caught any of this — notably T-470, which was written for exactly the bug in Finding 2 and asserts one level too shallow to see it.

---

## Findings

### Finding 1: `truncate()` measures with literal weights while `compose()` budgets with URL weights, so a truncated title overflows 280

- **Severity:** blocker
- **Location:** `includes/class-text.php:451-499` (`truncate()`), specifically line 477; interacting with `includes/class-text.php:411-429` (`compose()`) and `includes/class-text.php:349` (`weigh_unit()`)
- **Problem:** `compose()` decides the title budget using `weighted_length()`, which segments URLs and bare domains and charges each one 23 (`class-text.php:289-305`, `363-422`). `truncate()` then fills that budget by walking `units()` and calling `weigh_unit()` → `weigh_plain()`, which does **no URL segmentation at all** and charges every character literally. The two functions therefore disagree about the cost of any domain-shaped token that survives the cut, and `compose()` never re-measures the assembled string against `MAX_WEIGHTED`. Reproduced against the shipped code:

  ```
  title = "Comparing WordPress.com and Squarespace.com and Shopify.com and Wix.com
           hosting plans for small business owners in 2026: pricing, performance,
           support quality, migration paths, and hidden costs nobody mentions until
           after you have signed the annual contract"
  permalink = "https://example.com/p/"

  SRL_Text::weighted_length( SRL_Text::compose( $title, $permalink ) )  ===  320
  ```

  320 > 280. `truncate()` kept 254 literal characters — correct by its own accounting — but four of those characters-runs are bare domains that X charges 23 each, adding 40 units the budget never reserved. The effect scales: a title of nothing but domains measured **457**. The same class of bug hits the permalink from the other side — `compose()` hard-codes `URL_WEIGHT` for it (`class-text.php:411`) instead of measuring it, so a permalink whose host `host_is_shortenable()` rejects (a DNS label over 63 octets) is charged 23 while X charges the literal length; that case measured 351.
- **Why it matters:** This is precisely the failure SPEC §7.1.1 and §7.4's "MUST: the algorithm is conservative… overflowing produces an HTTP 400 from X and a `failed` post" exist to prevent, and it fires on the most ordinary content a tech blog produces: a long-ish headline naming two or three products by domain. Because the error matrix classifies a 400 as `ERROR_CLIENT`, it is terminal — no retry, a `failed` post, and a paid API call burned. It is silent until a title happens to be long enough to truncate, so it will look intermittent.
- **Suggested fix:** Make `truncate()` measure the same way `compose()` budgets. Change `weigh_unit()` (line 349) to call `self::weighted_length( $unit )` rather than `weigh_plain()`, and treat a URL/bare-domain segment as one indivisible unit in `units()` so it is never cut in half. Then add the final guard SPEC §7.4 step 1 actually asks for: after assembling `$line . "\n" . $permalink`, assert `weighted_length()` ≤ `MAX_WEIGHTED` and shrink the title budget in a loop until it holds. Add a unit test using the exact title above.

---

### Finding 2: a 403 duplicate-content rejection is classified as `auth`, making `duplicate` and `duplicate_on_retry` unreachable

- **Severity:** major
- **Location:** `includes/providers/class-x-provider.php:207-214`
- **Problem:** `interpret_create()` tests `401 === $status || 403 === $status` and returns `ERROR_AUTH` **before** it tests `body_is_duplicate( $body )`. X's v2 `POST /2/tweets` returns duplicate-content rejections as **HTTP 403** with `"detail":"You are not allowed to create a Tweet with duplicate content."` — the plugin's own test fixture at `tests/integration/PublisherTest.php:270` uses `status( 403, '{"detail":"You are not allowed to create a duplicate status."}' )`, so the author knew the status code. That body never reaches line 211. `ERROR_DUPLICATE` is therefore only producible from a 400 or another 4xx that happens to contain the word "duplicate".
- **Why it matters:** Three things break at once. (a) FR-4.11's `reason = duplicate` is never set. (b) `SRL_Publisher::apply_result()`'s `duplicate_on_retry` branch (`class-publisher.php:299-301`) is dead code, and with it the `SRL_Notices` warning at `class-notices.php:98-101` that tells the owner "this probably *did* go through — check your timeline before reposting". SPEC §9.3 names that warning as the mitigation for the one place INV-1 depends on external behaviour; it now never fires. (c) The owner is instead told the failure reason is `auth`, and `SRL_Notices` sends them to check their credentials — the exact misdiagnosis this codebase repeatedly designs against, on a request where the credentials were fine and a post may be live on their timeline.
- **Suggested fix:** Move the `body_is_duplicate()` check above the 401/403 check, guarded to 4xx only:
  ```php
  if ( $status >= 400 && $status < 500 && self::body_is_duplicate( $body ) ) {
      $result->error_code = SRL_Send_Result::ERROR_DUPLICATE;
      return $result;
  }
  if ( 401 === $status || 403 === $status ) { ... }
  ```
  and tighten `body_is_duplicate()` from `/duplicate/i` to something anchored on X's phrasing, so an unrelated 401 body mentioning the word is not swallowed.

---

### Finding 3: FR-4.4's image downscaling is not implemented, and there is no size guard before the billed upload

- **Severity:** major
- **Location:** `includes/class-publisher.php:205-229` (`resolve_image()`); `includes/providers/class-x-provider.php:237-319` (`upload_media()`)
- **Problem:** Brief FR-4.4 says "Downscale if larger than 5 MB or the X pixel limit." Nothing in the plugin resizes, re-encodes, or even measures an image. `resolve_image()` prefers the `large` intermediate when one exists and otherwise returns `get_attached_file()` — the original — and `upload_media()` reads it with `file_get_contents()` and posts it whatever its size. The code comment at `class-publisher.php:198-201` argues that preferring `large` makes downscaling unnecessary, but that is a heuristic, not a check: `wp_get_attachment_metadata()` has no `sizes.large` entry when the upload is narrower than the `large` threshold (a tall, heavily-detailed PNG or a screenshot at 1000 px wide can easily be 8-15 MB), when the site has disabled intermediate sizes, or when regeneration failed.
- **Why it matters:** An oversized upload is a real API call — billed, counted in `SRL_Usage`, and rejected. The failure is then swallowed as `image_omitted` with reason `http_400`, so the owner sees "the featured image was not attached" with no indication that the cause is file size and no way to fix it from the plugin. It also costs a 30-second timeout and holds the whole file plus its copy in the multipart string in PHP memory (`class-x-provider.php:253` and `:341`), roughly 2× file size, inside a cron request.
- **Suggested fix:** At minimum, add a size guard in `resolve_image()` or at the top of `upload_media()`: if `filesize( $path ) > 5 * MB_IN_BYTES`, skip the call entirely and set `reason = 'image_too_large: N bytes'`. That converts a billed failure into a free, accurately-diagnosed omission in about four lines. Full downscaling via `wp_get_image_editor()` can follow, but the guard should not wait for it.

---

### Finding 4: three of the four paths into `failed` never raise the admin notice, so those failures are silent

- **Severity:** major
- **Location:** `includes/class-scheduler.php:81-86` (`schedule_send()` false branch), `includes/class-scheduler.php:412-414` (stalled) and `:422-427` (event lost)
- **Problem:** SPEC §11.6 MUST 1 states: on a `false` from `wp_schedule_single_event()`, "set `_srl_status = failed`… write a `failed` log row, **raise the FR-5.2 notice (TR-15)**". TR-12 says the same for `stalled` and TR-15 for `event_lost`. All three code paths set the status and write the log row, and none of them calls `SRL_Notices::record_failure()`. Only `SRL_Publisher::apply_result()` (`class-publisher.php:320`) and the unusable-credentials branch (`:67`) do.
- **Why it matters:** These three are precisely the failures nobody is watching for. A `stalled` send and a lost event happen when the site is idle; a `schedule_failed` happens when a cron-control plugin's `pre_schedule_event` filter short-circuits, which is a silent, permanent condition on that host. The post reads "Failed" in a meta box the owner has no reason to open, no notice appears, and no email is sent even with `email_on_failure` on — because the email is sent from `record_failure()` too. INV-5 says a failure is always recorded *and surfaced*; these are recorded and buried.
- **Suggested fix:** Add `SRL_Notices::record_failure( $post_id );` immediately after each of the three `SRL_Post_Meta::fail()` / `set_status( STATUS_FAILED )` calls. Better still, fold the notice into `SRL_Post_Meta::fail()` itself so no future path can forget it.

---

### Finding 5: the failure notice can never be dismissed — there is no dismissal endpoint, no script, and `DISMISS_ACTION` is unused

- **Severity:** major
- **Location:** `includes/class-notices.php:29` (`DISMISS_ACTION`), `:68-71` (`dismiss()`), `:107-112` (render)
- **Problem:** The notice is rendered with `class="… is-dismissible" data-post="%1$d"`, but nothing enqueues a script to read `data-post`, there is no `wp_ajax_srl_dismiss_notice` handler, no `admin_post_` handler, and `SRL_Notices::DISMISS_ACTION` is referenced nowhere outside its own declaration (verified by grep across the tree). Core's `is-dismissible` styling only hides the element for the current page view. `dismiss()` is called from exactly one place: `render()` itself, at `:94`, when the post has been deleted.
- **Why it matters:** FR-5.2 requires "one **dismissible** admin notice per failed post". In practice the notice is permanent: it reappears on every wp-admin page, for every user with `edit_posts` (not just the owner), for up to 50 posts simultaneously (`:47`), and the only way to clear it is to delete the post or hand-edit the `srl_failure_notices` option. Administrators learn to ignore the plugin's notices, which destroys the value of Finding 4's fix as well.
- **Suggested fix:** Add `add_action( 'wp_ajax_srl_dismiss_notice', … )` that checks `check_ajax_referer( SRL_Notices::DISMISS_ACTION )` and `current_user_can( 'edit_post', $post_id )`, then calls `dismiss()`; enqueue a five-line inline script on `admin_enqueue_scripts` that posts `data-post` and the nonce when core fires the notice-dismiss click. Alternatively, drop `is-dismissible` and make the link a nonced `admin_post_` GET — fewer moving parts, and it works without JS.

---

### Finding 6: `reconcile()` can starve — the 20-row batch is filled by the newest, not-yet-due posts

- **Severity:** major
- **Location:** `includes/class-scheduler.php:372-431`
- **Problem:** The `WP_Query` at `:373-390` sets `posts_per_page => 20` and specifies no `orderby`, so WordPress applies its default of `date DESC`. The `meta_query` matches every post in `sending` **or** `scheduled`, including posts that are perfectly healthy and simply not due yet. On a site using a long delay (the setting allows up to 72 hours) that publishes more than twenty posts inside the delay window, those twenty newest, healthy, not-yet-due posts fill the batch on every heartbeat, forever. The older posts behind them — which, being older, are exactly the ones whose events were lost or whose sends stalled — are never examined by any pass. There is no offset, no rotation, and no ordering that favours the stale end. Separately, `post_status => 'any'` excludes statuses flagged `exclude_from_search`, which includes `trash`, so a post trashed while `sending` is invisible to the scan and stays in `sending` permanently.
- **Why it matters:** Reconciliation is the only enforcement of INV-7 and the only thing that resolves TR-12 and TR-15. When it starves, a post sits in `scheduled` or `sending` forever with nothing to move it — the precise "permanently stuck in a non-terminal state" condition SPEC §11.6's last paragraph says must not exist. Nothing in the code or the tests would reveal it: `SchedulerTest::test_lost_event_is_reconciled_to_failed` uses a single post.
- **Suggested fix:** Stop scanning the healthy set. Restrict the query to rows that could actually need action by adding the age condition to the `meta_query` — a `_srl_scheduled_at` / `_srl_sending_since` clause with `compare => '<'` and `type => 'NUMERIC'` against `time() - LOST_EVENT_AFTER` — and add `'orderby' => 'ID', 'order' => 'ASC'` so the oldest unresolved rows are drained first. Add `'trash'` to the status list. Add a test with 25 scheduled posts where the oldest has a lost event.

---

### Finding 7: `SRL_Publisher::run()` overwrites a terminal status with `cancelled`, re-opening the G-5 guard

- **Severity:** major
- **Location:** `includes/class-publisher.php:41-48`
- **Problem:** The first thing `run()` does is write status unconditionally:
  ```php
  if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
      SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_CANCELLED );
  ```
  There is no check that the current status is `scheduled`. `cancelled` is in `SRL_Scheduler::SCHEDULABLE_FROM` (`class-scheduler.php:64`), so this converts a terminal state back into a schedulable one. Every other cancellation path in the plugin is careful about this — `SRL_Scheduler::cancel()` at `:311-319` explicitly refuses to act unless the status is `scheduled`, citing TR-14 — and this path is the one that skipped the check. The reachable sequence: WP-Cron fires the same event in two overlapping workers (the case `claim()` exists for; the `doing_cron` lock expires after `WP_CRON_LOCK_TIMEOUT`, 60 s, and a media upload plus a create can exceed that); worker A claims and is mid-send; the owner trashes or unpublishes the post; worker B reaches line 44, reads `post_status !== 'publish'`, and writes `cancelled` over `sending`. Worker A then completes and writes `sent` — or, if B's write lands second, the post ends as `cancelled` while holding a real `_srl_remote_id`. Untrash-and-republish then passes G-5 and pays for a second post. The same write also fires on a deleted post, creating an orphan `_srl_status` postmeta row for a `post_id` that no longer exists.
- **Why it matters:** It is a direct INV-1 hole, and it defeats the one guard the spec review classified as a blocker. It is also the hardest kind to notice after the fact, because the surviving evidence (`cancelled` status plus a stored remote id) looks like a bug in the log rather than a duplicate charge.
- **Suggested fix:** Gate the write the same way `cancel()` does:
  ```php
  if ( SRL_Post_Meta::STATUS_SCHEDULED === SRL_Post_Meta::get_status( $post_id ) ) {
      SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_CANCELLED );
  }
  ```
  and skip the meta write entirely when `$post` is not a `WP_Post`.

---

### Finding 8: `_srl_attempts` is never reset when a `failed` post is rescheduled, so the retry budget is spent forever

- **Severity:** major
- **Location:** `includes/class-scheduler.php:168-176` (`on_transition()` schedule path); counter incremented at `includes/class-publisher.php:239-240`, gated at `:272`
- **Problem:** `SCHEDULABLE_FROM` deliberately includes `'failed'`, so unpublish-then-republish is a supported way to retry a post that failed. Nothing on that path clears `_srl_attempts`. The new `SRL_Post_Meta::handle_action()` repost path does clear it (`class-post-meta.php:~205`), which shows the intent — the transition path was simply missed. After four failed attempts the meta holds `4`; on the next scheduled send `apply_result()` computes `$attempts = 5`, and `5 <= count( self::BACKOFF )` is false, so the very first retryable failure — a 500, a 429, a timeout — is treated as terminal.
- **Why it matters:** The owner's natural recovery from a transient outage ("X was down; unpublish, republish") produces a post with a retry budget of zero. One flaky request and it fails again, permanently, with a reason that gives no hint why it did not retry. SPEC §9 promises 1 attempt + 3 retries for every send; this path silently promises 1 + 0.
- **Suggested fix:** In `on_transition()`, immediately before `SRL_Post_Meta::set_status( $post_id, STATUS_SCHEDULED )` at `class-scheduler.php:171`, add `delete_post_meta( $post_id, SRL_Post_Meta::META_ATTEMPTS );` and clear `_srl_last_error` / `_srl_image_omitted` alongside it, so the meta box does not show a stale reason for a send that has not happened yet.

---

### Finding 9: in the block editor, the per-post delay override and the per-post switch are ignored on the publishing request

- **Severity:** major
- **Location:** `includes/class-scheduler.php:264-302` (`per_post_enabled()`, `delay_for()`); `includes/class-post-meta.php:95-107` (`request_has_meta_box()`)
- **Problem:** Both readers depend on `request_has_meta_box()`, which requires `$_POST[ NONCE_FIELD ]`. In the block editor — the default editor since WP 5.0 — publishing happens over the REST API (`POST /wp/v2/posts/<id>` with a JSON body), which carries no `$_POST` and no meta-box nonce. `transition_post_status` therefore fires with `request_has_meta_box() === false` and both functions fall through to stored meta. On a **first** publish there is no stored meta, so `delay_for()` returns the site default and `per_post_enabled()` returns the master switch. The meta box's own fields arrive later, in the separate `post.php?meta-box-loader=1` request that Gutenberg sends after the REST save, by which time `schedule_send()` has already run. The comments at `class-scheduler.php:256-262` describe this ordering problem accurately but solve it only for the classic editor's single-request case.

  Two concrete consequences: an author who types `5` in "Delay for this post" and publishes in the block editor gets the site default (60 minutes), not 5. An author on a site whose master switch is off who *checks* "Post to X" and publishes gets nothing scheduled at all — the `post_switch` guard rejects it, the meta lands afterwards, and no later event re-evaluates it. Only the *disable* direction is rescued, by the re-read in `SRL_Publisher::run()` at `class-publisher.php:52-55`.
- **Why it matters:** FR-2.1 and FR-2.2 are the author's only per-post controls and they are, in practice, classic-editor-only. The delay override failing open to a longer delay is merely wrong; the checkbox failing to enable a post is a feature that visibly does nothing, and the author has no feedback that it was ignored.
- **Suggested fix:** Re-evaluate after the meta lands. In `SRL_Post_Meta::save()` (which runs on the meta-box request), after writing `_srl_enabled` and `_srl_delay_override`: if the post is `publish` and `_srl_status` is `scheduled`, recompute the delay from the freshly stored meta and reschedule via `clear_send()` + `schedule_send()`; if `_srl_status` is `none` and the per-post switch was just turned on, run `SRL_Scheduler::guard()` and schedule. Hooking `rest_after_insert_post` is the alternative, but it does not see the meta-box request either, so the meta-box save is the right seam.

---

### Finding 10: the "Cancel scheduled post" and "Repost now" buttons are native form submits with no verified block-editor path and no test at all

- **Severity:** major
- **Location:** `admin/meta-box.php:66-90`; handler at `includes/class-post-meta.php:189-227` (uncommitted)
- **Problem:** Both controls are `<button type="submit" name="srl_action">` placed inside whatever form encloses the meta box, and `handle_action()` reads `$_POST['srl_action']` on `save_post` priority 20. In the classic editor that works. In the block editor the meta-box area lives inside the hidden `#post` form that Gutenberg serialises and sends with `fetch()` to `post.php?meta-box-loader=1`; a native submit-button click inside it triggers a real browser form submission and navigates the page to the raw meta-box-loader response instead of staying in the editor. Whether the action still runs, and what the owner sees afterwards, is unverified — and there is no test that calls `handle_action()` at all. `MetaBoxTest::test_repost_requires_confirmation_and_nonce` (`tests/integration/MetaBoxTest.php:383-393`) only asserts that the rendered HTML contains the nonce field name and the string `confirm(`; it never invokes the handler, never posts without a nonce, and would pass unchanged if `handle_action()` were deleted. SPEC §16 lists `T-242 test_repost_resets_attempts_and_schedules_at_zero_delay`, which does not exist.
- **Why it matters:** FR-2.5 is documented in three places as "the only path to a second post", and FR-2.4 is the author's only way to stop a scheduled paid post once the editor request is over. If either silently fails in the default editor, the plugin's two manual controls do not exist for most users, and nothing in CI would say so.
- **Suggested fix:** Verify both buttons manually in the block editor before shipping; if the native submit misbehaves, move the actions to nonced `admin_post_srl_cancel` / `admin_post_srl_repost` links rendered as `<a class="button">` with `wp_nonce_url()`, which works identically in both editors. Either way add integration tests that set `$_POST['srl_action']`, a valid nonce and an administrator, call `SRL_Post_Meta::handle_action()`, and assert (a) `_srl_attempts` is deleted, (b) status is `scheduled`, (c) an event exists due within seconds, and (d) that the same call with no nonce, or with the status `scheduled`, changes nothing.

---

### Finding 11: guard order means a disabled plugin still writes a log row per post during an import

- **Severity:** minor
- **Location:** `includes/class-scheduler.php:206-228` (guard order) and `:157-160` (which skips are logged)
- **Problem:** `guard()` evaluates `importing`, `bulk_edit` and `stale_post` **before** `master_switch` and `post_switch`, while SPEC §10.3's table orders G-4 ahead of G-6/G-7/G-8. Because `on_transition()` logs every `importing` / `bulk_edit` / `stale_post` skip, a site with the master switch off — an activated-but-unconfigured install, which FR-1.3 makes the default state — writes one `cancelled` log row for every post in a migration. `SRL_Log::write()` also does a `get_post()` per row (`class-log.php:177-182`) to snapshot the title.
- **Why it matters:** Importing 5,000 posts into a site that has the plugin installed but switched off produces 5,000 log rows and 5,000 extra post lookups inside the import loop, and buries any real entry in a 90-day retention window. The rationale for logging those skips — "the owner must be able to find out why nothing posted" — does not apply when the plugin is off, which is exactly the distinction SPEC draws for G-4.
- **Suggested fix:** Move the `master_switch` and `post_switch` checks above the `importing` / `bulk_edit` / `stale_post` checks in `guard()`, matching the spec's G-4-before-G-6 order. The logging condition at `:158` then needs no change.

---

### Finding 12: HTTP 429 ignores `x-rate-limit-reset`

- **Severity:** minor
- **Location:** `includes/providers/class-x-provider.php:199-202`; `includes/class-publisher.php:272-291`
- **Problem:** SPEC §9's matrix says of a 429: "Backoff §9.1. **Honour `x-rate-limit-reset` if it is further out than the backoff.**" The provider reads only the status code; the header is never retrieved, `SRL_Send_Result` has no field to carry it, and `apply_result()` uses `BACKOFF[ $attempts - 1 ]` unconditionally.
- **Why it matters:** If X's reset is 15 minutes out, the plugin retries at 5 and 15 minutes into the same rate limit, burning two of its three retries — and two billed requests — before the window opens, then fails terminally at 60 minutes when it would have succeeded. It converts a recoverable throttle into a `failed` post.
- **Suggested fix:** Add a nullable `?int $retry_after` to `SRL_Send_Result`, populate it in `interpret_create()` from `wp_remote_retrieve_header( $response, 'x-rate-limit-reset' )` (an absolute epoch) or `retry-after` (relative seconds), and in `apply_result()` use `max( $backoff, $retry_after - time() )`, clamped to something sane.

---

### Finding 13: the multipart filename is interpolated into the header without escaping

- **Severity:** minor
- **Location:** `includes/providers/class-x-provider.php:271` and `:339`
- **Problem:** `'filename' => basename( $path )` is written straight into `Content-Disposition: form-data; name="media"; filename="{$file['filename']}"`. A filename containing a double quote, a CR or an LF would terminate the quoted string or inject a header line into the multipart part. A non-ASCII filename is emitted raw, which RFC 7578 does not permit in a quoted `filename` and which X may reject or mis-parse.
- **Why it matters:** Exploitability is low — `sanitize_file_name()` strips quotes and control characters from uploads — but `get_attached_file()` returns whatever path the attachment's `_wp_attached_file` holds, which other plugins, importers and CLI tools set directly. The value is derived from user-supplied data and reaches a protocol boundary unescaped, and the correct fix costs nothing. The non-ASCII case is a plain interoperability bug that will look like "the image is missing on some posts".
- **Suggested fix:** The filename carries no meaning to X's media endpoint. Send a fixed, derived name: `'filename' => 'image.' . ( 'image/jpeg' === $mime ? 'jpg' : ( 'image/png' === $mime ? 'png' : 'webp' ) )`. If a real name is ever wanted, strip everything outside `[A-Za-z0-9._-]` first.

---

### Finding 14: `sanitize()` will re-encrypt an already-encrypted credential if the option is ever updated from admin code

- **Severity:** minor
- **Location:** `includes/class-settings.php:296-307`; registered at `includes/class-plugin.php:107-115`
- **Problem:** `register_setting()` installs `sanitize()` as a `sanitize_option_srl_settings` filter, so it runs on **every** `update_option( 'srl_settings', … )` in an admin request, not only on submissions from `options.php`. The credential loop treats any non-empty value as new plaintext and encrypts it. Passing back a settings array that already contains `srl1:` envelopes therefore double-wraps them: `decrypt()` later returns the inner `srl1:…` string, `credentials_state()` still reports `CRED_OK` because the outer envelope is valid, and the signer sends the envelope text as the consumer key.
- **Why it matters:** No current call site hits this — `install_defaults()` runs during activation, before the setting is registered — so it is latent rather than live. But the failure mode is the worst kind: silent, delayed, and diagnosed as `auth` (a 401), which sends the owner to regenerate keys that were never wrong. Any future migration or "reset settings" routine that reads `all()`, changes one key, and writes it back will trip it.
- **Suggested fix:** Make the loop idempotent: skip re-encryption when the submitted value is already a valid envelope for the current key.
  ```php
  if ( '' === $submitted || SRL_Crypto::STATE_ABSENT !== self::crypto()->inspect( $submitted ) ) {
      continue;
  }
  ```

---

### Finding 15: "Send test post" (FR-1.5) has a handler but no button and no result display; uninstall is single-site only

- **Severity:** minor
- **Location:** `includes/class-plugin.php` (`handle_test_post()`, `redirect_after_test()`, uncommitted); `admin/settings-page.php:1-226`; `uninstall.php`
- **Problem:** Two loose ends. (a) `admin_post_srl_send_test` is registered and the handler is complete and correctly gated (`manage_options` then `check_admin_referer`), but `admin/settings-page.php` renders no button that posts to it and never reads the `srl_test` query argument the handler redirects with — so FR-1.5's "shows the raw API response" surfaces only as a log row. The handler also builds `new SRL_Post_Payload( $title, '' )`, and `SRL_Text::compose()` with an empty permalink returns `$line . "\n" . ''`, i.e. a test post with a trailing newline. (b) `uninstall.php` operates on the current site only: `delete_post_meta_by_key()`, `SRL_Log::drop_table()` (via `$wpdb->prefix`) and the `delete_option()` calls all target one blog, but WordPress runs `uninstall.php` once for a network-wide uninstall. On multisite, every subsite keeps its `wp_N_srl_log` table, its `srl_settings` option containing encrypted credentials, and all `_srl_*` meta.
- **Why it matters:** (a) is an incomplete requirement that will read as a missing feature. (b) means SEC-8 / SPEC §14 ("uninstall must leave nothing behind") is false on multisite, and the leftovers include stored secrets. `UninstallTest` runs single-site so it cannot see it.
- **Suggested fix:** (a) Add the button — `<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">` with `wp_nonce_field( 'srl_send_test' )` and a hidden `action=srl_send_test` — plus a block that renders the `srl_test` outcome, and pass a payload whose permalink is omitted from the composition rather than empty. (b) Wrap the uninstall body in a `get_sites()` / `switch_to_blog()` loop guarded by `is_multisite()`, or declare multisite unsupported in the readme.

---

### Finding 16: several tests pass for the wrong reason and would not catch the bugs above

- **Severity:** major (as a group; the suite is the safety net for everything else in this list)
- **Location:** as cited below
- **Problem:**
  - `tests/integration/CronHealthTest.php:66-80` — `test_unverified_when_wp_cron_is_visitor_triggered` never reaches the `unverified` branch. It adds a filter `srl_test_force_wp_cron_enabled` that **no production code reads**, then asserts `wp_cron_disabled()` is `true` — the opposite of the scenario in the method name — with a message saying the branch is "asserted by inspection". The state that most real sites will actually be in has zero coverage.
  - `tests/integration/CronHealthTest.php:82-86` — `test_stale_threshold_is_a_single_named_constant` asserts `SRL_Cron_Health::STALE_AFTER === 300`, i.e. that a constant equals its own literal. It can never fail for a reason anyone cares about.
  - `tests/integration/PublisherTest.php:269-276` — `test_duplicate_on_first_attempt_fails_with_reason_duplicate` sends a 403 with a duplicate body and asserts only `STATUS_FAILED`. Because `auth` is also terminal, it passes while the reason is wrong. Asserting `_srl_last_error` would have caught Finding 2 the day it was written.
  - `tests/integration/NoticesTest.php:38-46` — `test_notice_dismissal_persists` calls `SRL_Notices::dismiss()` directly. `dismiss()` is not reachable by any user action (Finding 5), so the test certifies a method nobody can invoke.
  - `tests/integration/SchedulerTest.php:215-226` — `test_clear_uses_identical_argument_array` calls `wp_clear_scheduled_hook( HOOK, SRL_Scheduler::event_args( (string) $post_id ) )`. Since `event_args()` casts to int, the two argument arrays are identical by construction; the test exercises no call site and would pass even if a call site elsewhere passed a raw string.
  - `tests/integration/PublisherTest.php:151-158` — `test_double_fired_event_makes_exactly_one_api_call` runs `run()` twice **sequentially**, so the second call is blocked because the status is already `sent`. It does not exercise the concurrent case the CAS exists for, and it would still pass if `claim()` were replaced by a plain `update_post_meta()`.
  - `tests/integration/PublisherTest.php:63-74` — the `intercept()` harness returns `ok_create()` whenever the response queue is empty, so any unanticipated extra request silently succeeds. Only tests that also assert `assertCount()` on `$this->requests` are protected.
- **Why it matters:** Four of the findings above (2, 5, and the two concurrency-shaped ones, 7 and 6) live in code that has a test whose name claims to cover it. A suite that is thorough in appearance and shallow in assertion is worse than a thin one, because it stops anyone looking harder.
- **Suggested fix:** Make `SRL_Cron_Health::wp_cron_disabled()` injectable (read a static override or apply a real filter) so the `unverified` branch can be exercised, and delete the constant-equals-literal test. Add `assertSame( 'duplicate', get_post_meta( …, META_LAST_ERROR, true ) )` to T-470. Replace the sequential double-fire test with one that drives `claim()` from a pre-set `sending` status and asserts `'taken'` plus zero requests. Make `intercept()` fail the test on an unqueued request rather than returning a success.

---

## Areas checked and found sound

- **`SRL_Crypto` (`includes/class-crypto.php`).** Correct use of `sodium_crypto_secretbox` with a fresh `random_bytes()` nonce per encryption, a key derived by hashing (not truncating) `wp_salt()`, `hash_equals()` for the fingerprint compare, a length floor before slicing, a version byte, and strict base64. `inspect()` genuinely distinguishes salt rotation from corruption, which is the whole point of ADR-003. No issues found.
- **`SRL_OAuth1` (`includes/class-oauth1.php`).** `rawurlencode()` rather than `urlencode()`, sort-after-encoding on both key and value, correct signing key construction, correct exclusion of the JSON and multipart bodies from the base string. The RFC 5849 vector test is real.
- **SQL.** Every query is prepared or built from `$wpdb->prefix`. The CAS in `claim()` is a genuine single-statement compare-and-swap with the right strict `1 === $claimed` check, correct handling of `false` (query error) and `>= 2` (duplicate meta rows), and the `wp_cache_delete()` that a direct UPDATE requires. No injection surface found.
- **Escaping and capabilities.** `admin/settings-page.php` escapes every echo, including the attacker-influenceable response body; `status_line()` escapes each interpolated value and is output through `wp_kses_post()`; `esc_js()` is correct for the `onclick` confirm. `render_settings_page()` re-checks `manage_options`; `request_has_meta_box()` checks the nonce **and** `edit_post` for that specific post; `handle_test_post()` checks capability then referer. Secrets are never rendered into an input value, and `masked()` is a genuine mask.
- **Event-argument discipline.** `event_args()` is the single source of the `array( (int) $post_id )` shape, and every `wp_schedule_single_event` / `wp_clear_scheduled_hook` / `wp_next_scheduled` call in the plugin goes through it. The type-mismatch class of bug is closed.
- **Retry budget.** `$attempts <= count( self::BACKOFF )` at `class-publisher.php:272` with a post-incremented counter gives exactly 1 attempt + 3 retries = 4, and makes the 60-minute step reachable. This matches SPEC §9 and FR-4.8. (The separate defect is that the counter is never reset — Finding 8.)
- **Media has no retry tier.** `SRL_X_Provider::send()` records the media failure, sets `image_omitted`, and proceeds to the create call without touching `_srl_attempts` or `_srl_status`. SPEC §9.5 and INV-6 are correctly implemented, and `test_media_failure_does_not_consume_retry_budget` is a good test with a real file on disk.
- **Weighted-length counting itself.** Against `twitter-text`'s own published fixtures the counter is correct, including the weight-one ranges (Thai and Devanagari at 1, CJK at 2), NFC normalization before the code-point walk, the hand-built emoji pattern that avoids both `\p{Extended_Pictographic}` and `\X`'s adjacent-ZWJ merging, and U+2026 measured at 2 rather than assumed at 1. Finding 1 is about `truncate()` using a different measurement, not about `weighted_length()` being wrong.
- **Log schema and pruning.** `varchar(32)` for `event`, denormalised `post_title` / `scheduled_at` / `sent_at` snapshots, `mb_strcut()` for byte-accurate UTF-8 truncation, a bounded `DELETE … LIMIT`, and an explicit `DB_VERSION` option so `dbDelta` runs on in-place updates. The insert's format array matches its column list.
- **Activation ordering.** The `cron_schedules` filter is registered at file scope (`social-relay.php:135`) rather than on `plugins_loaded`, which is required for `wp_schedule_event()` to succeed inside the activation hook, and the activation function verifies the heartbeat afterwards and logs if it is missing. `ActivationTest` covers both. This was fixed in `1b52e2e` during the review.
