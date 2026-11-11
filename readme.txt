=== SFAF Chat ===
Contributors: sfaf
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.4.0
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
3. Turn the widget switch on, then place the script tag on the pages that should show it.
   The plugin adds the widget to no page by itself.

Later releases appear on the Plugins screen as a normal update. To check at once,
use the Check for updates link in the plugin's row.

== Changelog ==

= 1.4.0 =
* Blog posts can now be used in answers, as a lower tier than website pages. Pages stay
  the official source: a post is used only when it adds something the pages don't, and
  the assistant says it is a blog post and points to it rather than restating it.
* Up to 3 matching posts are considered per question, each as a short excerpt, in their
  own section, so they never crowd out a page.
* A linked post shows as a card labeled "Blog post", with a "Read the blog post" button.
* A refusal, crisis reply or personal health reply never links a blog post.

= 1.3.0 =
* Placement decides where the widget appears. It shows only on pages that carry the
  script tag, and the plugin no longer adds it to this site's pages by itself.
* The widget switch is now simply On or Off, a master switch for every page at once.
  Admins-only test mode and the Public confirmation are gone.
* After updating, a site that was in Public is On. A site that was in admins-only test
  mode is Off until someone turns it on.
* Raw question logging now follows its toggle whenever the switch is on. Turn it off
  before the script tag goes on a page real visitors can reach.

= 1.2.0 =
* Live events. The assistant reads upcoming events straight from the SFAF Calendar on
  this site: published only, not cancelled, not private, not over yet, soonest first.
* Questions are matched to the calendar's organizers, series, categories and any other
  event labels, read fresh each time, then to event titles and descriptions. A
  recurring event is offered once, as its next date.
* A matched event comes back as a card with its date, place and a link to its page.
  When nothing clearly matches, the assistant points to the events calendar instead.
* Today, tonight, tomorrow, this week, the weekend and this or next month are worked
  out on the server, never by the model.

= 1.1.1 =
* Brief Gemini failures no longer reach visitors. A 429, a 5xx or a connection error
  is retried up to 3 times on the same model, with short backoff. Other errors are not
  retried, and there is no second model.
* The Model setting is now a dropdown of Flash and Flash-Lite text models, with a
  Check models button that asks Google what the saved key can use.
* The log records the model and the number of attempts for each question.

= 1.1.0 =
* New widget design: an Ask SFAF launcher beside the Weglot selector and a centered
  panel, with the SFAF brand colors and squared corners.
* Suggested questions, chosen by page. Voice input and read aloud, using the browser's
  own speech features.
* New admin screens: Appearance, with a contrast check and a reset, and Suggested
  Questions. Special Instructions has its own screen.

= 1.0.0 =
* First plugin release. Replaces the standalone files with a WordPress plugin:
  a REST endpoint, a stable widget script URL, a three-way widget switch, wp-admin
  settings, special instructions, pinned answers, a database log, and an admin
  dashboard.
