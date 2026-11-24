=== SFAF Chat ===
Contributors: sfaf
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.32.0
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

= 1.32.0 =
* Events are shown by the chat, not written by the model. The model names the events
  that fit by id ("event_ids"); every title, link, day and time comes from the calendar,
  so titles are exactly as entered and each event links to its own calendar page.
* Several events: one short lead line, then a compact list inside the reply: the title
  in bold, linked, and the day and time under it ("Tuesday, December 1, 9–10:30 am"). No
  year, no ordinals, no ":00", the first am/pm left out when both match. No location, no
  bullets. A repeating event shows its next date only.
* One event: a card with the event's image (alt text is the title), the linked title,
  the next day and time with Today or Tomorrow in front when it applies, the place, one
  "Also on December 2, 9 and 16, same time" line for a repeating event, and the program
  it belongs to as a line of text after the card ("Part of our Elizabeth Taylor 50-Plus
  Network.").
* The yellow button: for one event, that event's page, named by its title. For several,
  a page about the topic as a whole when one is found (World AIDS Day), else the events
  calendar from the calendar's own settings. Never the page of one program the events
  aren't all part of (the World AIDS Day list no longer links Aging Services).
* Spanish replies get Spanish days and times; titles stay as entered. Read aloud reads
  the list or the card after the answer: titles, days, times, places, never an address
  or the image's alt text.
* The reply gains "events" and "aloud"; the model's reply schema gains "event_ids".

= 1.31.0 =
* An alert with no last day now shows on its first day only, and ends at midnight
  Pacific. With no first day, it starts the day it is saved. (Before, no last day meant
  until deleted.)
* Alerts saved before 1.31.0 with no last day keep showing until deleted, as before,
  until an administrator presses "Apply the one-day rule to these" on Welcome & Alerts.
  The screen first lists how many there are and what each would become. Nothing is
  deleted, then or by the automatic cleanup.
* The alerts list shows one Dates column in the brand's style: "December 31, 2026" for
  one day, "December 21, 2026 to January 3, 2027" for a range.
* Edit for each alert: the same form with every field filled in. Save changes updates
  that alert in place; Cancel leaves it unchanged.
* Alert text is saved exactly as typed, spaces and line breaks included. Text with HTML
  tags is refused with a message, instead of having the tags removed.

= 1.30.0 =
* One control to minimize. The separate minimize button is gone: the X minimizes to the
  launcher, as it already did, and keeps the conversation. Screen readers now call it
  "Minimize chat". Centered: Read aloud, dock, X. Docked: Read aloud, maximize, X. Esc
  still minimizes; Clear chat is still the only way to wipe. Phones are unchanged.
* Fuller event matching. A question about events now finds every matching upcoming
  event, not just the best few: each event's title, calendar labels, location and
  description are all matched, with the same word stems and synonyms used for pages.
  Events that only mention the subject in their description are kept, listed after
  the title and label matches. Example, "What's happening for World AIDS Day?" on the
  live calendar: 3 events before, 5 now (the CTZ bike ride and A Seat at the Red Table
  were missing).
* A repeating event is one entry at its next date, with the three dates after it. A
  repeating event is the same series and the same title, because the calendar puts
  different events in one series (World AIDS Day holds several).
* Up to 10 events go to the assistant (was 6). With more, it is told how many there are,
  and says there are more and points to the events calendar.
* The assistant lists every event that fits the question, with its date, time and
  location, then mentions a related page if one was found. Event dates still come only
  from the calendar, never from a page.

= 1.29.0 =
* The chat keeps the conversation while a visitor browses the site. Three states:
  centered (the familiar panel over a dimmed page, how it always first opens), docked (a
  smaller window in the bottom-right corner, with no dimming, so the page stays usable),
  and minimized (just the launcher). Centered has minimize, dock and close; docked has
  minimize and maximize. Close and Esc now minimize instead of erasing the conversation.
  The launcher brings back whichever state was last open, with the conversation intact
  and no second welcome.
* The conversation is kept in the browser tab's sessionStorage (never localStorage), so
  it carries from page to page within one visit and disappears when the tab is closed.
  On a new page it comes back quietly: docked if it was docked, otherwise at the
  launcher. The centered panel never pops open by itself. Nothing new is stored on the
  server.
* Clear chat, a small link in the footer that asks for a second press, erases the
  conversation and starts fresh with the welcome, suggested questions and any notice.
* Phones keep the full-screen chat; there is no dock control there.
* Read aloud names the buttons under a reply. After the answer, one closing sentence
  lists them with their labels as shown: "You can also: Donate, Contact our team." In
  Spanish (when the Spanish voice reads), "También puedes:" and the labels as shown;
  that wording waits on a native speaker's review. Nothing is added when a reply has no
  buttons. The booking link sits in the reply's text and is read there, as before. With
  Google's voice the labels are signed with the answer, so the speech route still reads
  only what the chat showed.
* PRIVACY POLICY: while a tab is open, the chat's conversation is kept in that tab's
  session storage on the visitor's own device, and is removed when the tab is closed or
  the visitor presses Clear chat.

= 1.28.0 =
* Dashboard and Report are now one tab, Insights, in the report's look (square, flat,
  SFAF yellow and dark, red for gaps, green for actions, the 9.5 degree accent,
  Montserrat bundled). Nothing was dropped. From the Dashboard it keeps pinned answers,
  crisis handoffs, system errors, questions by topic, page and hour (with its table), the
  unanswered queue, recent exchanges, and the 7 and 60 day ranges (now presets beside
  Last 30 days, Last month and This month). Only duplicates went: the Dashboard's
  Questions, Answered and Unanswered tiles and its personal-advice and off-topic counts,
  which the report already shows as Questions asked, Answered well and "Why".
* One date range and one team filter for everything. With a team chosen, the sections
  from the question log show only the questions sorted to that team.
* Print / Save as PDF prints the report and the log's counts and charts. The unanswered
  queue and recent exchanges (question text) stay on screen only, as do Sort earlier
  questions and Reset.
* Alerts (Welcome & Alerts) can carry one link: a web address and optional link text.
  With text, the link shows as that text; with only an address, as the address; with no
  address, no link. Staff-entered, so it is shown as given, outside the link allowlist
  that governs the assistant's replies; that exception applies to alert links only. It
  must be https://, http://, mailto: or tel:. The assistant is told the alert's text,
  never its link.
* Every key field shows whether the key works: a green square check and "Key saved and
  working", a red square mark and "Key saved, but [service] rejected it", or "No key
  saved". If the service could not be reached, it says that instead. Checked with one
  read-only call (Gemini: the model's details; Postmark: the server's details; Text-to-
  Speech: the voice list), so no text, email or audio is produced. Checked when a key is
  saved, and with the Test link beside each status; the result is cached and tied to the
  key, so a changed key (here or in config.php) is checked again once. The key itself is
  never shown, stored with the result, or sent to the browser.

= 1.27.0 =
* Read aloud can speak with Google Cloud Text-to-Speech voices instead of the browser's.
  It needs its own key (General > Read aloud voice), separate from the Gemini key and
  handled the same way: write-only, not autoloaded, never shown. It can also come from
  tts_api_key in a config.php outside the web root, named in wp-config.php with
  define( 'SFAF_CHAT_CONFIG', '<full path>' ). With no key, Read aloud
  uses the browser voice exactly as before.
* English and Spanish voices are chosen from Google's live list (English US, Spanish US
  and Spain), with name and type shown, Chirp 3: HD by default, and a Preview button.
* Spanish replies use the Spanish voice, other replies the English voice, and replies in
  any other language the browser voice. Long replies are spoken in sentence-sized parts,
  played in order, the next fetched while the current one plays. Any error, timeout or
  refusal falls back to the browser voice without showing anything to the visitor.
* New route /speak, with the same switch, origin and CORS rules as the chat. It speaks
  only replies the chat itself produced and signed (a token in each reply, good for 3
  hours), at most 4,000 characters, and is rate limited: 40 parts per visitor and 400 in
  all per 10 minutes. The visitor is counted by a keyed hash of the IP address, held for
  10 minutes only, never logged and never sent to Google. Nothing is stored and no audio
  is cached.
* PRIVACY POLICY: when the key is set and a visitor turns on Read aloud, the text of the
  chat's replies is sent to Google Cloud Text-to-Speech to be turned into speech. Nothing
  else goes to Google: no IP address, page, session or other identifier.
* The ask reply has a new key, speech (the token, or null without a key).

= 1.25.0 =
* Chat messages come in more slowly (.45 seconds, was .3) with a little more spring: each
  bubble goes a few pixels past its place, swings back slightly and settles. Still none
  with the system's reduce-motion setting.
* Button hovers are slower and gentler: the color swap takes .8 seconds (was .56), the
  sliding fill and arrows 1 second (was .75), and the launcher, header buttons, suggested
  questions, Send and the mic .32 seconds (was .2). All of them now share one soft curve
  that eases in, where the old curves did most of the change at once and read as a snap.
  None with reduce-motion.

= 1.24.0 =
* Report restyled in the SFAF brand: one large lead number (Questions asked) with the
  other three smaller beside it, the two middle sections side by side, flat square bars,
  and the logo's 9.5 degree angle as the one graphic accent. Same data, sections, team
  dropdown and dates. It prints to PDF the same way.
* The report's type is Benton Sans where the computer has it, else Montserrat (the
  brand's web headline face), which now ships with the plugin under the SIL Open Font
  License, so the screen and the PDF look the same everywhere and nothing loads from
  Google.
* A count of 0 in the report draws no bar, just the 0.
* Chat messages come in with a quick fade and slide from their own side and a very slight
  settle (.3 seconds). None with the system's reduce-motion setting.
* "Contact our team" shows an envelope instead of the arrow, so sending a message no
  longer looks like going to a page. Page links, Donate, cards and the booking button keep
  the arrow.

= 1.23.0 =
* New Report screen (SFAF Chat > Report): a monthly insights report for all teams or one
  team, for any date range. It shows what people asked about (questions by team, or by
  subject within a team), where the chat struggled (questions it had no information for,
  answered only in part, or declined, with the reasons), and what people did next.
* Every question is sorted by the model into one of the teams on the Contact screen (or
  General) and a short, broad subject. Subjects already in use are offered back to the
  model, and near-duplicates are merged, so the list stays short.
* Tracked actions: contact form sent, appointment chooser opened, a visit type chosen in
  the chooser (clicked toward booking; booking itself happens on healow and is not
  tracked), Donate, event or calendar links, and phone numbers. Each is credited to the
  subject of the reply it came from, and the report shows how many conversations on a
  subject went on to take an action.
* Print / Save as PDF prints the report alone, with its charts and numbers, for the team
  meeting.
* "Sort earlier questions" puts questions already in the log (those logged with their
  text) into the report, 20 per model call, with progress shown.
* "Reset test data before launch" empties the report, after a tick box and a
  confirmation. The question log is not changed.
* Counts only: the report keeps no question text, names, contact details, pages or
  anything that identifies a visitor or a conversation. Raw question logging is
  unchanged and keeps its own switch.

= 1.22.0 =
* Contact screen: teams are no longer a fixed set of rows. "Add team" adds a row (up to 50
  teams) and "Remove" takes one off; nothing changes until you save. Saved teams are kept.
* A team can be saved with just its name and what it handles, with no recipient yet. Its
  requests go to the default recipients, still under the team's name, so the full list of
  teams can be built now and addresses added later. websites@sfaf.org is still refused as
  a recipient, and the form still needs a valid default recipient to be offered.
* A save the screen refuses now puts what you typed back on the screen (never the Postmark
  token), so a typo doesn't cost the whole list.
* The visitor's contact form needs a name and an email, as before, now with more tests
  behind it on both sides. Phone and additional details stay optional.

= 1.21.0 =
* Message bubbles have a small tail, so they read as speech: on the left for the chat's
  messages (the welcome and every answer), on the right for the visitor's questions.
  Corners stay square. Suggested questions have none.
* After the contact form is sent, the confirmation is a success state: a light green
  tint of the brand green, a green border and a check mark.
* Action buttons (page links, Donate, Make an appointment, card buttons, Contact our team,
  Send) read as buttons before they are touched: a soft drop shadow, and the arrow in a
  small round badge. Their hover swap is about 25% slower, and so are the hovers on the
  launcher, the header buttons, suggested questions and the send button.
* Approved link domains, on the Indexing screen: a web address in a reply is a link only
  on one of these domains, on a site the chat indexes, on a page added by hand, or on the
  booking service. Seeded with sfaf.org, tweaker.org, cycletozero.org, the SFAF Shop on
  Shopify, Greenhouse jobs, donate.sfaf.org and annualreport.sfaf.org. Any other address
  stays plain text. Emails and phone numbers are linked as before.
* A domain on that list can carry a friendly name. The Shopify shop is "SFAF Shop", so a
  link to it says "SFAF Shop" rather than the raw Shopify address.

= 1.20.0 =
* Giving, signing up and volunteering (donating, events, Cycle to Zero and other rides,
  fundraising events, volunteering): a direct, ready-to-act question ("How do I donate?",
  "How do I volunteer?") gets the link or the Donate button and no contact box. Someone who shows interest and has an open question
  (considering it, unsure, asking where the money goes) is offered the contact box so they
  can reach a person, beside the link. The existing reasons to offer it are unchanged.
* Email addresses, web addresses and phone numbers in a reply are working links: emails
  open a new email, phone numbers call, and 988 and 911 call when the reply says to call
  or text them. Web addresses on our own sites (sfaf.org, tweaker.org, cycletozero.org)
  open in a new tab; any other web address stays plain text, so a reply can never link to
  an outside or made-up site.
* The harm reduction label, in English and Spanish, shows as a caution: dark text on a
  light tint of the brand red with a red edge. Styling only: when it is added is unchanged.

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
* The launcher's question bubble is a rounded speech bubble with a tail, a soft shadow and
  a small light glint, resting at a slight tilt. About a second after the page loads it
  waves once (tips right, swings back, settles), and never again on that page. Never with
  the system's reduce-motion setting on.

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
