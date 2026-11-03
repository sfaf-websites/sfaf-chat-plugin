=== SFAF Chat ===
Contributors: sfaf
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

The San Francisco AIDS Foundation website assistant.

== Description ==

A conversational assistant for SFAF's websites. Visitors get short answers from
curated, staff-reviewed knowledge, with at most one link per answer. It does not
give individual medical or personal advice, and it hands off to a real person.

* Off by default. Activating the plugin exposes nothing.
* The Gemini API key is entered in wp-admin and stored in the database. It is
  never in the plugin code.
* Logs are de-identified: no IP address, user id or session id. Rows are deleted
  after 60 days.
* Staff can add special instructions and exact, verbatim pinned answers.

== Installation ==

1. Upload the release zip on the Plugins screen and activate it.
2. Go to SFAF Chat > Settings, and enter the API key and the index token.
3. Set the widget switch to admins-only and test on this site's pages.

Later releases appear on the Plugins screen as a normal update. To check at once,
use the Check for updates link in the plugin's row.

== Changelog ==

= 1.0.0 =
* First plugin release. Replaces the standalone files with a WordPress plugin:
  a REST endpoint, a stable widget script URL, a three-way widget switch, wp-admin
  settings, special instructions, pinned answers, a database log, and an admin
  dashboard.
