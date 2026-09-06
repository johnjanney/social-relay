# Installing Social Relay

For the person who administers the site. You do not need to be a developer, but you will need to edit one file (`wp-config.php`) and add one scheduled task at your host.

Budget about 30 minutes. Most of it is on X's side, not WordPress's.

---

## How to read the verification marks

Every step carries one. They are here because a setup guide nobody has followed is a guess, and you deserve to know which steps are which.

| Mark | Meaning |
|---|---|
| **[PERFORMED]** | Carried out on a real WordPress site during development. It worked as written. |
| **[PARTLY PERFORMED]** | Carried out, but not in the exact environment you are in. Differences noted. |
| **[NOT PERFORMED]** | Written from official documentation, not carried out. Treat it as a careful guess and tell us if it is wrong. |

The whole guide moves to **[PERFORMED]** after the first real installation. Until then the marks are more useful than a confident tone.

---

## Before you start: this costs money

X ended its free API tier for new developers in February 2026. There is no free option, and **every real post this plugin makes contains a link, which is the expensive case.**

| What | Price per request |
|---|---|
| A post *without* a link | $0.015 |
| **A post *with* a link — every real post this plugin makes** | **$0.200** |
| "Check credentials" button | about $0.010 |
| "Send test post" button | $0.015 (no link, so the cheap rate) |
| Image upload | not published by X; measure it in your Console |

**Work out your bill before you buy anything:**

```
monthly cost  ≈  (posts you publish per month)  ×  $0.20
```

| You publish | You will pay, roughly |
|---|---|
| 5 posts a month | $1 |
| 20 posts a month | $4 |
| 100 posts a month | $20 |
| 500 posts a month | $100 |

Rates read from <https://docs.x.com/x-api/getting-started/pricing> on 2026-09-05. X sets them and can change them. Check the page before you buy.

---

## Step 1 — Create an X developer account and buy credits

**[NOT PERFORMED]** — the account used during development already existed.

1. Go to <https://console.x.com> and sign in **with the X account you want to post to**. This matters: the plugin posts to whichever account owns the API keys.
2. Complete the developer sign-up if you have not before.
3. Buy credits. X's own words: *"Purchase credits upfront, deducted as you use the API. No subscriptions or commitments required."*
4. Buy the smallest amount that covers a month or two, using the table above. You can top up later.

Nothing works until credits are loaded.

---

## Step 2 — Create an App

**[NOT PERFORMED]**

In the Console, click **Create App**. Give it a name, a description and a use case. None of that affects the plugin; pick anything sensible.

**About Projects.** Older documentation says an App must live inside a **Project**. X reworked the Console in February 2026 and its current documentation does not mention Projects at all. So:

- If you see Projects, create one and put the App inside it.
- If you only see "Create App", that is fine — carry on.

Either way, **please tell us which you saw.** It is an open question (OQ-19) and one sentence closes it.

X then shows a batch of credentials and warns that *"Credentials are only displayed once."* **Save all of them somewhere safe now**, even the ones this plugin does not use. Regenerating later is easy but annoying.

---

## Step 3 — Set permissions to Read and Write, then regenerate the token

**[NOT PERFORMED] — and this is the step that most often goes wrong.**

1. Find **User authentication settings** (or App permissions).
2. Set the App to **Read and Write**. The default is read-only, which cannot post.
3. **Now regenerate your Access Token and Access Token Secret.**

Step 3 is not optional and the order is not negotiable. A token's permission level is fixed when the token is created. A token generated *before* you set Read and Write stays read-only forever, and it fails with a `401` that looks exactly like a wrong password — sending you off to check credentials that are perfectly fine.

X says it plainly: *"Changing permissions requires users to re-authorize your app to get new tokens with the updated scope."*

---

## Step 4 — Collect the four keys

**[PARTLY PERFORMED]** — these four values were used successfully against the live X API during development, so the names below are right. The Console screen itself was not walked.

Open the **Keys and tokens** tab. You will see six or more values. You need exactly four:

| The plugin's field | What the Console calls it |
|---|---|
| API Key | API Key, or Consumer Key |
| API Key Secret | API Key Secret, or Consumer Secret |
| Access Token | Access Token |
| Access Token Secret | Access Token Secret |

**Ignore the Bearer Token, the Client ID and the Client Secret.** They belong to a different authentication method this plugin does not use. Pasting a Bearer Token where the Access Token goes is a common mix-up and produces confusing failures.

**A quick check on the Access Token:** it should look like `1234567890-AbCdEf...` — digits, a hyphen, then a long string. If yours has no hyphen, you copied the wrong value.

---

## Step 5 — Install and activate the plugin

**[PERFORMED]** — the distributable zip was installed through WordPress's own plugin installer on a WordPress 6.5 site and activated cleanly.

1. In WordPress, go to **Plugins → Add New → Upload Plugin**.
2. Choose `social-relay-0.1.0.zip` and click **Install Now**.
3. Click **Activate**.

On activation the plugin creates its log table and schedules its two background jobs. Verified on activation: the log table exists, both jobs are scheduled, and **the master switch is off**. An installed-but-unconfigured plugin can never post. That is deliberate.

If your site runs PHP below 8.2 or WordPress below 6.5, the plugin declines to load and says so in a notice rather than breaking your site.

---

## Step 6 — Enter the keys and check them

**[PERFORMED]** — the settings screen, both buttons and the failure paths were exercised on a real site. Only the successful round trip to X was not, because that spends real money on a real account.

1. Go to **Settings → Social Relay**.
2. Paste the four keys into the **X API credentials** fields.
3. Click **Save Changes**.

The keys are encrypted before they are stored. After saving, the fields go blank and show a masked version underneath — correct, not a bug. **Leave a field blank to keep what is stored**; type in a field only when you want to replace that value.

Now check them, cheapest first:

4. **Check credentials** — about $0.010, posts nothing. On success it tells you which X account the keys belong to. Read that carefully and make sure it is the account you intended.
5. **Send test post** — $0.015, and **this does publish to your timeline**. It is the only check that proves the posting path end to end. The message is timestamped, so you can press it more than once without X rejecting it as a duplicate.

If either fails, X's response appears in the **Recent activity** log at the bottom of the page.

---

## Step 7 — Set up real cron

**[PARTLY PERFORMED]** — see the honest note at the end of this step. **Do not skip it.** Without it the delay is not a delay, it is a suggestion.

### Why

WordPress's built-in scheduler only runs when somebody visits your site. On a quiet site, a post scheduled for 60 minutes' time can go out three hours later — whenever the next visitor happens to arrive. Page caching makes it worse, because a cached page never wakes WordPress at all.

The fix is to stop WordPress guessing and let your server keep time.

### 7a. Turn off the built-in trigger

Edit `wp-config.php` in your site's root folder and add this **above** the line that says `/* That's all, stop editing! Happy publishing. */`:

```php
define( 'DISABLE_WP_CRON', true );
```

On Hostinger you can do this in **hPanel → Files → File Manager**, or over SFTP.

### 7b. Add a scheduled task that calls WordPress instead

**Hostinger** (hPanel → Advanced → Cron Jobs):

| Field | Value |
|---|---|
| Command | `wget -q -O /dev/null "https://yoursite.com/wp-cron.php?doing_wp_cron"` |
| Interval | **Every minute** (`* * * * *`) |

Replace `yoursite.com` with your actual domain.

**Every minute, not less.** Hostinger's own WordPress guide suggests starting at *twice an hour*. That is fine for ordinary WordPress housekeeping and **too coarse for this plugin**: at 30-minute intervals a 60-minute delay becomes 60 to 90 minutes, and a delay of zero becomes "sometime within half an hour". If you later find this job set to something slower, that is why it should not be.

**[NOT PERFORMED]** We could not confirm whether Hostinger's dropdown offers every-minute on every plan — their documentation contradicts itself, listing "Every minute" among its presets while using `*/5` in its worked example. **When you set this up, please tell us what the dropdown offered.** It is OQ-18, and it decides one threshold in the health panel below.

Hostinger's plan limits, which are not a problem here: the Single plan allows two cron jobs; Premium and above are unlimited. This plugin needs one.

**cPanel hosts** — Advanced → Cron Jobs, same command, interval `* * * * *`.

**A server you control** — `crontab -e`, then:

```
* * * * * wget -q -O /dev/null "https://yoursite.com/wp-cron.php?doing_wp_cron"
```

**No cron at all?** Use a free external service (cron-job.org, EasyCron) to request that same URL every minute. Less reliable than real cron, far better than nothing.

### An honest note about this step

We verified that `wp-cron.php` responds `200` and sends `Cache-Control: no-cache, must-revalidate, max-age=0`, so a caching layer will not serve it stale — the failure mode we worried about most. We also verified that the plugin's heartbeat runs and turns the health panel green when the scheduler invokes it.

What we could **not** verify is the whole chain: an external HTTP request to `wp-cron.php` causing due jobs to run. In our containerised test environment that request returns `200` and executes nothing, even with an overdue job confirmed ready. We believe that is an artefact of the test setup rather than a problem with WordPress or this plugin — the same job runs correctly when invoked directly — but we did not prove it.

**Step 8 is how you find out for real**, and it is why this step is only partly performed.

---

## Step 8 — Confirm the cron panel

**[PERFORMED]** — all three panel states were exercised on a real site.

Go back to **Settings → Social Relay** and look at **Cron health**. Wait a minute or two after setting up the cron job, then reload.

| What you see | What it means |
|---|---|
| **Green** — "Real cron is running" | Correct. Your delays will be accurate. |
| **Amber** — "WP-Cron is still triggered by visitors" | Step 7a did not take effect. `DISABLE_WP_CRON` is not set. |
| **Red** — "Cron has not run" or "too long ago" | Step 7a worked, step 7b did not. Scheduled posts will not go out. |

**Amber never turns green just by reloading**, and that is deliberate. On a site without `DISABLE_WP_CRON`, opening this page makes WordPress run its own scheduler, so a fresh timestamp would prove nothing about what happens when nobody is looking. A panel you can turn green by pressing F5 is a panel that lies to you.

**Do not continue until this is green.**

---

## Step 9 — Set your delay and switch it on

**[PERFORMED]**

1. **Default delay** — how long to wait after publishing. Default 60 minutes, maximum 72 hours. Zero still routes through the scheduler, so publishing stays fast; expect the post within a minute.
2. **Prefix / Suffix** — optional text before and after the title. Up to 60 characters each, counted the way X counts them, so emoji cost two.
3. **Email on failure** — off by default. Turn it on if you would rather hear about problems by email than notice them in wp-admin.
4. **Enabled** — tick this last. Nothing posts until you do.

Publish something and watch the meta box on the post edit screen. `INSTRUCTIONS.md` explains what it tells you.

---

## Uninstalling

**[PERFORMED]** — a real uninstall was run and the site checked afterwards. Nothing was left behind: no log table, no options, no post meta, no scheduled events.

**Deactivating** stops all scheduled sends and the background jobs. It keeps your settings, your log and your API keys, so you can deactivate to investigate something and switch it back on without losing anything.

**Deleting** the plugin removes everything: settings, encrypted keys, the log table, every scheduled send, and all per-post data.

**Your X credits are not affected.** Manage those in the X Console, which the plugin cannot touch.

On a multisite network, uninstalling removes the plugin's data from every site on the network, so no encrypted credentials are left on a subsite you had forgotten about.

---

## When something is wrong

| Symptom | Likely cause |
|---|---|
| Posts stay "Scheduled" forever | Cron is not running. Go back to step 8. |
| "Failed: auth" | Permissions were not Read and Write, **or** the token was generated before you changed them. Redo step 3, including the regeneration. |
| A notice about **security salts** | Your `wp-config.php` salts changed, which makes the stored keys unreadable. **Your X keys are fine.** Re-enter the four keys in step 6. Do not regenerate them in the Console. |
| "The featured image was not attached" | The image was missing, over 5 MB, or not a JPEG, PNG or WebP. The post still went out with its text and link. |
| "Failed: duplicate" | X rejected the text as one you posted recently. If this happened on a retry, the earlier attempt probably succeeded — **check your timeline before reposting.** |
| Nothing happens on publish at all | Master switch off, per-post box unticked, the post is dated more than 24 hours ago, or the site is mid-import. The **Recent activity** log records skips that could have cost money. |

The **Recent activity** table at the bottom of the settings page is the first place to look. It keeps 90 days.
