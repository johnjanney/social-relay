# Using Social Relay

For the person who writes the posts. You do not need to touch settings — someone has already set this up. This is about the box on your post edit screen and what it is telling you.

If you are setting the plugin up rather than writing with it, you want `INSTALLATION.md`.

---

## The short version

When you publish a post, this plugin waits a while and then posts your title, your featured image and the link to X.

Three things worth knowing before anything else:

1. **Each post goes to X once.** Editing a published post does not post it again. Neither does unpublishing and republishing it. The only way to post the same article twice is the "Repost now" button, and it asks you to confirm.
2. **Every post costs about 20 cents.** Not your money necessarily, but somebody's. Unticking the box before you publish is free; realising afterwards is not.
3. **The delay means "not before", not "at".** More on that below, because it surprises people.

---

## The box on your post edit screen

It looks like this, in the sidebar:

```
Social Relay
  [x] Post to X
  Delay for this post (minutes)  [    ]  Leave blank to use the site default.
  Status: Not scheduled.
```

### Post to X

Ticked by default if the site has the plugin switched on.

**Untick it before you press Publish** if this particular post should not go to X. A short site update, a correction, a page nobody needs to see on social — untick and it costs nothing.

If you untick it *after* publishing but before the delay elapses, that also works: the plugin checks again just before sending and cancels.

### Delay for this post

Leave it blank and you get the site default (usually 60 minutes).

Type a number of minutes to override it just for this post. Useful when something is time-sensitive and you want it out in five minutes, or when you would rather it landed tomorrow morning.

Maximum 72 hours (4320 minutes). Type something larger and the plugin quietly uses the site default rather than a number you did not choose.

---

## What each status means

| Status | What it means | What to do |
|---|---|---|
| **Not scheduled** | Nothing will be sent. Either the box is unticked, the site's master switch is off, the post is not published yet, or it was published before the plugin was set up. | Nothing, unless you want it sent — then "Post to X now", below. |
| **Scheduled for {time}** | Waiting. It will go out at that time or shortly after. | Nothing. You can still cancel. |
| **Sending now** | The send is happening right now. Usually lasts a second or two. | Wait. Refresh in a moment. |
| **Sent {time} — View on X** | Done. The link opens the actual post. | Click through and check it looks right. |
| **Failed: {reason}** | It did not go out. The reason is shown. | See the failure table below. |
| **Cancelled** | It was scheduled and then stopped — by you, or because the post left "Published". | Nothing, unless you want it to go after all — then "Post to X now", below. |

---

## The delay is "not before", never "at"

This is the one that surprises people, so it is worth a moment.

WordPress's scheduler is not a clock. Your post goes out at **the first scheduler run at or after** the time shown.

- **On a properly configured site** — the administrator has set up real cron running every minute — that difference is under a minute. "Scheduled for 14:30" means 14:30, give or take.
- **On a site without that** — the scheduler only runs when somebody visits. On a quiet site, "Scheduled for 14:30" can mean 17:00, or whenever the next visitor turns up.

If your posts consistently go out late, that is not the delay setting. Ask your administrator to check the **Cron health** panel in Settings → Social Relay. If it is not green, that is why, and `INSTALLATION.md` step 7 is the fix.

---

## Cancelling a scheduled post

While the status says **Scheduled**, a **Cancel scheduled post** button appears in the box.

Click it and press Update. The status becomes **Cancelled** and nothing is sent.

This is the button to use when you have published something and then thought better of sharing it, or spotted a mistake you want to fix first. It costs nothing.

**If you cancel and then want it to go after all**, use "Post to X now" — see below.

---

## Reposting

Once a post is **Sent** or **Failed**, a **Repost now** button appears.

It asks you to confirm, because it creates a **second, separately billed post**. It sends immediately rather than waiting for the delay.

Use it when:

- a send failed for a reason that has since been fixed, such as corrected API keys;
- the original went out with a typo you have now fixed and you want the corrected version on the timeline.

**Before you use it after a failure, read the reason.** Two of them mean the post may already be live:

- **`duplicate_on_retry`** — X rejected the text as a duplicate while the plugin was retrying, which usually means an earlier attempt did go through. **Check your timeline first.**
- **`malformed_response`** — X accepted the post but sent back something the plugin could not read. The post is probably there. **Check your timeline first.**

Reposting in either case gives you two posts and two charges.

---

## Posting an older or skipped post

A published post whose status is **Not scheduled** or **Cancelled** has a **Post to X now** button.

It is for the posts the automatic path never touched: everything you published before Social Relay was installed, a post that came in through an import or a bulk edit, one you published with the box unticked and have changed your mind about, or one you cancelled and now want after all.

Click it, confirm, and press Update. It goes out at the next scheduler run rather than after the usual delay, and it is billed like any other post. It ticks the "Post to X" box for you if it was unticked.

It is a first send, so the once-only rule is not affected. The button does not appear on a post that is already **Sent** — for that, and only that, there is "Repost now" — and it does not appear on a draft, because a draft has no public link to send.

---

## What a failure notice means

When a send fails you get a dismissible notice at the top of wp-admin, with a link to the post. One notice per failed post; dismissing it makes it stay dismissed.

| Reason | What happened | What to do |
|---|---|---|
| `auth` | X rejected the credentials. Usually the App is read-only, or the token was created before permissions were changed. | Administrator: redo `INSTALLATION.md` step 3, **including regenerating the token**. |
| `credentials_unreadable` | The site's security salts changed, so the stored keys can no longer be decrypted. **The keys in X are fine.** | Administrator: re-enter the four keys in Settings. Do **not** regenerate them in the X Console. |
| `duplicate` | X refused because you posted the same text recently. | Change the title, or leave it. |
| `duplicate_on_retry` | Same, but on a retry — the earlier attempt probably succeeded. | **Check your timeline before doing anything.** |
| `server` / `rate_limit` | X had a problem or throttled us. The plugin already retried up to three times. | Try "Repost now" later. |
| `transport` | The site could not reach X at all — network or DNS. | Try again; if it persists, tell your administrator. |
| `stalled` | A send started and never finished, usually a server timeout. Not retried automatically, because a stall cannot be told apart from a lost reply. | Check your timeline, then "Repost now" if it is genuinely not there. |
| `event_lost` | The scheduled job disappeared before it ran. Usually another plugin or a migration clearing the schedule. | "Repost now", and tell your administrator. |
| `schedule_failed` | The job could not be scheduled at all. | Tell your administrator; something on the host is blocking WordPress's scheduler. |
| `malformed_response` | X accepted it but replied with something unreadable. | **Check your timeline before reposting.** |

---

## About the featured image

The plugin attaches your featured image to the post on X.

It will be left off, and the post will still go out with its text and link, when:

- there is no featured image;
- the image is not a JPEG, PNG or WebP (SVG and animated GIF are not supported);
- the file is larger than 5 MB;
- the upload fails.

When that happens the box tells you why: *"The featured image was not attached: …"*. The post itself is unaffected — the image never blocks it.

---

## How the title is shortened

X allows 280 characters, and it counts them its own way: most characters count as one, emoji and Chinese, Japanese, Korean and similar scripts count as two, and **any link counts as 23 no matter how long it really is**.

The post is built as:

```
{prefix} {your title} {suffix} {hashtags}
{link to your post}
```

If that is too long, **only the title is shortened**, and it ends with a single `…`. The link is never touched — it is the point of the post.

If your titles are regularly getting cut, shorter titles are the fix. Prefix and suffix, if the site uses them, eat into the same budget.

---

## Hashtags from your tags

Off unless you turn it on, under Settings → Social Relay → Hashtags. With it on, the plugin adds the post's own tags to the end of the post as hashtags. It adds three at most by default; you can change that number or set it to zero.

**Multi-word tags.** A hashtag cannot contain a space, and X stops reading a hashtag at the first space or punctuation mark. So the words are joined together and each one is capitalised:

| Your tag | What is posted |
|---|---|
| `machine learning` | `#MachineLearning` |
| `co-op` | `#CoOp` |
| `rock 'n' roll` | `#RockNRoll` |
| `Web 2.0` | `#Web20` |

**Capitalisation you typed is kept.** A tag you wrote as `iPhone SE` is posted as `#iPhoneSE`, not `#IphoneSe`. The plugin only adds a capital letter to a word you typed entirely in lower case.

**Some tags produce nothing.** A tag made only of numbers, like `2026`, is skipped, because X does not turn an all-number hashtag into a link — it would just be a stray `#` in your post.

**Hashtags never cost you words.** If the post is too long, hashtags are removed one at a time, whole, from the end, until it fits. Your title is only shortened after every hashtag is gone. So on a long title you may see fewer hashtags than you have tags, or none at all — that is the plugin protecting your title, not a fault.

**If a tag reads badly as a hashtag,** rename the tag. There is no per-tag override; the hashtag is always built from the tag name as you typed it.

---

## Reading the log

Settings → Social Relay, at the bottom: **Recent activity**. The last 50 entries, newest first, kept for 90 days.

| Column | What it tells you |
|---|---|
| When | UTC timestamp of the entry |
| Post | The post's title **as it was at the time** — so it stays readable even if the post was later renamed or deleted |
| Event | `scheduled`, `sent`, `failed`, `retry`, `cancelled`, `test` |
| HTTP | X's response code. Blank means the request never reached X at all. |
| Detail | The X post ID, or the error |

A single post typically produces `scheduled` then `sent`. A bumpy one might read `scheduled`, `retry`, `retry`, `sent` — that is the plugin working, not failing.

---

## Appendix: the acceptance checklist

For a tester verifying a new installation, or an administrator confirming something after a change. Work through it on a staging site if you can; every item that posts costs real money.

| # | Do this | Expect |
|---|---|---|
| 1 | Set the default delay to 2 minutes. Publish a post with a featured image. | Status **Scheduled** within seconds. Within ~2 minutes it becomes **Sent** with a working link. The post on X shows the title, the image and the link. |
| 2 | Publish a post, then move it to Trash before the delay elapses. | Status **Cancelled**. Nothing appears on X. |
| 3 | Publish a post with no featured image. | **Sent**. The post on X has text and link, no image. The box notes the image was omitted. |
| 4 | Untick "Post to X", then publish. | Status **Not scheduled**. Nothing sent. No charge. |
| 5 | Enter a per-post delay of 5, publish, and check the scheduled time. | Roughly 5 minutes ahead, not the site default. |
| 6 | Break the API keys deliberately (change one character) and publish. | **Failed** with an admin notice. No retry loop. Restore the keys afterwards. |
| 7 | Publish, wait for **Sent**, then unpublish and republish. | Status stays **Sent**. Nothing is sent again. **This is the duplicate-post guard; it matters most.** |
| 8 | On a **Sent** post, click "Repost now" and confirm. | A second post appears on X. Deliberate, confirmed, billed twice. |
| 8a | On a post published **before the plugin was installed**, click "Post to X now" and confirm. | Status **Scheduled**, then **Sent** at the next scheduler run. One post on X. The "Post to X" box is now ticked. |
| 9 | Check Settings → Social Relay → **Cron health**. | Green. If not, delays are unreliable — see `INSTALLATION.md` step 7. |
| 10 | Compare the **API usage** panel with your X Developer Console for the same month. | Close. The plugin counts every request including failures, because X bills for those too. The Console is authoritative. |

Item 7 is the one to repeat after any upgrade. Everything else being slightly wrong is an inconvenience; posting twice is the failure this plugin is built to prevent.
