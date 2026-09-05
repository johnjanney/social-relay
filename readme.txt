=== Social Relay ===
Contributors: johnjanney
Tags: x, social, auto-post, scheduling
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Posts the title, featured image, and permalink of each newly published post to one X account, after a delay you choose.

== Description ==

Social Relay does one thing. When you publish a post, it waits for the delay you set, then posts the title, the featured image, and the link to your X account.

It posts each post **at most once**. Editing a published post does not post it again. Only the "Repost now" button, which asks for confirmation, will ever produce a second post.

**This costs money.** X ended its free API tier for new developers in February 2026. Every post this plugin makes contains a link, and X charges about $0.20 per post containing a link. Twenty posts a month is roughly $4. Five hundred posts a month is roughly $100. You buy credits up front in the X Developer Console. Read INSTALLATION.md before installing.

**The delay needs real cron.** WordPress's built-in scheduler only runs when someone visits your site, so on a quiet site a 60-minute delay can become three hours. The plugin shows you whether cron is actually running, and the installation guide explains the one configuration change that makes the delay accurate.

= What it does not do =

* Other networks. X only.
* More than one X account.
* Message templates. A prefix and a suffix, nothing more.
* Hashtags, AI captions, link shortening, UTM tags.
* Post types other than posts.
* Analytics or engagement reading.
* Multisite network activation.

== Installation ==

Full instructions, including how to get X API credentials and how to set up real cron, are in INSTALLATION.md in the plugin repository.

In short:

1. Buy credits in the X Developer Console and create an App with Read and Write permissions.
2. Generate the four OAuth 1.0a keys, after setting permissions.
3. Upload and activate the plugin.
4. Paste the four keys into Settings to Social Relay and press "Send test post".
5. Set up real cron as described in INSTALLATION.md, and check the cron health panel shows green.
6. Set your delay and turn the master switch on.

The master switch is off after installation, so nothing posts until you are ready.

== Frequently Asked Questions ==

= Why did my post go out later than the delay I set? =

The delay means "not before", not "at". The plugin publishes at the first cron run at or after the scheduled time. With real cron running every minute the difference is under a minute. Without it, the difference can be hours. The cron health panel on the settings page tells you which situation you are in.

= What happens if the image fails to upload? =

The post still goes out, with the text and the link, and the log records that the image was omitted and why. The image never blocks the post.

= What happens if I change the title during the delay? =

The corrected title is used. The plugin reads the title and the link when it sends, not when it schedules.

= Are my API keys safe? =

They are encrypted before being stored in the database, so a stolen database dump alone does not yield working credentials. This is defence in depth, not a guarantee: anyone with both your database and your files can decrypt them. Treat the four keys as you would a password.

== Changelog ==

See CHANGELOG.md in the repository for the full history.

= 0.1.0 =
* Not yet released. In development.
