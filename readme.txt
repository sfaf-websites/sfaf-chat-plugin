=== SFAF Chat ===
Contributors: sfaf
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.14.0
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

= 1.14.0 =
* Every site is crawled, not just sfaf.org. Each site now has its own lane in the
  indexer's queue and a run takes them in turn, so Tweaker and Cycle to Zero are indexed
  from the first batch instead of waiting behind sfaf.org's pages.
* A sitemap index is followed into every child sitemap for every site, and a child given
  as a relative address is resolved. Tweaker is read from its sitemap_index.xml directly.
* A change to the list of sites starts a new run on the next call, without waiting 12
  hours. Updating to this version does the same once.
* Sitemap problems are kept apart from page errors and shown on their site's row under
  "Last run's fetch problems": a child sitemap that fails, an HTML page where a sitemap
  should be (often a bot check), and a sitemap that lists nothing. A site whose sitemaps
  list nothing keeps the pages it had.
* The Indexing screen says, per site, how many sitemaps the last run read, how many pages
  they listed and how many were queued.
* The per-call time budget now counts sitemap reading too.

= 1.13.0 =
* A Donate button on donation questions, linking to our donation page with the chat's
  tracking tags (utm_source=chat, utm_medium=assistant, utm_campaign=sfaf_chat). The chat
  never handles payment.
* The Dashboard shows the page each question was asked on, in Recent exchanges and in a
  new Questions by page list. Only the page address is kept, never its query string, and
  still no IP address, user or session.

= 1.12.0 =
* "Contact our team". When a question is about a service, a program or getting help, the
  chat offers a button. The visitor gives a name and an email, is told the chat transcript
  will be attached and that someone will reach out within 1 to 2 business days, and the
  request is emailed through Postmark to the team for the page they were on.
* Contact screen: switch, Postmark server token, Reply-To, default recipients, and routes
  by page (one or more recipients each), with a test email button.
* Nothing about a request is stored on the site. Threats and abuse are refused gently and
  not sent.

= 1.11.0 =
* Indexing screen. Staff can add pages no sitemap lists, such as campaign and event pages
  on donate.sfaf.org. Each is fetched daily and tagged with its site; one that stops
  working is dropped from the index and flagged, with a Re-check button. The Santa
  Skivvies page is added to start.
* Pages built in the browser (donate.sfaf.org) are read from their structured data: event
  date and place, registration, and FAQs.
* tweaker.org and Cycle to Zero are crawled too. The assistant names those sites and links
  them, instead of speaking for them as SFAF.

= 1.10.0 =
* Answers state the fact and link the page, without an "Our ... page explains that"
  opening. General answers offer resources plainly ("Our Strut clinic can help") instead
  of "if you need...". The same in Spanish.
* Answers made of several items (closures, hours by location, events, options) are a short
  bulleted list; single answers stay as a sentence or two. The chat shows lists as lists,
  never as raw asterisks or dashes, and Read aloud skips the markers.

= 1.9.0 =
* Welcome & Alerts screen. The chat's welcome message is editable, and the default is now
  "Hi! How can I help you today?".
* Scheduled alerts: short notices with a first day and an optional last day, Pacific. An
  active alert shows at the top of the chat and the assistant is told about it, so its
  answers agree. Alerts clear themselves after their last day.
* Closures from the SFAF Calendar: the assistant knows whether we're closed today or
  tomorrow and which closures are coming up in the next 60 days, with their notes and what
  stays open, and answers "are you open on Thanksgiving?" from them.

= 1.8.0 =
* The assistant now speaks as SFAF, in the first person: "our PrEP & PEP page explains...",
  "we offer", "nuestra página", instead of "SFAF's page" or "according to SFAF". It still
  says which page information comes from, and blog posts keep their own label.

= 1.7.0 =
* General health questions are answered when SFAF's own pages cover them, by relaying what
  the page says and linking it. They are no longer
  declined as personal advice just for being about health or sex.
* Questions about a person's own situation (their exposure, symptoms, results, whether they
  should take something) are still declined warmly and sent to the clinic.
* Nothing is improvised: when the pages don't cover a health question, the assistant says so
  and points to the Strut clinic at 415-581-1600. The same rules apply in every language.

= 1.6.0 =
* Events: when a question matches a program's events, the assistant lists every date this
  week and next week, soonest first, instead of one next date that may be weeks out.
* Beside that list it links the program's own page (for example Programa Latino), found by
  matching the event organizer to the site's pages. With no such page, it links the events
  calendar as before.
* The assistant prefers a program's or place's own page over a broader one that only
  mentions it: "Strut hours" links the Strut page, not Locations & Hours.
* Raw logging now records the answer the visitor was shown as well as the question, and the
  Dashboard shows both under Recent exchanges. Same toggle, same 60-day cleanup, still no
  IP address, user or session.

= 1.5.0 =
* Better matching for pages, blog posts and events. Words are compared by their stem, so
  "voting" meets "vote", and a question also looks for common synonyms ("props" for
  propositions and ballot measures, "Spanish" for español and Latino). Rare, telling words
  now count for more than common ones.
* Blog posts: the excerpt the assistant sees is the part of the post most about the
  question, not its opening, and up to 7 more matching posts are offered by title.
* The index now keeps up to 900 words of each post (was 400). Posts are re-indexed on the
  next indexer run automatically.
* Events: when no event matches the words of a question about events, the assistant gets
  a short list of upcoming events with their calendar labels and judges for itself whether
  one fits. It describes events by their labels and never claims a language the calendar
  doesn't state.
* When a blog post covers the subject, the assistant points to it rather than saying it
  has no details.
* The Ask SFAF button now has a defined edge and a soft shadow, so it stands out on dark
  backgrounds as well as light ones.

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
