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

  // Set by the plugin ahead of this script: {proxy, nonce?}. The nonce appears only
  // in admins-only test mode, on a page served to a logged-in administrator.
  var CONFIG = window.sfafChatConfig || {};

  // Absolute: the widget runs on sfaf.org but the endpoint is on resources.sfaf.org.
  var PROXY_URL = CONFIG.proxy || 'https://resources.sfaf.org/wp-json/sfaf-chat/v1/ask';
  var STARTERS_URL = PROXY_URL.replace(/\/ask$/, '/starters');

  // Prior messages sent with each question.
  // KEEP EQUAL TO MAX_HISTORY_TURNS IN includes/class-sfaf-chat-endpoint.php.
  var MAX_HISTORY = 6;

  // Which SFAF sites this bot answers from. v1: "all".
  var SCOPE = (script && script.getAttribute('data-scope')) || 'all';

  var PHONE = '415-487-3000';
  var REQUEST_TIMEOUT_MS = 30000;

  var GREETING = "Hi! I can help you find SFAF services, locations and hours, and ways " +
    "to get involved. What are you looking for?";
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
    '  --sfaf-focus:#373433;',
    '  --sfaf-focus-on-dark:#FFD900;',
    '  --sfaf-bot-bg:#ffffff;',
    '  --sfaf-bot-text:#373433;',
    '  --sfaf-user-bg:#373433;',
    '  --sfaf-user-text:#ffffff;',
    '  --sfaf-radius:0px;',
    '  --sfaf-overlay:rgba(55,52,51,.48);',
    '  --sfaf-shadow:0 28px 72px rgba(35,33,32,.3);',
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
    '#sfaf-chat .sfaf-launcher{position:fixed;z-index:2147483000;',
    '  right:var(--sfaf-dock-right,40px);left:var(--sfaf-dock-left,auto);bottom:var(--sfaf-dock-bottom,0px);',
    '  height:var(--sfaf-dock-height,39px);border-radius:var(--sfaf-radius);',
    '  display:inline-flex;align-items:center;gap:8px;cursor:pointer;white-space:nowrap;',
    '  background:var(--sfaf-dark);color:var(--sfaf-on-dark);border:0;',
    '  border-left:4px solid var(--sfaf-accent);padding:0 14px 0 11px;font-size:13px;font-weight:600;',
    '  transition:background .16s ease;}',
    '#sfaf-chat .sfaf-launcher:hover{background:var(--sfaf-dark-hover);}',
    // Inset, because the page behind may be white and the bottom edge would clip it.
    '#sfaf-chat .sfaf-launcher:focus-visible{outline:2px solid var(--sfaf-focus-on-dark);outline-offset:-4px;}',
    '#sfaf-chat .sfaf-launcher svg{width:16px;height:16px;flex:none;}',
    '#sfaf-chat.sfaf-open .sfaf-launcher{display:none;}',

    // Overlay
    '#sfaf-chat .sfaf-overlay{position:fixed;inset:0;z-index:2147483001;background:var(--sfaf-overlay);',
    '  opacity:0;visibility:hidden;pointer-events:none;transition:opacity .22s ease,visibility 0s linear .22s;}',
    '#sfaf-chat.sfaf-open .sfaf-overlay{opacity:1;visibility:visible;pointer-events:auto;transition:opacity .3s ease,visibility 0s;}',

    // Panel: fixed with auto margins centers it without a transform, so text stays crisp
    // once the slide has finished.
    '#sfaf-chat .sfaf-panel{position:fixed;inset:0;margin:auto;z-index:2147483002;',
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
    '  transition:background .16s ease,color .16s ease,border-color .16s ease;}',
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
    '#sfaf-chat .sfaf-bot{align-self:flex-start;background:var(--sfaf-bot-bg);color:var(--sfaf-bot-text);',
    '  border:1px solid var(--sfaf-border);}',
    '#sfaf-chat .sfaf-user{align-self:flex-end;background:var(--sfaf-user-bg);color:var(--sfaf-user-text);}',
    '#sfaf-chat .sfaf-user::selection{background:var(--sfaf-surface);color:var(--sfaf-text);}',
    // A fixed pair, so the error stays readable whatever the bubble colors are.
    '#sfaf-chat .sfaf-error{background:var(--sfaf-error-bg);border-color:var(--sfaf-error-border);color:#373433;}',
    '#sfaf-chat .sfaf-msg a.sfaf-tel{color:inherit;text-decoration:underline;',
    '  text-underline-offset:2px;font-weight:600;}',
    '#sfaf-chat a.sfaf-tel{white-space:nowrap;}',

    // A note from the widget itself, such as a voice message. Not part of the conversation.
    '#sfaf-chat .sfaf-note{align-self:stretch;font-size:13.5px;color:var(--sfaf-muted);padding:2px 0 2px 12px;',
    '  border-left:2px solid var(--sfaf-border);animation:sfaf-rise .34s var(--sfaf-ease) both;}',

    // Starter questions
    '#sfaf-chat .sfaf-starters{display:flex;flex-direction:column;align-items:stretch;gap:8px;max-width:84%;}',
    '#sfaf-chat .sfaf-starter{display:flex;align-items:center;justify-content:space-between;gap:16px;width:100%;',
    '  text-align:left;cursor:pointer;background:transparent;color:var(--sfaf-text);',
    '  border:1px solid var(--sfaf-border);border-radius:var(--sfaf-radius);padding:10px 14px;font-size:14.5px;line-height:1.4;',
    '  animation:sfaf-rise .34s var(--sfaf-ease) both;',
    '  transition:background .16s ease,border-color .16s ease;}',
    '#sfaf-chat .sfaf-starter:nth-child(2){animation-delay:.05s;}',
    '#sfaf-chat .sfaf-starter:nth-child(3){animation-delay:.1s;}',
    '#sfaf-chat .sfaf-starter:hover{background:var(--sfaf-surface);border-color:var(--sfaf-dark);}',
    '#sfaf-chat .sfaf-starter svg{width:15px;height:15px;flex:none;}',

    // Link button: the one yellow element in the conversation.
    '#sfaf-chat .sfaf-linkbtn{align-self:flex-start;display:inline-flex;align-items:center;gap:8px;',
    '  max-width:84%;background:var(--sfaf-accent);color:var(--sfaf-on-accent);border:0;border-radius:var(--sfaf-radius);',
    '  padding:9px 14px;font-weight:600;font-size:14px;line-height:1.35;',
    '  animation:sfaf-rise .34s var(--sfaf-ease) .06s both;transition:background .16s ease;}',
    '#sfaf-chat .sfaf-linkbtn:hover{background:var(--sfaf-accent-hover);}',
    '#sfaf-chat .sfaf-linkbtn svg{width:15px;height:15px;flex:none;}',

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
    '#sfaf-chat .sfaf-card-cta{display:inline-block;margin-top:12px;background:var(--sfaf-dark);border-radius:var(--sfaf-radius);',
    '  color:var(--sfaf-on-dark);padding:8px 14px;font-weight:600;font-size:14px;transition:background .16s ease;}',
    '#sfaf-chat .sfaf-card-cta:hover{background:var(--sfaf-dark-hover);}',

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
    '  display:flex;align-items:center;justify-content:center;transition:background .16s ease,color .16s ease,border-color .16s ease;}',
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
  var ICON_CHAT = ['M4 5h16v11H10l-6 4z'];
  var ICON_CLOSE = ['M18 6 6 18', 'M6 6l12 12'];
  var ICON_SEND = ['M4 12h15', 'M13 6l6 6-6 6'];
  var ICON_ARROW = ['M7 17 17 7', 'M8 7h9v9'];
  var ICON_MIC = ['M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z', 'M19 11a7 7 0 0 1-14 0', 'M12 18v3'];
  var ICON_SPEAKER = ['M11 5 6 9H3v6h3l5 4z', 'M15.5 8.5a5 5 0 0 1 0 7', 'M18.5 5.5a9.5 9.5 0 0 1 0 13'];

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
  launcher.appendChild(svg(ICON_CHAT));
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
    a.href = 'tel:+1' + num.replace(/\D/g, '').replace(/^1(?=\d{10}$)/, '');
    return a;
  }

  // Plain text, with US phone numbers turned into tap-to-call links.
  var PHONE_RE = /(\(?\d{3}\)?[\s.-]\d{3}[\s.-]\d{4})/g;
  function fillText(node, text) {
    var parts = String(text).split(PHONE_RE);
    for (var i = 0; i < parts.length; i++) {
      if (!parts[i]) continue;
      if (i % 2 === 1) node.appendChild(telLink(parts[i]));
      else node.appendChild(document.createTextNode(parts[i]));
    }
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
    a.appendChild(el('span', null, link.label || 'Learn more'));
    a.appendChild(svg(ICON_ARROW));
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
    body.appendChild(el('div', 'sfaf-card-kind', card.type === 'event' ? 'Event' : 'Article'));
    body.appendChild(el('div', 'sfaf-card-title', card.title || ''));
    if (card.subtitle) body.appendChild(el('div', 'sfaf-card-sub', card.subtitle));
    var cta = el('a', 'sfaf-card-cta', card.cta || (card.type === 'event' ? 'Event details' : 'Read more'));
    cta.href = card.url;
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
  var startersCache = {};

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

  function loadStarters() {
    var mySession = session;
    var page = pageAddress();
    if (startersCache[page]) { showStarters(startersCache[page]); return; }

    fetch(STARTERS_URL, requestOptions({ url: page }))
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || !data.ok || !Array.isArray(data.questions)) return;
        var list = data.questions.filter(function (q) { return typeof q === 'string' && q; });
        startersCache[page] = list;
        // Too late if the panel closed, or the visitor has already asked something.
        if (mySession === session && isOpen() && !asked) showStarters(list);
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

  function setBusy(on) {
    busy = on;
    sendBtn.disabled = on;
    input.setAttribute('aria-busy', on ? 'true' : 'false');
  }

  function requestOptions(payload, signal) {
    var headers = { 'Content-Type': 'application/json' };
    if (CONFIG.nonce) headers['X-WP-Nonce'] = CONFIG.nonce;
    return {
      method: 'POST',
      headers: headers,
      body: JSON.stringify(payload),
      signal: signal,
      credentials: CONFIG.nonce ? 'same-origin' : 'omit',
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
      { question: question, history: history, scope: SCOPE },
      ctrl ? ctrl.signal : undefined
    ))
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (mySession !== session) return;
        if (!data || !data.ok || typeof data.answer !== 'string') throw new Error('bad reply');

        showTyping(false);
        addBubble('bot', data.answer);
        if (data.card) addCard(data.card);
        else if (data.link) addLink(data.link);

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
    asked = false;
    lastAnswer = '';
    noteEl = null;
    startersEl = null;
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
