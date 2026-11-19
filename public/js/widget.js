/**
 * SFAF Chat widget
 * =============================================================================
 * Served by the SFAF Chat plugin on resources.sfaf.org, and loaded on sfaf.org by
 * ONE line in the site footer. This URL has no version in it and never changes:
 *   <script src="https://resources.sfaf.org/wp-json/sfaf-chat/v1/widget.js" defer></script>
 *
 * Self-contained: no dependencies, no CDN, no cookies, no storage. The thread
 * lives only in memory and is cleared when the panel closes.
 *
 * Voice uses the browser's own speech features. This code never touches audio and
 * sends none to SFAF or Gemini. Only the resulting text goes anywhere, and only
 * when the visitor presses Send.
 *
 * All server text is inserted with textContent, never innerHTML, so nothing
 * the proxy returns can inject markup into the host page.
 * =============================================================================
 */
(function () {
  'use strict';

  if (window.__sfafChatLoaded) return;
  window.__sfafChatLoaded = true;

  /* ============================== SETTINGS ================================ */

  var script = document.currentScript;

  // Set by the stable script URL ahead of this script: {proxy, theme}. No nonce and
  // no cookies: every page reaches the endpoint the same way, by origin.
  var CONFIG = window.sfafChatConfig || {};

  // Absolute: the widget runs on sfaf.org but the endpoint is on resources.sfaf.org.
  var PROXY_URL = CONFIG.proxy || 'https://resources.sfaf.org/wp-json/sfaf-chat/v1/ask';
  var STARTERS_URL = PROXY_URL.replace(/\/ask$/, '/starters');
  var CONTACT_URL = PROXY_URL.replace(/\/ask$/, '/contact');
  var TRACK_URL = PROXY_URL.replace(/\/ask$/, '/track');

  // Prior messages sent with each question.
  // KEEP EQUAL TO MAX_HISTORY_TURNS IN includes/class-sfaf-chat-endpoint.php.
  var MAX_HISTORY = 6;

  // Which SFAF sites this bot answers from. v1: "all".
  var SCOPE = (script && script.getAttribute('data-scope')) || 'all';

  var PHONE = '415-487-3000';
  // KEEP ABOVE RETRY_BUDGET_SECONDS IN includes/class-sfaf-chat-endpoint.php, so a
  // retry that is still going on the server isn't abandoned here.
  var REQUEST_TIMEOUT_MS = 50000;

  // Staff set this on the Welcome & Alerts screen; the stable script carries it.
  var GREETING = (typeof CONFIG.greeting === 'string' && CONFIG.greeting.trim())
    ? CONFIG.greeting.trim()
    : 'Hi! How can I help you today?';
  var ERROR_TEXT = "Sorry, I'm having trouble right now. For help, please call SFAF at " +
    PHONE + '.';

  var VOICE_UNSUPPORTED = "This browser can't turn speech into text. Voice input is " +
    'available in most current versions of Chrome, Edge, and Safari. You can still ' +
    'type your question.';
  var VOICE_ERRORS = {
    'not-allowed': 'Microphone access is blocked. You can allow it in your browser settings, or type your question.',
    'service-not-allowed': 'Voice input is turned off in this browser. You can type your question instead.',
    'audio-capture': 'No microphone was found. You can type your question instead.',
    'no-speech': "I didn't catch that. Try the microphone again, or type your question.",
    'network': "Your browser couldn't reach its speech service. You can type your question instead.",
    'language-not-supported': "Voice input doesn't support this language here. You can type your question instead."
  };
  // Shown while the microphone is on. The first is the honest default: browsers such
  // as Chrome send the audio to their own speech service.
  var VOICE_HINT = 'Listening. Your browser turns speech into text and may use its own online speech service.';
  var VOICE_HINT_LOCAL = 'Listening. Your speech is processed on this device.';

  /* ================================ STYLES ================================ */
  /*
   * Every rule is scoped under #sfaf-chat so nothing leaks into, or is easily
   * overridden by, the host theme. These variables are the SFAF brand, and the
   * Appearance screen overrides them through sfafChatConfig.theme.
   *
   * CONTRAST (WCAG AA, checked in real Chrome by .claude/test/browser.cjs): yellow is
   * never text on a light surface, white is never text on yellow, and a focus ring is
   * yellow only on a dark surface.
   *
   * --sfaf-dock-* are worked out by dockLauncher() from the placement and Weglot.
   */
  var CSS = [
    '#sfaf-chat{',
    '  --sfaf-dark:#373433;',
    '  --sfaf-dark-hover:#4b4745;',
    '  --sfaf-accent:#FFD900;',
    '  --sfaf-accent-hover:#ffe64d;',
    '  --sfaf-surface:#ffffff;',
    '  --sfaf-surface-warm:#f4f3f0;',
    '  --sfaf-border:#d1d3d4;',
    '  --sfaf-text:#373433;',
    '  --sfaf-muted:#5f5b58;',
    '  --sfaf-on-dark:#ffffff;',
    '  --sfaf-on-accent:#373433;',
    '  --sfaf-error-bg:#fdf1ee;',
    '  --sfaf-error-border:#d9a79b;',
    // The harm reduction label (1.20.0): a soft tint of the brand red #F04937 behind it,
    // the full red only on its edge, so it reads as a caution without alarm.
    '  --sfaf-caution-bg:#FDE9E7;',
    '  --sfaf-caution-edge:#F04937;',
    // The contact form's "sent" message (1.21.0): a soft tint of the brand green #8CC745,
    // the full green only on its border and check.
    '  --sfaf-success-bg:#F1F8E9;',
    '  --sfaf-success-edge:#8CC745;',
    // Action buttons at rest (1.21.0): the same shadow family as the launcher and panel,
    // so they read as buttons without a hover (phones have none).
    '  --sfaf-btn-shadow:0 1px 2px rgba(0,0,0,.14),0 4px 12px rgba(0,0,0,.16);',
    // Hover swaps (1.21.0): 25% slower than before (.45s to .56s, .6s to .75s, .16s to .2s).
    '  --sfaf-swap:.56s;',
    '  --sfaf-glide:.75s;',
    '  --sfaf-quick:.2s;',
    '  --sfaf-focus:#373433;',
    '  --sfaf-focus-on-dark:#FFD900;',
    '  --sfaf-bot-bg:#ffffff;',
    '  --sfaf-bot-text:#373433;',
    '  --sfaf-user-bg:#373433;',
    '  --sfaf-user-text:#ffffff;',
    '  --sfaf-radius:0px;',
    '  --sfaf-overlay:rgba(55,52,51,.48);',
    // Layered, so the panel lifts off the dimmed page: a close contact shadow, a middle
    // one and a wide soft one (1.19.0). Phones go full screen and drop it.
    '  --sfaf-shadow:0 2px 4px rgba(0,0,0,.14),0 12px 28px rgba(0,0,0,.22),0 32px 80px rgba(0,0,0,.36);',
    '  --sfaf-panel-w:560px;',
    '  --sfaf-panel-h:720px;',
    '  --sfaf-ease:cubic-bezier(.16,1,.3,1);',
    // Geologica is the sfaf.org typeface. Used when the host page has it; never loaded here.
    '  --sfaf-font:Geologica,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;',
    '  font-family:var(--sfaf-font);font-size:15px;line-height:1.5;color:var(--sfaf-text);',
    '}',
    // Reset the bits host themes most often restyle.
    '#sfaf-chat *,#sfaf-chat *::before,#sfaf-chat *::after{box-sizing:border-box;margin:0;padding:0;',
    '  border-radius:0;font-family:inherit;letter-spacing:normal;text-transform:none;text-shadow:none;float:none;}',
    '#sfaf-chat button,#sfaf-chat input{font-size:inherit;line-height:inherit;color:inherit;',
    '  box-shadow:none;min-height:0;width:auto;}',
    '#sfaf-chat a{text-decoration:none;box-shadow:none;}',
    '#sfaf-chat svg{display:block;fill:none;stroke:currentColor;stroke-width:2;',
    '  stroke-linecap:square;stroke-linejoin:miter;}',
    '#sfaf-chat ::selection{background:var(--sfaf-dark);color:var(--sfaf-on-dark);}',
    '#sfaf-chat :focus-visible{outline:3px solid var(--sfaf-focus);outline-offset:2px;}',
    '#sfaf-chat .sfaf-panel:focus{outline:none;}',

    // Launcher: docked beside Weglot, same height, square, flush with the same edge.
    '#sfaf-chat .sfaf-launcher{position:fixed;z-index:1000000;',
    '  right:var(--sfaf-dock-right,40px);left:var(--sfaf-dock-left,auto);bottom:var(--sfaf-dock-bottom,0px);',
    '  height:var(--sfaf-dock-height,39px);border-radius:var(--sfaf-radius);',
    '  display:inline-flex;align-items:center;gap:8px;cursor:pointer;white-space:nowrap;',
    '  background:var(--sfaf-dark);color:var(--sfaf-on-dark);border:0;',
    '  border-left:4px solid var(--sfaf-accent);padding:0 14px 0 47px;font-size:13px;font-weight:600;',
    // Its edge must show on ANY page: a black footer as much as white content. A thin
    // accent ring inside the edge carries it on dark backgrounds, a faint light hairline
    // just outside keeps it even if the accent is set dark, and a soft shadow lifts it
    // off light ones. Shadows, not borders, so the size matched to Weglot is unchanged.
    // It sits on the bottom edge of the window, so the lift (1.19.0) also reaches up
    // and out, where it can be seen.
    '  box-shadow:inset 0 0 0 1px var(--sfaf-accent),0 0 0 1px rgba(255,255,255,.4),0 2px 12px rgba(0,0,0,.28),',
    '    0 -4px 14px rgba(0,0,0,.16),0 8px 28px rgba(0,0,0,.18);',
    '  transition:background var(--sfaf-quick) ease;}',
    '#sfaf-chat .sfaf-launcher:hover{background:var(--sfaf-dark-hover);}',
    // Inset, because the page behind may be white and the bottom edge would clip it.
    '#sfaf-chat .sfaf-launcher:focus-visible{outline:2px solid var(--sfaf-focus-on-dark);outline-offset:-4px;}',
    // The question bubble straddles the top edge: its body half inside, half above.
    // A child of the launcher, so it shares the launcher's layer (above Weglot's 9999)
    // and its click. The dark outline and shadow keep it visible on a white page.
    // Its one motion plays once on load; JS ends it (sfaf-qmark-done) so reopening the
    // launcher doesn't replay it, and reduced motion turns it off with the rest.
    '#sfaf-chat .sfaf-launcher .sfaf-qmark{position:absolute;left:7px;top:-12px;width:32px;height:32px;overflow:visible;',
    '  transition:transform .2s var(--sfaf-ease);}',
    '#sfaf-chat .sfaf-launcher .sfaf-qmark.sfaf-qmark-done{animation:none;}',
    '#sfaf-chat .sfaf-qmark .sfaf-qmark-b{fill:var(--sfaf-accent);stroke:var(--sfaf-dark);stroke-linejoin:round;}',
    '#sfaf-chat .sfaf-qmark .sfaf-qmark-q{fill:none;stroke:var(--sfaf-dark);stroke-width:2.6;stroke-linecap:round;stroke-linejoin:round;}',
    '#sfaf-chat .sfaf-qmark .sfaf-qmark-d{fill:var(--sfaf-dark);stroke:none;}',
    // BUBBLE:START variant C, "Tilted": rests at a slight tilt with a light glint, waves once.
    '#sfaf-chat .sfaf-launcher .sfaf-qmark{filter:drop-shadow(0 1px 1px rgba(0,0,0,.28)) drop-shadow(0 4px 7px rgba(0,0,0,.22));',
    '  transform-origin:30% 90%;transform:rotate(-8deg);animation:sfaf-q-wave .9s var(--sfaf-ease) .7s 1 both;}',
    '#sfaf-chat .sfaf-launcher:hover .sfaf-qmark,#sfaf-chat .sfaf-launcher:focus-visible .sfaf-qmark{transform:rotate(-8deg) translateY(-2px);}',
    '#sfaf-chat .sfaf-qmark .sfaf-qmark-b{stroke-width:1.75;}',
    '#sfaf-chat .sfaf-qmark .sfaf-qmark-g{fill:none;stroke:#fff;stroke-opacity:.75;stroke-width:1.6;stroke-linecap:round;}',
    '@keyframes sfaf-q-wave{0%,100%{transform:rotate(-8deg);}25%{transform:rotate(7deg) translateY(-3px);}50%{transform:rotate(-14deg);}72%{transform:rotate(-4deg);}}',
    // BUBBLE:END
    '#sfaf-chat.sfaf-open .sfaf-launcher{display:none;}',

    // Overlay
    '#sfaf-chat .sfaf-overlay{position:fixed;inset:0;z-index:1000001;background:var(--sfaf-overlay);',
    '  opacity:0;visibility:hidden;pointer-events:none;transition:opacity .22s ease,visibility 0s linear .22s;}',
    '#sfaf-chat.sfaf-open .sfaf-overlay{opacity:1;visibility:visible;pointer-events:auto;transition:opacity .3s ease,visibility 0s;}',

    // Panel: fixed with auto margins centers it without a transform, so text stays crisp
    // once the slide has finished.
    '#sfaf-chat .sfaf-panel{position:fixed;inset:0;margin:auto;z-index:1000002;',
    '  width:min(var(--sfaf-panel-w),calc(100vw - 48px));height:min(var(--sfaf-panel-h),calc(100vh - 64px));',
    '  display:flex;flex-direction:column;overflow:hidden;background:var(--sfaf-surface);',
    '  border-radius:var(--sfaf-radius);box-shadow:var(--sfaf-shadow);',
    '  opacity:0;visibility:hidden;pointer-events:none;transform:translateY(32px);',
    '  transition:transform .2s ease-in,opacity .2s ease-in,visibility 0s linear .2s;}',
    '#sfaf-chat.sfaf-open .sfaf-panel{opacity:1;visibility:visible;pointer-events:auto;transform:none;',
    '  transition:transform .46s var(--sfaf-ease),opacity .28s ease-out,visibility 0s;}',

    '#sfaf-chat .sfaf-header{flex:none;display:flex;align-items:center;gap:6px;background:var(--sfaf-dark);',
    '  color:var(--sfaf-on-dark);border-top:4px solid var(--sfaf-accent);padding:13px 12px 13px 24px;}',
    '#sfaf-chat .sfaf-title{flex:1;min-width:0;font-size:18px;line-height:1.3;font-weight:600;color:var(--sfaf-on-dark);}',
    '#sfaf-chat .sfaf-header button{display:inline-flex;align-items:center;gap:7px;height:36px;cursor:pointer;',
    '  border-radius:var(--sfaf-radius);',
    '  background:transparent;color:var(--sfaf-on-dark);border:1px solid transparent;font-size:13px;font-weight:500;',
    '  transition:background var(--sfaf-quick) ease,color var(--sfaf-quick) ease,border-color var(--sfaf-quick) ease;}',
    '#sfaf-chat .sfaf-header button:hover{background:var(--sfaf-dark-hover);}',
    '#sfaf-chat .sfaf-header button:focus-visible{outline-color:var(--sfaf-focus-on-dark);outline-offset:0;}',
    '#sfaf-chat .sfaf-header svg{width:18px;height:18px;flex:none;}',
    '#sfaf-chat .sfaf-header .sfaf-close{width:36px;justify-content:center;}',
    '#sfaf-chat .sfaf-header .sfaf-read{padding:0 11px 0 9px;border-color:rgba(255,255,255,.34);}',
    '#sfaf-chat .sfaf-header .sfaf-read[aria-pressed="true"],#sfaf-chat .sfaf-header .sfaf-read[aria-pressed="true"]:hover{',
    '  background:var(--sfaf-surface);color:var(--sfaf-text);border-color:var(--sfaf-surface);}',

    '#sfaf-chat .sfaf-messages{flex:1;min-height:0;overflow-y:auto;background:var(--sfaf-surface-warm);',
    '  padding:24px;display:flex;flex-direction:column;gap:12px;overscroll-behavior:contain;',
    '  scrollbar-width:thin;scrollbar-color:#b7b4b0 transparent;}',
    // Without this, overflow:hidden children (cards) shrink to nothing once the log scrolls.
    '#sfaf-chat .sfaf-messages>*{flex-shrink:0;}',

    // Bubbles
    '#sfaf-chat .sfaf-msg{max-width:84%;padding:12px 16px;white-space:pre-wrap;overflow-wrap:anywhere;',
    '  border-radius:var(--sfaf-radius);',
    '  animation:sfaf-rise .34s var(--sfaf-ease) both;}',
    '@keyframes sfaf-rise{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:none;}}',
    '#sfaf-chat .sfaf-msg .sfaf-p{margin:0;}',
    // A fixed text color, like the error, so it stays readable whatever the bubble colors are.
    '#sfaf-chat .sfaf-msg .sfaf-p.sfaf-caution{background:var(--sfaf-caution-bg);border-left:3px solid var(--sfaf-caution-edge);',
    '  color:#373433;padding:6px 10px;font-weight:600;}',
    '#sfaf-chat .sfaf-msg .sfaf-p+.sfaf-p,#sfaf-chat .sfaf-msg .sfaf-list+.sfaf-p{margin-top:8px;}',
    '#sfaf-chat .sfaf-msg .sfaf-list{margin:6px 0 0;padding-left:20px;white-space:normal;}',
    '#sfaf-chat .sfaf-msg .sfaf-list li{margin:0;}',
    '#sfaf-chat .sfaf-msg .sfaf-list li+li{margin-top:3px;}',
    '#sfaf-chat .sfaf-msg strong{font-weight:600;}',
    '#sfaf-chat .sfaf-bot{align-self:flex-start;background:var(--sfaf-bot-bg);color:var(--sfaf-bot-text);',
    '  border:1px solid var(--sfaf-border);}',
    '#sfaf-chat .sfaf-user{align-self:flex-end;background:var(--sfaf-user-bg);color:var(--sfaf-user-text);}',
    '#sfaf-chat .sfaf-user::selection{background:var(--sfaf-surface);color:var(--sfaf-text);}',
    /*
     * Tails (1.21.0): a small notch off the bottom edge, so a bubble reads as speech.
     * The chat's on the left, the visitor's on the right; the corners stay square, and
     * starters, notes, alerts and cards have none. The chat's bubble has a border, so its
     * tail is two triangles: the border color, then the fill 1px inside it. A bubble with
     * other colors (error, sent) sets --sfaf-tail-fill and --sfaf-tail-edge.
     */
    '#sfaf-chat .sfaf-msg{position:relative;}',
    '#sfaf-chat .sfaf-bot::before,#sfaf-chat .sfaf-bot::after,#sfaf-chat .sfaf-user::after{content:"";position:absolute;',
    '  width:0;height:0;border-style:solid;pointer-events:none;}',
    '#sfaf-chat .sfaf-bot::before{left:-10px;bottom:9px;border-width:6px 10px 6px 0;',
    '  border-color:transparent var(--sfaf-tail-edge,var(--sfaf-border)) transparent transparent;}',
    '#sfaf-chat .sfaf-bot::after{left:-8px;bottom:10px;border-width:5px 9px 5px 0;',
    '  border-color:transparent var(--sfaf-tail-fill,var(--sfaf-bot-bg)) transparent transparent;}',
    '#sfaf-chat .sfaf-user::after{right:-9px;bottom:9px;border-width:6px 0 6px 9px;',
    '  border-color:transparent transparent transparent var(--sfaf-user-bg);}',
    // A fixed pair, so the error stays readable whatever the bubble colors are.
    '#sfaf-chat .sfaf-error{background:var(--sfaf-error-bg);border-color:var(--sfaf-error-border);color:#373433;',
    '  --sfaf-tail-fill:var(--sfaf-error-bg);--sfaf-tail-edge:var(--sfaf-error-border);}',
    // The contact form went: a success state, distinct from any other bubble (1.21.0).
    '#sfaf-chat .sfaf-success{background:var(--sfaf-success-bg);border-color:var(--sfaf-success-edge);color:#373433;',
    '  display:flex;align-items:flex-start;gap:10px;',
    '  --sfaf-tail-fill:var(--sfaf-success-bg);--sfaf-tail-edge:var(--sfaf-success-edge);}',
    '#sfaf-chat .sfaf-success-icon{flex:none;width:20px;height:20px;margin-top:1px;}',
    '#sfaf-chat .sfaf-success-icon .sfaf-success-disc{fill:var(--sfaf-success-edge);stroke:none;}',
    '#sfaf-chat .sfaf-success-icon .sfaf-success-tick{fill:none;stroke:#373433;stroke-width:2.4;}',
    '#sfaf-chat .sfaf-success-text{min-width:0;}',
    '#sfaf-chat .sfaf-msg a.sfaf-tel{color:inherit;text-decoration:underline;',
    '  text-underline-offset:2px;font-weight:600;}',
    '#sfaf-chat a.sfaf-tel{white-space:nowrap;}',
    // A long address must wrap inside the bubble, never push it wider (1.20.0).
    '#sfaf-chat a.sfaf-url,#sfaf-chat a.sfaf-mail{white-space:normal;overflow-wrap:anywhere;}',
    '#sfaf-chat .sfaf-msg a.sfaf-appt-link{margin:6px 0 2px;white-space:nowrap;}',

    /*
     * Every action button in the conversation (link, Donate, booking, card, contact,
     * Send): a fill grows from the middle on hover or keyboard focus, the colors trade
     * (yellow and dark), and the arrow leaves on the right as another comes in on the
     * left. markFlow() adds the fill, the text and the two arrows. Pixels, not rem:
     * the host page sets the root font size.
     */
    '#sfaf-chat .sfaf-flow{--sfaf-flow-bg:var(--sfaf-accent);--sfaf-flow-ink:var(--sfaf-on-accent);',
    '  --sfaf-flow-fill:var(--sfaf-dark);--sfaf-flow-ink-on:var(--sfaf-accent);',
    '  position:relative;isolation:isolate;overflow:hidden;display:inline-flex;align-items:center;',
    '  min-height:48px;padding:16px 56px 16px 24px;border:0;border-radius:var(--sfaf-radius);cursor:pointer;',
    '  background:var(--sfaf-flow-bg);color:var(--sfaf-flow-ink);box-shadow:var(--sfaf-btn-shadow);text-decoration:none;text-align:left;',
    '  font-family:var(--sfaf-font);font-weight:700;font-size:16px;line-height:16px;letter-spacing:.8px;',
    '  text-transform:uppercase;transition:color var(--sfaf-swap) ease;}',
    '#sfaf-chat .sfaf-flow-dark{--sfaf-flow-bg:var(--sfaf-dark);--sfaf-flow-ink:var(--sfaf-on-dark);',
    '  --sfaf-flow-fill:var(--sfaf-accent);--sfaf-flow-ink-on:var(--sfaf-on-accent);}',
    '#sfaf-chat .sfaf-flow-disc{position:absolute;left:50%;top:50%;z-index:-1;width:24px;height:24px;',
    '  margin:-12px 0 0 -12px;border-radius:50%;background:var(--sfaf-flow-fill);transform:scale(0);',
    '  transition:transform var(--sfaf-glide) cubic-bezier(.23,1,.32,1);pointer-events:none;}',
    // inherit: the reset above sets letter-spacing and text-transform on every element.
    '#sfaf-chat .sfaf-flow-text{position:relative;letter-spacing:inherit;text-transform:inherit;',
    '  transition:transform var(--sfaf-glide) cubic-bezier(.23,1,.32,1);}',
    // The arrow sits in a small round badge (1.21.0) in the text color, the arrow in the
    // button color, so the button says "go" at rest. The badge swaps with the button.
    '#sfaf-chat .sfaf-flow .sfaf-flow-a{position:absolute;top:50%;width:26px;height:26px;margin-top:-13px;padding:5px;',
    '  border-radius:50%;background:var(--sfaf-flow-ink);color:var(--sfaf-flow-bg);stroke-width:2.4;',
    '  transition:left var(--sfaf-glide) cubic-bezier(.23,1,.32,1),right var(--sfaf-glide) cubic-bezier(.23,1,.32,1),',
    '    background var(--sfaf-swap) ease,color var(--sfaf-swap) ease;}',
    '#sfaf-chat .sfaf-flow .sfaf-flow-in{left:-25%;}',
    '#sfaf-chat .sfaf-flow .sfaf-flow-out{right:18px;}',
    '#sfaf-chat .sfaf-flow:not([disabled]):hover .sfaf-flow-a,#sfaf-chat .sfaf-flow:not([disabled]):focus-visible .sfaf-flow-a{',
    '  background:var(--sfaf-flow-ink-on);color:var(--sfaf-flow-fill);}',
    '#sfaf-chat .sfaf-flow:not([disabled]):hover,#sfaf-chat .sfaf-flow:not([disabled]):focus-visible{color:var(--sfaf-flow-ink-on);}',
    '#sfaf-chat .sfaf-flow:not([disabled]):hover .sfaf-flow-disc,#sfaf-chat .sfaf-flow:not([disabled]):focus-visible .sfaf-flow-disc{transform:scale(32);}',
    '#sfaf-chat .sfaf-flow:not([disabled]):hover .sfaf-flow-text,#sfaf-chat .sfaf-flow:not([disabled]):focus-visible .sfaf-flow-text{transform:translateX(32px);}',
    '#sfaf-chat .sfaf-flow:not([disabled]):hover .sfaf-flow-in,#sfaf-chat .sfaf-flow:not([disabled]):focus-visible .sfaf-flow-in{left:24px;}',
    '#sfaf-chat .sfaf-flow:not([disabled]):hover .sfaf-flow-out,#sfaf-chat .sfaf-flow:not([disabled]):focus-visible .sfaf-flow-out{right:-30%;}',

    // A note from the widget itself, such as a voice message. Not part of the conversation.
    '#sfaf-chat .sfaf-note{align-self:stretch;font-size:13.5px;color:var(--sfaf-muted);padding:2px 0 2px 12px;',
    '  border-left:2px solid var(--sfaf-border);animation:sfaf-rise .34s var(--sfaf-ease) both;}',

    // Staff alerts, at the top of the conversation while they are active.
    // A filled yellow block, so a closure reads first when the chat opens.
    '#sfaf-chat .sfaf-alert{align-self:stretch;background:var(--sfaf-accent);color:var(--sfaf-on-accent);',
    '  border-radius:var(--sfaf-radius);padding:14px 16px 15px;font-size:15px;font-weight:500;',
    '  line-height:1.45;animation:sfaf-rise .34s var(--sfaf-ease) both;}',
    '#sfaf-chat .sfaf-alert-kind{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:800;',
    '  letter-spacing:.08em;text-transform:uppercase;color:var(--sfaf-on-accent);margin-bottom:6px;}',
    '#sfaf-chat .sfaf-alert-kind svg{width:18px;height:18px;flex:none;stroke-width:2.4;}',
    '#sfaf-chat .sfaf-alert-kind span{letter-spacing:inherit;text-transform:inherit;}',
    '#sfaf-chat .sfaf-alert p{margin:0;white-space:pre-wrap;overflow-wrap:anywhere;}',
    '#sfaf-chat .sfaf-alert p+p{margin-top:6px;}',

    // Starter questions
    '#sfaf-chat .sfaf-starters{display:flex;flex-direction:column;align-items:stretch;gap:8px;max-width:84%;}',
    '#sfaf-chat .sfaf-starter{display:flex;align-items:center;justify-content:space-between;gap:16px;width:100%;',
    '  text-align:left;cursor:pointer;background:transparent;color:var(--sfaf-text);',
    '  border:1px solid var(--sfaf-border);border-radius:var(--sfaf-radius);padding:10px 14px;font-size:14.5px;line-height:1.4;',
    '  animation:sfaf-rise .34s var(--sfaf-ease) both;',
    '  transition:background var(--sfaf-quick) ease,border-color var(--sfaf-quick) ease;}',
    '#sfaf-chat .sfaf-starter:nth-child(2){animation-delay:.05s;}',
    '#sfaf-chat .sfaf-starter:nth-child(3){animation-delay:.1s;}',
    '#sfaf-chat .sfaf-starter:hover{background:var(--sfaf-surface);border-color:var(--sfaf-dark);}',
    '#sfaf-chat .sfaf-starter svg{width:15px;height:15px;flex:none;}',

    // "Contact our team": an outline button, then a short form in the conversation.
    '#sfaf-chat .sfaf-contactbtn{align-self:flex-start;max-width:84%;',
    '  animation:sfaf-rise .34s var(--sfaf-ease) .1s both;}',
    '#sfaf-chat .sfaf-contact{align-self:stretch;display:flex;flex-direction:column;gap:10px;position:relative;',
    '  background:var(--sfaf-surface);color:var(--sfaf-text);border:1px solid var(--sfaf-border);',
    '  border-radius:var(--sfaf-radius);padding:12px 14px;font-size:14px;animation:sfaf-rise .34s var(--sfaf-ease) both;}',
    '#sfaf-chat .sfaf-contact-title{font-weight:700;}',
    '#sfaf-chat .sfaf-contact-note{margin:0;color:var(--sfaf-muted);font-size:13.5px;line-height:1.45;}',
    '#sfaf-chat .sfaf-contact label{display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600;}',
    '#sfaf-chat .sfaf-contact input,#sfaf-chat .sfaf-contact textarea{font:inherit;font-weight:400;font-size:15px;color:var(--sfaf-text);background:var(--sfaf-surface);',
    '  border:1px solid var(--sfaf-border);border-radius:var(--sfaf-radius);padding:8px 10px;width:100%;box-sizing:border-box;}',
    '#sfaf-chat .sfaf-contact input:focus-visible,#sfaf-chat .sfaf-contact textarea:focus-visible{outline:2px solid var(--sfaf-focus);outline-offset:1px;}',
    '#sfaf-chat .sfaf-contact textarea{resize:vertical;min-height:72px;line-height:1.45;}',
    '#sfaf-chat .sfaf-contact .sfaf-optional{font-weight:400;color:var(--sfaf-muted);}',
    '#sfaf-chat .sfaf-contact-hp{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden;}',
    '#sfaf-chat .sfaf-contact-actions{display:flex;gap:8px;flex-wrap:wrap;}',
    '#sfaf-chat .sfaf-contact-send[disabled]{opacity:.6;cursor:default;}',
    '#sfaf-chat .sfaf-contact-cancel{background:transparent;color:var(--sfaf-text);border:1px solid var(--sfaf-border);',
    '  border-radius:var(--sfaf-radius);padding:9px 14px;font-size:14px;cursor:pointer;}',
    '#sfaf-chat .sfaf-contact-status{margin:0;font-size:13.5px;}',
    '#sfaf-chat .sfaf-contact-status:empty{display:none;}',

    // Donate: a full-width accent button, only on a donation answer. A link, never a payment.
    '#sfaf-chat .sfaf-donatebtn{align-self:stretch;display:flex;justify-content:center;',
    '  animation:sfaf-rise .34s var(--sfaf-ease) .06s both;}',
    '#sfaf-chat .sfaf-donatebtn:focus-visible{outline:2px solid var(--sfaf-focus);outline-offset:2px;}',

    // Link button
    '#sfaf-chat .sfaf-linkbtn{align-self:flex-start;max-width:84%;',
    '  animation:sfaf-rise .34s var(--sfaf-ease) .06s both;}',

    // Card
    '#sfaf-chat .sfaf-card{align-self:flex-start;width:84%;background:var(--sfaf-surface);',
    '  border:1px solid var(--sfaf-border);border-radius:var(--sfaf-radius);overflow:hidden;',
    '  animation:sfaf-rise .34s var(--sfaf-ease) .06s both;}',
    '#sfaf-chat .sfaf-card img{display:block;width:100%;height:160px;object-fit:cover;',
    '  background:var(--sfaf-surface-warm);max-width:none;border:0;}',
    '#sfaf-chat .sfaf-card-body{padding:14px 16px 16px;}',
    '#sfaf-chat .sfaf-card-kind{font-size:13px;font-weight:600;color:var(--sfaf-muted);margin-bottom:2px;}',
    '#sfaf-chat .sfaf-card-title{font-weight:600;font-size:16px;color:var(--sfaf-text);}',
    '#sfaf-chat .sfaf-card-sub{color:var(--sfaf-muted);font-size:13.5px;margin-top:4px;}',
    '#sfaf-chat .sfaf-card-cta{margin-top:12px;}',

    // Typing indicator: three square marks passing a slow beat along the row.
    '#sfaf-chat .sfaf-typing{display:inline-flex;gap:5px;align-items:center;height:47px;padding:0 16px;}',
    '#sfaf-chat .sfaf-typing span{width:6px;height:6px;background:var(--sfaf-dark);opacity:.22;',
    '  animation:sfaf-beat 1.35s infinite ease-in-out;}',
    '#sfaf-chat .sfaf-typing span:nth-child(2){animation-delay:.18s;}',
    '#sfaf-chat .sfaf-typing span:nth-child(3){animation-delay:.36s;}',
    '@keyframes sfaf-beat{0%,70%,100%{opacity:.22;}30%{opacity:1;}}',

    // Input row
    '#sfaf-chat .sfaf-hint{display:none;flex:none;font-size:12.5px;line-height:1.4;color:var(--sfaf-muted);',
    '  padding:10px 24px 0;background:var(--sfaf-surface);border-top:1px solid var(--sfaf-border);}',
    '#sfaf-chat.sfaf-listening .sfaf-hint{display:block;}',
    '#sfaf-chat .sfaf-form{flex:none;display:flex;gap:8px;padding:14px 24px 12px;',
    '  border-top:1px solid var(--sfaf-border);background:var(--sfaf-surface);}',
    '#sfaf-chat.sfaf-listening .sfaf-form{border-top:0;padding-top:10px;}',
    '#sfaf-chat .sfaf-input{flex:1;min-width:0;height:44px;border:1px solid var(--sfaf-border);border-radius:var(--sfaf-radius);',
    '  padding:0 14px;background:var(--sfaf-surface);color:var(--sfaf-text);font-size:16px;',
    '  caret-color:var(--sfaf-dark);transition:border-color .16s ease;}',
    '#sfaf-chat .sfaf-input::placeholder{color:var(--sfaf-muted);opacity:1;}',
    '#sfaf-chat .sfaf-input:hover{border-color:#8f8c89;}',
    '#sfaf-chat .sfaf-input:focus{border-color:var(--sfaf-dark);outline:1px solid var(--sfaf-dark);outline-offset:-2px;}',
    '#sfaf-chat .sfaf-mic,#sfaf-chat .sfaf-send{flex:none;width:44px;height:44px;cursor:pointer;border-radius:var(--sfaf-radius);',
    '  display:flex;align-items:center;justify-content:center;transition:background var(--sfaf-quick) ease,color var(--sfaf-quick) ease,border-color var(--sfaf-quick) ease;}',
    '#sfaf-chat .sfaf-mic svg,#sfaf-chat .sfaf-send svg{width:20px;height:20px;}',
    '#sfaf-chat .sfaf-mic{background:var(--sfaf-surface);color:var(--sfaf-text);border:1px solid var(--sfaf-border);}',
    '#sfaf-chat .sfaf-mic:hover{border-color:var(--sfaf-dark);}',
    '#sfaf-chat .sfaf-mic[aria-pressed="true"]{background:var(--sfaf-dark);color:var(--sfaf-on-dark);',
    '  border-color:var(--sfaf-dark);animation:sfaf-listen 1.5s infinite ease-in-out;}',
    '@keyframes sfaf-listen{0%,100%{box-shadow:0 0 0 0 rgba(55,52,51,.34);}50%{box-shadow:0 0 0 5px rgba(55,52,51,0);}}',
    '#sfaf-chat .sfaf-send{background:var(--sfaf-accent);color:var(--sfaf-on-accent);border:0;}',
    '#sfaf-chat .sfaf-send:hover{background:var(--sfaf-accent-hover);}',
    '#sfaf-chat .sfaf-send:disabled{background:var(--sfaf-surface-warm);color:var(--sfaf-muted);cursor:default;}',

    '#sfaf-chat .sfaf-foot{flex:none;font-size:12px;color:var(--sfaf-muted);text-align:center;',
    '  padding:0 24px 12px;background:var(--sfaf-surface);}',
    '#sfaf-chat .sfaf-foot a{color:var(--sfaf-muted);text-decoration:underline;text-underline-offset:2px;}',

    '#sfaf-chat .sfaf-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);',
    '  white-space:nowrap;}',

    // Phones: full-screen panel.
    '@media (max-width:640px){',
    '  #sfaf-chat .sfaf-panel{width:100%;height:100%;height:100dvh;box-shadow:none;border-radius:0;}',
    '  #sfaf-chat .sfaf-header{padding-left:16px;padding-right:8px;}',
    '  #sfaf-chat .sfaf-messages{padding:18px 16px;}',
    '  #sfaf-chat .sfaf-msg,#sfaf-chat .sfaf-starters,#sfaf-chat .sfaf-linkbtn{max-width:92%;}',
    '  #sfaf-chat .sfaf-card{width:92%;}',
    '  #sfaf-chat .sfaf-form{padding:12px 12px 10px;}',
    '  #sfaf-chat .sfaf-hint{padding-left:16px;padding-right:16px;}',
    '  #sfaf-chat .sfaf-foot{padding:0 12px 10px;}',
    '}',

    '@media (prefers-reduced-motion:reduce){',
    '  #sfaf-chat *,#sfaf-chat *::before,#sfaf-chat *::after{animation:none !important;transition:none !important;}',
    '  #sfaf-chat .sfaf-panel{transform:none;}',
    '  #sfaf-chat .sfaf-typing span{opacity:.6;}',
    '}'
  ].join('\n');

  /* ============================ SFAF ALLOWLIST ============================ */
  // Card images only from SFAF domains. KEEP IN STEP with SFAF_Chat_Core::is_sfaf_url():
  // sfaf.org, *.sfaf.org, tweaker.org (+ www). HTTPS only.
  function isSfafUrl(url) {
    try {
      var u = new URL(url);
      if (u.protocol !== 'https:') return false;
      var h = u.hostname.toLowerCase();
      return h === 'sfaf.org' || /\.sfaf\.org$/.test(h) ||
        h === 'tweaker.org' || h === 'www.tweaker.org';
    } catch (e) {
      return false;
    }
  }

  function isHttps(url) {
    try { return new URL(url).protocol === 'https:'; } catch (e) { return false; }
  }

  /* ================================ DOM =================================== */

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function svg(paths) {
    var ns = 'http://www.w3.org/2000/svg';
    var s = document.createElementNS(ns, 'svg');
    s.setAttribute('viewBox', '0 0 24 24');
    s.setAttribute('aria-hidden', 'true');
    s.setAttribute('focusable', 'false');
    paths.forEach(function (d) {
      var p = document.createElementNS(ns, 'path');
      p.setAttribute('d', d);
      s.appendChild(p);
    });
    return s;
  }

  // Square-cornered line icons, to match the squared interface.
  var ICON_CLOSE = ['M18 6 6 18', 'M6 6l12 12'];
  var ICON_SEND = ['M4 12h15', 'M13 6l6 6-6 6'];
  var ICON_MIC = ['M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z', 'M19 11a7 7 0 0 1-14 0', 'M12 18v3'];
  var ICON_SPEAKER = ['M11 5 6 9H3v6h3l5 4z', 'M15.5 8.5a5 5 0 0 1 0 7', 'M18.5 5.5a9.5 9.5 0 0 1 0 13'];
  var ICON_NOTICE = ['M3 3h18v18H3z', 'M12 10.5V17', 'M12 7v.01'];

  // The launcher's bubble: a yellow speech bubble with a dark question mark. Its shape
  // and motion live between the BUBBLE markers here and in the CSS, and nowhere else.
  // BUBBLE:START variant C, "Tilted" (chosen 2026-11-17)
  var BUBBLE = [
    ['sfaf-qmark-b', 'M9 2h14a7 7 0 0 1 7 7v7a7 7 0 0 1-7 7H14.5l-5.7 5.2c-.64.58-1.66.13-1.66-.73V23H9a7 7 0 0 1-7-7V9a7 7 0 0 1 7-7z'],
    ['sfaf-qmark-g', 'M6 11.5V9.5a3 3 0 0 1 3-3h2.5'],
    ['sfaf-qmark-q', 'M12.6 9.4a3.4 3.4 0 1 1 4.9 3.05c-1 .5-1.5 1.15-1.5 2.25v.6'],
    ['sfaf-qmark-d', 'M16 17.15a1.45 1.45 0 1 1 0 2.9a1.45 1.45 0 1 1 0-2.9z']
  ];
  // BUBBLE:END
  function questionBubble() {
    var ns = 'http://www.w3.org/2000/svg';
    var s = document.createElementNS(ns, 'svg');
    s.setAttribute('viewBox', '0 0 32 32');
    s.setAttribute('class', 'sfaf-qmark');
    s.setAttribute('aria-hidden', 'true');
    s.setAttribute('focusable', 'false');
    BUBBLE.forEach(function (p) {
      var n = document.createElementNS(ns, 'path');
      n.setAttribute('class', p[0]);
      n.setAttribute('d', p[1]);
      s.appendChild(n);
    });
    // Once: when the load motion ends it is gone for good, even if the launcher is
    // hidden and shown again (which would otherwise start it over).
    s.addEventListener('animationend', function () {
      s.classList.add('sfaf-qmark-done');
    });
    return s;
  }

  // An action button's inside: the fill that grows on hover, the label, and the arrow
  // that comes in (left) and the one that goes out (right). Returns the label, whose
  // text can be changed later without losing the rest.
  function markFlow(node, labelText, dark) {
    node.classList.add('sfaf-flow');
    if (dark) node.classList.add('sfaf-flow-dark');
    node.textContent = '';
    node.appendChild(el('span', 'sfaf-flow-disc'));
    var inArrow = svg(ICON_SEND);
    inArrow.setAttribute('class', 'sfaf-flow-a sfaf-flow-in');
    node.appendChild(inArrow);
    var text = el('span', 'sfaf-flow-text', labelText);
    node.appendChild(text);
    var outArrow = svg(ICON_SEND);
    outArrow.setAttribute('class', 'sfaf-flow-a sfaf-flow-out');
    node.appendChild(outArrow);
    return text;
  }

  var SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition || null;
  var canSpeak = 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window;

  // Touch screens: moving focus to the input pops up the keyboard over the starters.
  var coarsePointer = !!(window.matchMedia && window.matchMedia('(pointer:coarse)').matches);

  var style = el('style');
  style.textContent = CSS;
  document.head.appendChild(style);

  var root = el('div');
  root.id = 'sfaf-chat';

  // The Appearance screen, already validated by the plugin. Without it, the CSS above
  // is the SFAF brand. setProperty takes one value at a time, so none can add a rule.
  var THEME = CONFIG.theme || {};
  var themeVars = THEME.vars || {};
  Object.keys(themeVars).forEach(function (name) {
    root.style.setProperty('--sfaf-' + name, themeVars[name]);
  });

  var launcher = el('button', 'sfaf-launcher');
  launcher.type = 'button';
  launcher.setAttribute('aria-haspopup', 'dialog');
  launcher.setAttribute('aria-expanded', 'false');
  launcher.appendChild(questionBubble());
  launcher.appendChild(el('span', null, 'Ask SFAF'));

  var overlay = el('div', 'sfaf-overlay');

  var panel = el('div', 'sfaf-panel');
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-modal', 'true');
  panel.setAttribute('aria-labelledby', 'sfaf-chat-title');
  panel.tabIndex = -1;

  var header = el('div', 'sfaf-header');
  var title = el('h2', 'sfaf-title', 'Ask SFAF');
  title.id = 'sfaf-chat-title';
  var closeBtn = el('button', 'sfaf-close');
  closeBtn.type = 'button';
  closeBtn.setAttribute('aria-label', 'Close chat');
  closeBtn.appendChild(svg(ICON_CLOSE));
  header.appendChild(title);

  // No browser speech synthesis, no control: there is nothing to offer.
  var readBtn = null;
  if (canSpeak) {
    readBtn = el('button', 'sfaf-read');
    readBtn.type = 'button';
    readBtn.title = 'Read answers aloud. Press again to stop.';
    readBtn.setAttribute('aria-pressed', 'false');
    readBtn.appendChild(svg(ICON_SPEAKER));
    readBtn.appendChild(el('span', null, 'Read aloud'));
    header.appendChild(readBtn);
  }
  header.appendChild(closeBtn);

  var messages = el('div', 'sfaf-messages');
  messages.setAttribute('role', 'log');
  messages.setAttribute('aria-live', 'polite');
  messages.setAttribute('aria-relevant', 'additions');

  var hint = el('div', 'sfaf-hint', VOICE_HINT);
  hint.id = 'sfaf-chat-hint';

  var form = el('form', 'sfaf-form');
  form.setAttribute('novalidate', '');
  var label = el('label', 'sfaf-sr', 'Your question');
  label.setAttribute('for', 'sfaf-chat-input');
  var input = el('input', 'sfaf-input');
  input.id = 'sfaf-chat-input';
  input.type = 'text';
  input.maxLength = 600;
  input.autocomplete = 'off';
  input.placeholder = 'Type your question';

  // Always shown, even where it can't work: tapping it then explains why.
  var micBtn = el('button', 'sfaf-mic');
  micBtn.type = 'button';
  micBtn.setAttribute('aria-label', 'Voice input');
  micBtn.setAttribute('aria-pressed', 'false');
  micBtn.setAttribute('aria-describedby', 'sfaf-chat-hint');
  micBtn.appendChild(svg(ICON_MIC));

  var sendBtn = el('button', 'sfaf-send');
  sendBtn.type = 'submit';
  sendBtn.setAttribute('aria-label', 'Send');
  sendBtn.appendChild(svg(ICON_SEND));
  form.appendChild(label);
  form.appendChild(input);
  form.appendChild(micBtn);
  form.appendChild(sendBtn);

  var foot = el('div', 'sfaf-foot');
  foot.appendChild(document.createTextNode('Automated assistant · not medical advice · call '));
  foot.appendChild(telLink(PHONE));
  foot.appendChild(document.createTextNode(' for help'));

  panel.appendChild(header);
  panel.appendChild(messages);
  panel.appendChild(hint);
  panel.appendChild(form);
  panel.appendChild(foot);
  root.appendChild(launcher);
  root.appendChild(overlay);
  root.appendChild(panel);
  document.body.appendChild(root);

  // The insights report (1.23.0): which links in the chat were used. Counts only.
  messages.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a || !messages.contains(a)) return;
    var type = actionFor(a);
    if (!type) return;
    var cat = catOf(a);
    if (type === 'appointment') { apptCat = cat; apptAt = Date.now(); }
    track(type, cat);
  }, true);

  // A visit type chosen in the appointment chooser the chat opened ("clicked toward
  // booking"). Booking itself happens on healow and is not seen here. On www, the site's own
  // script moves the address to data-exit, so either attribute counts.
  document.addEventListener('click', function (e) {
    if (!apptAt || Date.now() - apptAt > 30 * 60 * 1000) return;
    var opt = e.target && e.target.closest ? e.target.closest('#sfaf-appt-modal a') : null;
    if (!opt) return;
    var to = (opt.getAttribute('href') || '') + ' ' + (opt.getAttribute('data-exit') || '');
    if (/healow\.com/i.test(to)) track('healow', apptCat);
  }, true);

  /* ============================ WEGLOT PAIRING ============================ */
  /*
   * Weglot's default selector is fixed in the bottom-right corner, and its size
   * depends on the site's CSS, so it is measured rather than assumed. The launcher
   * takes its height and sits just to its left, on the same edge.
   *
   * With no selector, a hidden one (resources.sfaf.org has it at zero size) or one
   * placed inline in a menu, the launcher uses the corner and offsets from the
   * Appearance screen. Only the right corner pairs: Weglot isn't in the left one.
   */
  var PLACE = THEME.placement || {};
  var ON_LEFT = PLACE.corner === 'left';
  var OFFSET_X = typeof PLACE.x === 'number' ? PLACE.x : 40;
  var OFFSET_Y = typeof PLACE.y === 'number' ? PLACE.y : 0;
  var DOCK_GAP = typeof PLACE.gap === 'number' ? PLACE.gap : 8;
  var PAIR_WEGLOT = PLACE.weglot !== false && !ON_LEFT;
  var WEGLOT_WATCH_MS = 20000;

  function weglotBox() {
    var sel = document.querySelector('aside.country-selector');
    if (!sel || window.getComputedStyle(sel).position !== 'fixed') return null;
    var r = (sel.querySelector('.wgcurrent') || sel).getBoundingClientRect();
    var doc = document.documentElement;
    if (!r.width || !r.height) return null;
    if (r.right < doc.clientWidth / 2 || r.bottom < doc.clientHeight - 80) return null;
    return r;
  }

  function dockLauncher() {
    var r = PAIR_WEGLOT ? weglotBox() : null;
    var doc = document.documentElement;
    var set = function (name, value) { root.style.setProperty('--sfaf-dock-' + name, value); };
    if (r) {
      set('right', Math.round(doc.clientWidth - r.left + DOCK_GAP) + 'px');
      set('left', 'auto');
      set('bottom', Math.round(doc.clientHeight - r.bottom + OFFSET_Y) + 'px');
      set('height', Math.round(r.height) + 'px');
    } else {
      set('right', ON_LEFT ? 'auto' : OFFSET_X + 'px');
      set('left', ON_LEFT ? OFFSET_X + 'px' : 'auto');
      set('bottom', OFFSET_Y + 'px');
      root.style.removeProperty('--sfaf-dock-height');
    }
    return !!r;
  }

  // Weglot can arrive after this script, so watch for it for a short while.
  if (!dockLauncher() && PAIR_WEGLOT && window.MutationObserver) {
    var weglotWatch = new MutationObserver(function () {
      if (dockLauncher()) weglotWatch.disconnect();
    });
    weglotWatch.observe(document.body, { childList: true });
    setTimeout(function () { weglotWatch.disconnect(); }, WEGLOT_WATCH_MS);
  }
  window.addEventListener('load', dockLauncher);
  window.addEventListener('resize', dockLauncher);

  /* ============================== RENDERING =============================== */

  function telLink(num) {
    var a = el('a', 'sfaf-tel', num);
    var digits = num.replace(/\D/g, '');
    a.href = digits.length === 3 ? 'tel:' + digits : 'tel:+1' + digits.replace(/^1(?=\d{10}$)/, '');
    return a;
  }

  /*
   * AUTO-LINKS (1.20.0). In a reply's text, these become working links:
   *   an email address            mailto:, any address
   *   a web address               only on an allowed host or a subdomain of one
   *                               (1.21.0: CONFIG.links, from the plugin: the approved
   *                               link domains, the indexed sites, healow), opened in a
   *                               new tab, always https. Any other address stays plain
   *                               text, so a model reply can never put a link to an
   *                               unknown or made-up site in front of a visitor.
   *   a US phone number           tel:+1...
   *   988 or 911                  tel:, only beside "call", "text", "dial" (or Spanish),
   *                               "988 Suicide & Crisis Lifeline" or "911 in an emergency",
   *                               never a bare number
   * One pass, left to right, so an email's domain is never linked on its own.
   */
  var LINK_RE = new RegExp([
    '([A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\\.[A-Za-z0-9-]+)*\\.[A-Za-z]{2,})',
    '((?:https?:\\/\\/|www\\.)[^\\s<>"\'()\\[\\]]+|\\b(?:[A-Za-z0-9-]+\\.)+(?:org|com|net|io)\\b(?:\\/[^\\s<>"\'()\\[\\]]*)?)',
    '(\\(?\\d{3}\\)?[\\s.-]\\d{3}[\\s.-]\\d{4})',
    '\\b(988|911)\\b'
  ].join('|'), 'g');
  // Without a list from the plugin, our own three sites only.
  var LINK_HOSTS = (Array.isArray(CONFIG.links) && CONFIG.links.length ? CONFIG.links :
    ['sfaf.org', 'tweaker.org', 'cycletozero.org']).map(function (h) { return String(h).toLowerCase(); });
  function allowedHost(host) {
    host = String(host).toLowerCase().replace(/\.$/, '');
    for (var i = 0; i < LINK_HOSTS.length; i++) {
      var d = LINK_HOSTS[i];
      if (host === d || host.slice(-(d.length + 1)) === '.' + d) return true;
    }
    return false;
  }
  var SHORT_BEFORE_RE = /\b(call|text|dial|llam[ae]|llamar|marc[ae]|marcar|env[ií]a|mensaje)\b[^.\n]{0,12}$/i;
  var SHORT_AFTER_RE = /^\s*(suicide|crisis|lifeline|l[ií]nea|in an emergency|en (caso de )?(una )?emergencia)/i;

  // An address on an allowed host, as an https URL; null for anything else.
  function ourUrl(raw) {
    var u = /^https?:\/\//i.test(raw) ? raw.replace(/^http:/i, 'https:') : 'https://' + raw;
    try {
      var p = new URL(u);
      return allowedHost(p.hostname) ? p.href : null;
    } catch (e) {
      return null;
    }
  }

  // Plain text, with emails, our web addresses and phone numbers as links.
  function linkify(target, text) {
    var s = String(text);
    var last = 0;
    var m;
    LINK_RE.lastIndex = 0;
    while ((m = LINK_RE.exec(s))) {
      var found = m[0];
      var link = null;
      if (m[1]) {
        link = el('a', 'sfaf-tel sfaf-mail', found);
        link.href = 'mailto:' + found;
      } else if (m[2]) {
        // Sentence punctuation after an address isn't part of it.
        found = found.replace(/[.,;:!?'"]+$/, '');
        var url = ourUrl(found);
        if (url) {
          link = el('a', 'sfaf-tel sfaf-url', found);
          link.href = url;
          link.target = '_blank';
          link.rel = 'noopener';
        }
      } else if (m[3]) {
        link = telLink(found);
      } else if (m[4] && (SHORT_BEFORE_RE.test(s.slice(Math.max(0, m.index - 40), m.index)) ||
          SHORT_AFTER_RE.test(s.slice(m.index + found.length)))) {
        link = telLink(found);
      }
      if (!link) continue;
      if (m.index > last) target.appendChild(document.createTextNode(s.slice(last, m.index)));
      target.appendChild(link);
      last = m.index + found.length;
      LINK_RE.lastIndex = last;
    }
    if (last < s.length) target.appendChild(document.createTextNode(s.slice(last)));
  }
  /*
   * The appointment chooser (served with this script, see SFAF_Chat_Widget::
   * appointments()). A reply's [label](#sfaf-appointments) becomes a link to it, and
   * the chooser's own document click listener opens it. Only that exact target:
   * any other [label](address) stays plain text, so a reply still can't carry a URL.
   */
  var APPT_RE = /\[([^\]\n]{1,60})\]\(#sfaf-appointments\)/;
  var APPT_FONTS = 'https://fonts.googleapis.com/css2?family=Geologica:wght@400;700&family=Young+Serif&display=swap';
  var apptFontsAsked = false;

  // The chooser is styled in Geologica and Young Serif and expects the page to have
  // them. Fetched only when a booking link is shown, and only if the page lacks
  // them; blocked or offline, its own fallbacks (Helvetica/Arial, Georgia) apply.
  function apptFonts() {
    if (apptFontsAsked) return;
    apptFontsAsked = true;
    var have = {};
    try {
      document.fonts.forEach(function (f) { have[String(f.family).replace(/["']/g, '')] = true; });
    } catch (e) { /* no FontFaceSet: load them */ }
    if ((have.Geologica && have['Young Serif']) || document.getElementById('sfaf-appt-fonts')) return;
    var l = document.createElement('link');
    l.id = 'sfaf-appt-fonts';
    l.rel = 'stylesheet';
    l.href = APPT_FONTS;
    document.head.appendChild(l);
  }

  function apptLink(label) {
    var a = el('a', 'sfaf-appt-link');
    markFlow(a, label);
    a.href = '#sfaf-appointments';
    a.addEventListener('click', function (e) {
      // Without the chooser on the page, stay put rather than jump to a hash.
      if (!window.SFAFAppointments) e.preventDefault();
    });
    apptFonts();
    return a;
  }

  // Text with booking links, emails, our web addresses and phone numbers as links
  // (linkify), and **bold** as <strong>. Built from text nodes and elements only:
  // nothing in a reply is ever parsed as HTML.
  function inline(node, text) {
    var parts = String(text).split(APPT_RE);
    for (var i = 0; i < parts.length; i++) {
      if (i % 2 === 1) node.appendChild(apptLink(parts[i]));
      else if (parts[i]) inlineText(node, parts[i]);
    }
  }

  function inlineText(node, text) {
    var bold = String(text).split(/\*\*([^*\n]+)\*\*/);
    for (var b = 0; b < bold.length; b++) {
      var target = node;
      if (b % 2 === 1) { target = el('strong'); node.appendChild(target); }
      if (bold[b]) linkify(target, bold[b]);
    }
  }

  // A reply's lines: runs starting "- ", "* " or a bullet character become a bulleted
  // list, "1. " a numbered one; other lines stay text, a blank line between paragraphs.
  // So a list the model writes reads as a list, never as raw asterisks.
  var LIST_RE = /^\s*(?:([-*•])|(\d{1,2})[.)])\s+(.*)$/;
  function fillText(node, text) {
    var lines = String(text).replace(/\r\n?/g, '\n').split('\n');
    var para = [];
    var list = null;
    var listTag = '';
    function flush() {
      if (!para.length) return;
      var p = el('p', 'sfaf-p');
      inline(p, para.join('\n'));
      node.appendChild(p);
      para = [];
    }
    lines.forEach(function (line) {
      var m = LIST_RE.exec(line);
      if (m) {
        flush();
        var tag = m[2] ? 'ol' : 'ul';
        if (!list || listTag !== tag) {
          list = el(tag, 'sfaf-list');
          listTag = tag;
          node.appendChild(list);
        }
        var li = el('li');
        inline(li, m[3]);
        list.appendChild(li);
      } else if (!line.trim()) {
        flush();
        list = null;
      } else {
        list = null;
        para.push(line);
      }
    });
    flush();
  }

  function scrollDown() {
    messages.scrollTop = messages.scrollHeight;
  }

  function addBubble(role, text, extraCls) {
    var b = el('div', 'sfaf-msg ' + (role === 'user' ? 'sfaf-user' : 'sfaf-bot') +
      (extraCls ? ' ' + extraCls : ''));
    if (role === 'user') b.textContent = text;
    else fillText(b, text);
    messages.appendChild(b);
    scrollDown();
    return b;
  }

  function addLink(link) {
    if (!link || !isHttps(link.url)) return;
    var a = el('a', 'sfaf-linkbtn');
    a.href = link.url;
    a.target = '_blank';
    a.rel = 'noopener';
    markFlow(a, link.label || 'Learn more');
    messages.appendChild(a);
    scrollDown();
  }

  function addDonate(d) {
    if (!d || !isHttps(d.url)) return;
    var a = el('a', 'sfaf-donatebtn');
    a.href = d.url;
    a.target = '_blank';
    a.rel = 'noopener';
    markFlow(a, d.label || 'Donate');
    messages.appendChild(a);
    scrollDown();
  }

  function addCard(card) {
    if (!card || !isHttps(card.url)) return;
    var c = el('div', 'sfaf-card');

    // Image only from SFAF domains; otherwise keep the card without it.
    if (card.image_url && isSfafUrl(card.image_url)) {
      var img = el('img');
      img.src = card.image_url;
      img.alt = '';
      img.loading = 'lazy';
      img.referrerPolicy = 'no-referrer';
      img.onerror = function () { img.remove(); };
      c.appendChild(img);
    }

    var body = el('div', 'sfaf-card-body');
    // The server names the kind ("Blog post" for SFAF's blog), so a post never looks
    // like an official page. textContent, like everything else here.
    var kind = typeof card.kind === 'string' && card.kind ? card.kind : (card.type === 'event' ? 'Event' : 'Article');
    body.appendChild(el('div', 'sfaf-card-kind', kind));
    body.appendChild(el('div', 'sfaf-card-title', card.title || ''));
    if (card.subtitle) body.appendChild(el('div', 'sfaf-card-sub', card.subtitle));
    var cta = el('a', 'sfaf-card-cta');
    markFlow(cta, card.cta || (card.type === 'event' ? 'Event details' : 'Read more'), true);
    cta.href = card.url;
    if (card.type === 'event') cta.setAttribute('data-sfaf-kind', 'event');
    cta.target = '_blank';
    cta.rel = 'noopener';
    body.appendChild(cta);
    c.appendChild(body);

    messages.appendChild(c);
    scrollDown();
  }

  // A message from the widget, not the assistant. It goes in the log, so it is shown
  // and announced, and it never enters the thread. Re-adding it announces it again.
  var noteEl = null;
  function clearNote() {
    if (noteEl) { noteEl.remove(); noteEl = null; }
  }
  function addNote(text) {
    clearNote();
    noteEl = el('div', 'sfaf-note', text);
    messages.appendChild(noteEl);
    scrollDown();
  }

  var typingEl = null;
  function showTyping(on) {
    if (on && !typingEl) {
      typingEl = el('div', 'sfaf-msg sfaf-bot sfaf-typing');
      typingEl.setAttribute('aria-label', 'Assistant is typing');
      typingEl.setAttribute('role', 'status');
      typingEl.appendChild(el('span'));
      typingEl.appendChild(el('span'));
      typingEl.appendChild(el('span'));
      messages.appendChild(typingEl);
      scrollDown();
    } else if (!on && typingEl) {
      typingEl.remove();
      typingEl = null;
    }
  }

  function isOpen() {
    return root.classList.contains('sfaf-open');
  }

  // Back to typing, without forcing a touch keyboard up over the conversation.
  function focusComposer() {
    if (!coarsePointer) { input.focus(); return; }
    if (!panel.contains(document.activeElement)) panel.focus();
  }

  /* ========================== STARTER QUESTIONS =========================== */
  /*
   * Fetched when the panel opens, not on page load, so a visitor who never opens it
   * costs nothing. Only the address without its query string or fragment is sent,
   * since those can hold personal details. If this fails, the panel just has no
   * starters.
   */
  var startersEl = null;
  var alertsEl = null;
  var startersCache = {};   // page address => {questions, alerts}

  function pageAddress() {
    return location.origin + location.pathname;
  }

  function removeStarters() {
    if (startersEl) { startersEl.remove(); startersEl = null; }
  }

  function showStarters(list) {
    removeStarters();
    if (!list.length) return;
    startersEl = el('div', 'sfaf-starters');
    startersEl.setAttribute('role', 'group');
    startersEl.setAttribute('aria-label', 'Suggested questions');
    list.slice(0, 3).forEach(function (q) {
      var b = el('button', 'sfaf-starter');
      b.type = 'button';
      b.appendChild(el('span', null, q));
      b.appendChild(svg(ICON_SEND));
      b.addEventListener('click', function () {
        if (busy) return;
        panel.focus();   // this button is about to go away
        ask(q);
      });
      startersEl.appendChild(b);
    });
    messages.appendChild(startersEl);
  }

  // Active staff alerts ("Closed today due to weather"), first in the conversation,
  // above the greeting. Text only, through textContent like everything else.
  function showAlerts(list) {
    if (alertsEl) { alertsEl.remove(); alertsEl = null; }
    if (!list.length) return;
    alertsEl = el('div', 'sfaf-alert');
    alertsEl.setAttribute('role', 'note');
    alertsEl.setAttribute('aria-label', 'Notice');
    var kind = el('div', 'sfaf-alert-kind');
    kind.appendChild(svg(ICON_NOTICE));
    kind.appendChild(el('span', null, list.length > 1 ? 'Notices' : 'Notice'));
    alertsEl.appendChild(kind);
    list.forEach(function (t) { alertsEl.appendChild(el('p', null, t)); });
    messages.insertBefore(alertsEl, messages.firstChild);
  }

  function showOpening(got) {
    showAlerts(got.alerts);
    // Starters only until the visitor has asked something.
    if (!asked) showStarters(got.questions);
  }

  function loadStarters() {
    var mySession = session;
    var page = pageAddress();
    if (startersCache[page]) { showOpening(startersCache[page]); return; }

    fetch(STARTERS_URL, requestOptions({ url: page }))
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || !data.ok || !Array.isArray(data.questions)) return;
        var got = {
          questions: data.questions.filter(function (q) { return typeof q === 'string' && q; }),
          alerts: (Array.isArray(data.alerts) ? data.alerts : [])
            .map(function (a) { return a && typeof a.text === 'string' ? a.text.trim() : ''; })
            .filter(function (t) { return t; })
            .slice(0, 5)
        };
        startersCache[page] = got;
        // Too late if the panel closed in the meantime.
        if (mySession === session && isOpen()) showOpening(got);
      })
      .catch(function () {});
  }

  /* ============================ CONVERSATION ============================== */

  // In memory only. Each item: {role: "user"|"assistant", text}.
  var thread = [];
  var busy = false;
  var asked = false;     // has this conversation started?
  var inflight = null;   // AbortController of the current request
  var session = 0;       // bumps on close, so a late reply can't land in a new thread

  /*
   * The insights report (1.23.0). Each reply carries its category {team, sub}; the
   * elements it adds are tagged with it, so an action is credited to the reply it came
   * from. Kept in memory for this conversation only, cleared on close, and sent as
   * counts: the server never learns which conversation anything came from.
   */
  var cats = [];          // categories of this conversation's replies, by tag number
  var seenCats = [];      // "team|sub" asked about in this conversation
  var actedCats = [];     // "team|sub" this conversation already took an action in
  var apptCat = null;     // the category of the last booking link opened from the chat
  var apptAt = 0;

  function catKey(c) { return c ? c.team + '|' + c.sub : ''; }

  function catOf(node) {
    var tagged = node && node.closest ? node.closest('[data-sfaf-cat]') : null;
    var i = tagged ? parseInt(tagged.getAttribute('data-sfaf-cat'), 10) : -1;
    return cats[i] || null;
  }

  // Credit an action to a category: "first" only for the conversation's first action in it.
  function credit(cat) {
    var key = catKey(cat);
    var first = !!key && actedCats.indexOf(key) === -1;
    if (first) actedCats.push(key);
    return first;
  }

  function track(type, cat) {
    var first = credit(cat);
    try {
      fetch(TRACK_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: type, team: cat ? cat.team : '', sub: cat ? cat.sub : '', first: first }),
        credentials: 'omit',
        mode: 'cors',
        keepalive: true
      }).catch(function () {});
    } catch (e) {}
  }

  // What a click in the chat was, or null for anything the report doesn't count.
  function actionFor(a) {
    var href = a.getAttribute('href') || '';
    if (a.classList.contains('sfaf-appt-link')) return 'appointment';
    if (/^tel:/i.test(href)) return 'phone';
    if (a.classList.contains('sfaf-donatebtn')) return 'donate';
    if (a.getAttribute('data-sfaf-kind') === 'event') return 'calendar';
    var u;
    try { u = new URL(href, location.href); } catch (e) { return null; }
    if (!/^https?:$/.test(u.protocol)) return null;
    if (/^donate\./i.test(u.hostname) || /\/donat(e|ion)/i.test(u.pathname)) return 'donate';
    if (/\/(calendar|events?)(\/|$)/i.test(u.pathname)) return 'calendar';
    return null;
  }

  function setBusy(on) {
    busy = on;
    sendBtn.disabled = on;
    input.setAttribute('aria-busy', on ? 'true' : 'false');
  }

  function requestOptions(payload, signal) {
    var headers = { 'Content-Type': 'application/json' };
    return {
      method: 'POST',
      headers: headers,
      body: JSON.stringify(payload),
      signal: signal,
      credentials: 'omit',
      mode: 'cors'
    };
  }

  function ask(question) {
    var history = thread.slice(-MAX_HISTORY);
    var mySession = session;

    asked = true;
    stopListening(true);
    stopSpeaking();
    removeStarters();
    clearNote();
    addBubble('user', question);
    setBusy(true);
    showTyping(true);

    var ctrl = window.AbortController ? new AbortController() : null;
    inflight = ctrl;
    var timer = setTimeout(function () { if (ctrl) ctrl.abort(); }, REQUEST_TIMEOUT_MS);

    fetch(PROXY_URL, requestOptions(
      // The page goes along for routing "Contact our team" and for the Dashboard:
      // origin and path only, never the query or fragment.
      { question: question, history: history, scope: SCOPE, page: pageAddress(), seen: seenCats.slice() },
      ctrl ? ctrl.signal : undefined
    ))
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (mySession !== session) return;
        if (!data || !data.ok || typeof data.answer !== 'string') throw new Error('bad reply');

        showTyping(false);
        var before = messages.childNodes.length;
        var bubble = addBubble('bot', data.answer);
        // The server put the harm reduction label first: shown as a caution (1.20.0).
        if (data.harm_reduction === true && bubble.firstChild && bubble.firstChild.classList &&
            bubble.firstChild.classList.contains('sfaf-p')) {
          bubble.firstChild.classList.add('sfaf-caution');
        }
        if (data.card) addCard(data.card);
        else if (data.link) addLink(data.link);
        if (data.donate) addDonate(data.donate);
        if (data.contact && typeof data.contact.label === 'string') {
          addContact(data.contact.label, typeof data.contact.team === 'string' ? data.contact.team : '');
        }
        if (data.cat && typeof data.cat.team === 'string' && typeof data.cat.sub === 'string') {
          var tag = cats.push({ team: data.cat.team, sub: data.cat.sub }) - 1;
          for (var n = before; n < messages.childNodes.length; n++) {
            messages.childNodes[n].setAttribute('data-sfaf-cat', String(tag));
          }
          if (seenCats.indexOf(catKey(data.cat)) === -1) seenCats.push(catKey(data.cat));
        }

        // Only completed exchanges enter the thread.
        thread.push({ role: 'user', text: question });
        thread.push({ role: 'assistant', text: data.answer });
        spokenAnswer(data.answer);
      })
      .catch(function () {
        if (mySession !== session) return;
        showTyping(false);
        addBubble('bot', ERROR_TEXT, 'sfaf-error');
        spokenAnswer(ERROR_TEXT);
      })
      .then(function () {
        clearTimeout(timer);
        if (mySession !== session) return;
        inflight = null;
        setBusy(false);
        focusComposer();
      });
  }

  /* ============================ CONTACT FORM ============================== */
  /*
   * "Contact our team" opens a short form here in the conversation: a name, an
   * email, and optionally a phone number and a few words. The transcript the
   * visitor can see goes with it, to the team the server chose ("team", sent back
   * as given; the server only accepts a team it has). Nothing is kept in the browser
   * beyond this panel, and closing the chat clears it like the rest.
   */
  var contactEl = null;
  var contactSeq = 0;

  function addContact(label, team) {
    if (contactEl) { contactEl.remove(); contactEl = null; }
    var b = el('button', 'sfaf-contactbtn');
    markFlow(b, label, true);
    b.type = 'button';
    b.addEventListener('click', function () { showContactForm(label, b, team || ''); });
    contactEl = b;
    messages.appendChild(b);
    scrollDown();
  }

  // A labelled input, or a textarea for 'textarea'. Optional ones say so in the label.
  function field(labelText, type, name, auto, max, optional) {
    var id = 'sfaf-contact-' + name + '-' + (++contactSeq);
    var l = el('label');
    l.setAttribute('for', id);
    // Text and "(optional)" in one span, so the column layout keeps them on one line.
    var t = el('span', null, labelText);
    if (optional) t.appendChild(el('span', 'sfaf-optional', ' (optional)'));
    l.appendChild(t);
    var i = el(type === 'textarea' ? 'textarea' : 'input');
    i.id = id;
    if (type === 'textarea') i.rows = 3;
    else i.type = type;
    i.name = name;
    i.autocomplete = auto;
    i.maxLength = max;
    i.required = !optional;
    l.appendChild(i);
    return { label: l, input: i };
  }

  function showContactForm(label, btn, team) {
    var form = el('form', 'sfaf-contact');
    var formCat = catOf(btn);
    if (formCat) form.setAttribute('data-sfaf-cat', btn.getAttribute('data-sfaf-cat'));
    form.noValidate = true;
    form.setAttribute('aria-label', label);
    form.appendChild(el('div', 'sfaf-contact-title', label));
    form.appendChild(el('p', 'sfaf-contact-note',
      "We'll send this chat with your message, and someone will reach out within 1 to 2 business days."));
    var name = field('Your name', 'text', 'name', 'name', 100);
    var email = field('Your email', 'email', 'email', 'email', 200);
    var phone = field('Phone', 'tel', 'phone', 'tel', 30, true);
    var details = field('Additional details', 'textarea', 'details', 'off', 1000, true);
    form.appendChild(name.label);
    form.appendChild(email.label);
    form.appendChild(phone.label);
    form.appendChild(details.label);

    // People never see this field; a bot that fills it in is quietly ignored.
    var hp = el('div', 'sfaf-contact-hp');
    hp.setAttribute('aria-hidden', 'true');
    var hpInput = el('input');
    hpInput.type = 'text';
    hpInput.name = 'website';
    hpInput.tabIndex = -1;
    hpInput.autocomplete = 'off';
    hp.appendChild(hpInput);
    form.appendChild(hp);

    var status = el('p', 'sfaf-contact-status');
    status.setAttribute('role', 'alert');
    var actions = el('div', 'sfaf-contact-actions');
    var send = el('button', 'sfaf-contact-send');
    var sendText = markFlow(send, 'Send', true);
    send.type = 'submit';
    var cancel = el('button', 'sfaf-contact-cancel', 'Cancel');
    cancel.type = 'button';
    actions.appendChild(send);
    actions.appendChild(cancel);
    form.appendChild(status);
    form.appendChild(actions);

    cancel.addEventListener('click', function () {
      form.replaceWith(btn);
      contactEl = btn;
      btn.focus();
    });

    var mySession = session;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var n = name.input.value.trim();
      var m = email.input.value.trim();
      if (!n) { status.textContent = 'Please add your name.'; name.input.focus(); return; }
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(m)) { status.textContent = 'Please check your email address.'; email.input.focus(); return; }
      var ph = phone.input.value.trim();
      if (ph && (ph.replace(/\D/g, '').length < 7 || !/^\+?[0-9().\-\s]+(?:\s*(?:ext\.?|x)\s*\d{1,6})?$/i.test(ph))) {
        status.textContent = 'Please check your phone number, or leave it blank.'; phone.input.focus(); return;
      }
      status.textContent = '';
      send.disabled = true;
      sendText.textContent = 'Sending…';
      fetch(CONTACT_URL, requestOptions({
        name: n, email: m, phone: ph, details: details.input.value.trim(), team: team,
        page: pageAddress(), thread: thread.slice(), website: hpInput.value,
        cat_team: formCat ? formCat.team : '', cat_sub: formCat ? formCat.sub : '',
        cat_first: !!formCat && actedCats.indexOf(catKey(formCat)) === -1
      }))
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (mySession !== session) return;
          if (!data || !data.ok) {
            status.textContent = (data && typeof data.error === 'string') ? data.error : ERROR_TEXT;
            send.disabled = false;
            sendText.textContent = 'Send';
            return;
          }
          form.remove();
          contactEl = null;
          if (formCat) credit(formCat);
          // A success state with a check (1.21.0), not an ordinary reply.
          var done = addBubble('bot', typeof data.message === 'string' ? data.message : 'Thanks. Someone will reach out soon.', 'sfaf-success');
          var doneText = el('div', 'sfaf-success-text');
          while (done.firstChild) doneText.appendChild(done.firstChild);
          var tick = svg(['M12 2a10 10 0 1 1 0 20a10 10 0 1 1 0-20z', 'M7.5 12.5l3 3 6-6.5']);
          tick.setAttribute('class', 'sfaf-success-icon');
          tick.firstChild.setAttribute('class', 'sfaf-success-disc');
          tick.lastChild.setAttribute('class', 'sfaf-success-tick');
          done.appendChild(tick);
          done.appendChild(doneText);
          focusComposer();
        })
        .catch(function () {
          if (mySession !== session) return;
          status.textContent = ERROR_TEXT;
          send.disabled = false;
          sendText.textContent = 'Send';
        });
    });

    btn.replaceWith(form);
    contactEl = form;
    scrollDown();
    name.input.focus();
  }

  /* ============================== VOICE INPUT ============================= */
  /*
   * Speech goes into the input box, where the visitor can check it before pressing
   * Send. On a health site a misheard word shouldn't become a question by itself.
   *
   * Recognition runs on the device when the browser says it can. Otherwise the
   * browser decides, and Chrome for one uses its own online service, so the hint
   * says so while the microphone is on. Audio never passes through this code.
   */
  var recognizer = null;
  var onDevice = false;

  function speechLang() {
    return document.documentElement.lang || navigator.language || 'en-US';
  }

  function checkOnDevice() {
    if (!SpeechRec || typeof SpeechRec.available !== 'function') return;
    try {
      SpeechRec.available({ langs: [speechLang()], processLocally: true })
        .then(function (status) { onDevice = status === 'available'; })
        .catch(function () {});
    } catch (e) {}
  }

  function setListening(on) {
    root.classList.toggle('sfaf-listening', on);
    micBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
    if (on) hint.textContent = onDevice ? VOICE_HINT_LOCAL : VOICE_HINT;
  }

  // abort drops anything still being recognized; otherwise the last words still land.
  function stopListening(abort) {
    var rec = recognizer;
    if (!rec) return;
    if (abort) {
      recognizer = null;
      setListening(false);
      try { rec.abort(); } catch (e) {}
    } else {
      try { rec.stop(); } catch (e) {}
    }
  }

  function startListening() {
    if (!SpeechRec) { addNote(VOICE_UNSUPPORTED); return; }

    stopSpeaking();   // or the microphone hears the assistant
    clearNote();

    var rec = new SpeechRec();
    var typed = input.value.trim();
    rec.lang = speechLang();
    rec.continuous = false;
    rec.interimResults = true;
    rec.maxAlternatives = 1;
    if (onDevice) rec.processLocally = true;

    rec.onresult = function (e) {
      if (rec !== recognizer) return;
      var heard = '';
      for (var i = 0; i < e.results.length; i++) heard += e.results[i][0].transcript;
      input.value = ((typed ? typed + ' ' : '') + heard.trim()).slice(0, input.maxLength);
    };
    rec.onerror = function (e) {
      if (rec !== recognizer) return;
      if (VOICE_ERRORS[e.error]) addNote(VOICE_ERRORS[e.error]);
    };
    rec.onend = function () {
      if (rec !== recognizer) return;
      recognizer = null;
      setListening(false);
      focusComposer();
    };

    recognizer = rec;
    setListening(true);
    try {
      rec.start();
    } catch (e) {
      recognizer = null;
      setListening(false);
      addNote(VOICE_ERRORS['audio-capture']);
    }
  }

  micBtn.addEventListener('click', function () {
    if (recognizer) stopListening(false);
    else startListening();
  });

  /* ============================== VOICE OUTPUT ============================ */

  var readingOn = false;    // a preference, so it outlasts a closed thread
  var lastAnswer = '';
  var utterances = [];      // held so Chrome doesn't collect them mid-sentence

  // Turns an answer into something worth hearing.
  function speakable(text) {
    return String(text)
      .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')                 // [label](url) keeps the label
      .replace(/^\s*(?:[-*•]|\d{1,2}[.)])\s+/gm, '')    // list markers are not read out
      .replace(/([^.!?:;\s])[ \t]*\n/g, '$1.\n')                // a line ends in a pause
      .replace(/\b(?:https?:\/\/|www\.)[^\s)]+/gi, 'the link') // a bare address is never spelled out
      .replace(/\(?(\d{3})\)?[\s.-](\d{3})[\s.-](\d{4})/g, function (m, a, b, c) {
        return [a, b, c].map(function (g) { return g.split('').join(' '); }).join(', ');
      })
      .replace(/(\w)\s?\u2013\s?(\w)/g, '$1 to $2')            // en dash ranges: 5 to 8 pm
      .replace(/\s*\u2014\s*/g, ', ')
      .replace(/[\uD83C-\uDBFF][\uDC00-\uDFFF]|[\u2600-\u27BF]/g, '')
      .replace(/[*_#`~<>|]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function stopSpeaking() {
    if (!canSpeak) return;
    window.speechSynthesis.cancel();
    utterances = [];
  }

  function speak(text) {
    if (!canSpeak) return;
    stopSpeaking();
    // A sentence at a time: Chrome can stop dead partway through one long utterance.
    (speakable(text).match(/[^.!?]+(?:[.!?]+(?=\s|$)|$)|[.!?]+/g) || []).forEach(function (part) {
      var u = new window.SpeechSynthesisUtterance(part.trim());
      u.lang = speechLang();
      utterances.push(u);
      window.speechSynthesis.speak(u);
    });
  }

  function spokenAnswer(text) {
    lastAnswer = text;
    if (readingOn) speak(text);
  }

  if (readBtn) {
    readBtn.addEventListener('click', function () {
      readingOn = !readingOn;
      readBtn.setAttribute('aria-pressed', readingOn ? 'true' : 'false');
      if (!readingOn) { stopSpeaking(); return; }
      if (lastAnswer) { speak(lastAnswer); return; }
      // iOS only allows speech that follows a tap, and answers arrive later. A silent
      // utterance here is what unlocks them.
      var unlock = new window.SpeechSynthesisUtterance(' ');
      unlock.volume = 0;
      window.speechSynthesis.speak(unlock);
    });
    window.addEventListener('pagehide', stopSpeaking);
  }

  /* ============================ OPEN / CLOSE ============================== */

  function open() {
    if (isOpen()) return;
    root.classList.add('sfaf-open');
    launcher.setAttribute('aria-expanded', 'true');
    if (!messages.childNodes.length) addBubble('bot', GREETING);
    checkOnDevice();
    loadStarters();
    if (coarsePointer) panel.focus();
    else input.focus();
  }

  // Closing clears the conversation. Nothing is kept.
  function close() {
    if (!isOpen()) return;
    session++;
    if (inflight) { try { inflight.abort(); } catch (e) {} inflight = null; }
    stopListening(true);
    stopSpeaking();
    thread = [];
    cats = [];
    seenCats = [];
    actedCats = [];
    apptCat = null;
    apptAt = 0;
    asked = false;
    lastAnswer = '';
    noteEl = null;
    startersEl = null;
    alertsEl = null;
    contactEl = null;
    showTyping(false);
    messages.textContent = '';
    input.value = '';
    setBusy(false);
    root.classList.remove('sfaf-open');
    launcher.setAttribute('aria-expanded', 'false');
    launcher.focus();
  }

  launcher.addEventListener('click', open);
  closeBtn.addEventListener('click', close);

  // Clicking the dimmed page deliberately does nothing: closing erases the
  // conversation, which a stray click shouldn't do. Focus stays in the panel and the
  // page behind stays still.
  function holdPage(e) { e.preventDefault(); }
  overlay.addEventListener('mousedown', holdPage);
  overlay.addEventListener('wheel', holdPage, { passive: false });
  overlay.addEventListener('touchmove', holdPage, { passive: false });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var q = input.value.trim();
    if (!q || busy) return;
    input.value = '';
    ask(q);
  });

  function focusables() {
    return Array.prototype.filter.call(
      panel.querySelectorAll('a[href],button:not([disabled]),input:not([disabled])'),
      function (n) { return n.offsetParent !== null; }
    );
  }

  root.addEventListener('keydown', function (e) {
    // Esc closes from anywhere inside the panel.
    if (e.key === 'Escape' || e.key === 'Esc') {
      e.stopPropagation();
      close();
      return;
    }

    // The panel is modal, so Tab stays inside it.
    if (e.key !== 'Tab' || !isOpen()) return;
    var items = focusables();
    if (!items.length) return;
    var first = items[0];
    var last = items[items.length - 1];
    var at = document.activeElement;
    if (e.shiftKey && (at === first || at === panel)) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && at === last) {
      e.preventDefault();
      first.focus();
    }
  });
})();
