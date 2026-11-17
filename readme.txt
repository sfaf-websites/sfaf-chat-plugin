=== SFAF Chat ===
Contributors: sfaf
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.19.0
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

= 1.19.0 =
* Leadership questions ("Who is the CEO?", "Who runs SFAF?", "Who is the director of
  X?") always get the Our Leadership page first, and blog posts are left out of them, so
  an old announcement can't answer them. Board questions get the Board of Directors page
  the same way. That page is now indexed: its address starts with "dev-" and it had been
  skipped as a test page. The Board of Supervisors and the US President are not mistaken
  for ours.
* Questions about any other member of staff are not answered or looked up; the reply
  points to the Main Office, 415-487-3000.
* Job questions list our current openings as our own, linked to the Careers page. A
  Greenhouse job board page added by hand is folded into the Careers page before the model
  sees it, so Greenhouse is never named or linked.
* Answers that give substance-use dosing or safer-use specifics start with "This is
  general harm reduction information, not medical advice." (in Spanish when the answer
  is). It is a label added to the answer, never a reason to hold anything back. General
  health answers don't get it.
* The chat panel and the Ask SFAF launcher have a soft layered drop shadow, so they lift
  off the page. Phones, where the panel is full screen, have none.
* The launcher's question bubble is a rounded speech bubble with a tail and a soft shadow,
  and it moves once, gently, about a second after the page loads. Never with the system's
  reduce-motion setting on.

= 1.18.0 =
* Notices at the top of the chat (closures, alerts) are a filled yellow block with dark
  text and a bold NOTICE label with an icon.
* Every action button in the chat (page links, Donate, Make an appointment, card buttons
  such as Event details, Contact our team, and the contact form's Send) has the animated
  hover: a fill grows from the middle, yellow and dark trade places, and the arrow leaves
  on the right as another comes in on the left. Keyboard focus does the same. The buttons
  are uppercase, bold, 16px, at least 48px tall. Contact our team is now a dark button.
* The Ask SFAF launcher has a new icon: a larger yellow speech bubble with a dark question
  mark that sits across the launcher's top edge, half inside and half above. Position,
  background and label are unchanged.
* Questions about who leads SFAF now ("Who is the CEO?") are answered from the Our
  Leadership page, in the present tense, rather than from the 2022 blog post announcing
  the appointment. Where only a post names the person, the fact is stated plainly, with no
  mention of the post or its date and no link to it.
* The indexer keeps a header or footer that belongs to an article, such as each person
  card's role on Our Leadership, which it used to drop with the page's own header and
  footer. Every indexed page is read again once on the next run.
* "CEO", "executive", "leadership", "leader" and "lead" now find each other when ranking
  pages.
* The Make an appointment button also appears when the answer tells someone to set up one
  of the four visits the chooser covers (express sexual health screening, screening with
  an RN, PrEP enrollment, PrEP follow-up), as in "Can I get PrEP now?". Never for other
  appointment types such as counseling, mental health or a substance use intake, and left
  out when in doubt.

= 1.17.0 =
* The contact form is offered when someone wants to reach a person ("who can I contact",
  "can I talk to someone", "how do I get in touch with the Stonewall team"), even when the
  answer also gives a phone number, which the chat offers beside it as the quickest way.
  Ordinary questions that were fully answered still don't get it.
* The chat knows it can take a message for a team, and never says it can't pass one on
  or connect someone with our staff.
* Requests go to the team the question is about, not the page it was asked on. The
  Contact screen's routes are now Teams: a name, what they handle, recipients, and pages
  as an optional fallback. Anything else goes to the default recipients.
* The sending address, websites@sfaf.org, can never be a recipient: refused when saving,
  dropped when sending. The test email says where it went.
* The team email is a styled HTML email (logo, Arial, our colors) with everything in the
  body: name, email, phone, additional details, team, the page as a plain address, and the
  whole chat. No attachment, and link tracking is off.
* The form has two optional fields: Phone and Additional details.
* The visitor gets a short confirmation email, with nothing they typed in it.

= 1.16.0 =
* The "You are leaving sfaf.org" notice now shows on top. The chat and the appointment
  chooser moved down to ordinary layers (the chat at 1000000 to 1000002, the chooser at
  1000010), still above everything else on the page and below the notice. Escape closes
  the notice first, and the chooser and chat stay open behind it.
* The four healow links in the appointment chooser open in a new tab (target="_blank",
  rel="noopener"). Nothing else in the chooser changed. On www.sfaf.org the leaving-site
  notice handles these links itself, and its Continue button decides how the page opens.
* Answers from our other sites (Tweaker, Cycle to Zero) are in our own voice: the answer
  first, as our staff would give it, then "You can read more on our Tweaker.org page."
  They no longer open with "Tweaker's site explains".

= 1.15.0 =
* Other sites win on their own subject. A question about Cycle to Zero, or about meth and
  Tweaker, gets that site's pages in their own section ahead of ours, so a page of ours
  that only mentions the subject no longer outranks the site. A short follow-up keeps the
  subject. Each site's subjects are listed with its sitemap.
* "Contact our team" is offered less: only when the answer gave no phone number or email,
  and the person seems to want to reach someone or the question was not fully answered.
  The model judges both for each question; the threshold is one setting in the code.
* The assistant no longer uses the name Magnet, which is now part of Strut, and does not
  explain the change unless asked about Magnet.
* Meth questions are answered by intent: Tweaker for what meth is and safer use, the
  Stonewall Project for help cutting down or quitting, both when someone wants options.
* Appointment booking in the chat. The appointment chooser (four Strut visits: express
  sexual health screening, sexual health screening with an RN, PrEP enrollment, PrEP
  follow-up express) now comes with the chat on every page that has it, with nothing to
  paste. When someone wants to book one of those four, the reply has a "Make an
  appointment" button that opens it. Other appointment types never get it.
* The chooser is added exactly as supplied, healow links unchanged, and only when the page
  doesn't already have one. It opens above the chat. Its Geologica and Young Serif fonts
  are loaded from Google Fonts only when a booking button appears and the page doesn't
  have them; otherwise its own fallback fonts apply.
* No "Contact our team" button beside a booking button.

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
