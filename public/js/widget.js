/**
 * SFAF Chat — widget
 * =============================================================================
 * Served by the SFAF Chat plugin on resources.sfaf.org, and loaded on sfaf.org by
 * ONE line in the site footer. This URL has no version in it and never changes:
 *   <script src="https://resources.sfaf.org/wp-json/sfaf-chat/v1/widget.js" defer></script>
 *
 * Self-contained: no dependencies, no CDN, no cookies, no storage. The thread
 * lives only in memory and is cleared when the panel closes.
 *
 * All server text is inserted with textContent — never innerHTML — so nothing
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

  /* ================================ STYLES ================================ */
  /*
   * Every rule is scoped under #sfaf-chat so nothing leaks into — or is easily
   * overridden by — the host theme. Rebrand by editing the variables only.
   *
   * ACCESSIBILITY NOTE: white text on --sfaf-accent (#0098ad) is ~3.4:1, below
   * the WCAG AA 4.5:1 for body text. --sfaf-accent-dark (#00778a) is ~4.9:1.
   * To fix, set --sfaf-user-bg and --sfaf-launcher-bg to var(--sfaf-accent-dark).
   */
  var CSS = [
    '#sfaf-chat{',
    '  --sfaf-accent:#0098ad;',
    '  --sfaf-accent-dark:#00778a;',
    '  --sfaf-accent-tint:#e4f4f6;',
    '  --sfaf-header-bg:#14181b;',
    '  --sfaf-header-fg:#ffffff;',
    '  --sfaf-body-bg:#f4f6f7;',
    '  --sfaf-panel-bg:#ffffff;',
    '  --sfaf-text:#14181b;',
    '  --sfaf-muted:#5b6670;',
    '  --sfaf-border:#d5dbdf;',
    '  --sfaf-bot-bg:#ffffff;',
    '  --sfaf-bot-fg:#14181b;',
    '  --sfaf-user-bg:var(--sfaf-accent);',
    '  --sfaf-user-fg:#ffffff;',
    '  --sfaf-launcher-bg:var(--sfaf-accent);',
    '  --sfaf-launcher-fg:#ffffff;',
    '  --sfaf-error-bg:#fff4f2;',
    '  --sfaf-error-border:#e7b4aa;',
    '  --sfaf-focus:#00778a;',
    '  --sfaf-shadow:0 12px 40px rgba(20,24,27,.22);',
    '  --sfaf-font:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;',
    '  position:fixed;right:20px;bottom:20px;z-index:2147483000;',
    '  font-family:var(--sfaf-font);font-size:15px;line-height:1.45;color:var(--sfaf-text);',
    '}',
    // Reset the bits host themes most often restyle.
    '#sfaf-chat *,#sfaf-chat *::before,#sfaf-chat *::after{box-sizing:border-box;margin:0;padding:0;',
    '  font-family:inherit;letter-spacing:normal;text-transform:none;text-shadow:none;float:none;}',
    '#sfaf-chat button,#sfaf-chat input{font-size:inherit;line-height:inherit;color:inherit;',
    '  border-radius:0;box-shadow:none;min-height:0;width:auto;}',
    '#sfaf-chat a{text-decoration:none;box-shadow:none;}',
    '#sfaf-chat svg{display:block;fill:none;stroke:currentColor;stroke-width:2;',
    '  stroke-linecap:round;stroke-linejoin:round;}',
    '#sfaf-chat :focus-visible{outline:3px solid var(--sfaf-focus);outline-offset:2px;}',

    // Launcher
    '#sfaf-chat .sfaf-launcher{display:inline-flex;align-items:center;gap:8px;cursor:pointer;',
    '  background:var(--sfaf-launcher-bg);color:var(--sfaf-launcher-fg);border:0;',
    '  border-radius:999px;padding:12px 20px 12px 16px;font-weight:600;font-size:15px;',
    '  box-shadow:var(--sfaf-shadow);transition:background .15s,transform .15s;}',
    '#sfaf-chat .sfaf-launcher:hover{background:var(--sfaf-accent-dark);transform:translateY(-1px);}',
    '#sfaf-chat .sfaf-launcher svg{width:20px;height:20px;}',
    '#sfaf-chat.sfaf-open .sfaf-launcher{display:none;}',

    // Panel
    '#sfaf-chat .sfaf-panel{display:none;flex-direction:column;width:380px;height:600px;',
    '  max-height:calc(100vh - 40px);background:var(--sfaf-panel-bg);border-radius:14px;',
    '  overflow:hidden;box-shadow:var(--sfaf-shadow);border:1px solid var(--sfaf-border);',
    '  animation:sfaf-in .18s ease-out;}',
    '#sfaf-chat.sfaf-open .sfaf-panel{display:flex;}',
    '@keyframes sfaf-in{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:none;}}',

    '#sfaf-chat .sfaf-header{display:flex;align-items:center;justify-content:space-between;',
    '  background:var(--sfaf-header-bg);color:var(--sfaf-header-fg);padding:14px 12px 14px 18px;}',
    '#sfaf-chat .sfaf-title{font-size:16px;font-weight:700;color:var(--sfaf-header-fg);}',
    '#sfaf-chat .sfaf-close{background:transparent;border:0;color:var(--sfaf-header-fg);',
    '  cursor:pointer;padding:6px;border-radius:6px;display:flex;}',
    '#sfaf-chat .sfaf-close:hover{background:rgba(255,255,255,.12);}',
    '#sfaf-chat .sfaf-close svg{width:20px;height:20px;}',

    '#sfaf-chat .sfaf-messages{flex:1;overflow-y:auto;background:var(--sfaf-body-bg);',
    '  padding:16px 14px;display:flex;flex-direction:column;gap:10px;overscroll-behavior:contain;}',
    // Without this, overflow:hidden children (cards) shrink to nothing once the log scrolls.
    '#sfaf-chat .sfaf-messages>*{flex-shrink:0;}',

    // Bubbles
    '#sfaf-chat .sfaf-msg{max-width:85%;padding:10px 13px;border-radius:14px;',
    '  white-space:pre-wrap;overflow-wrap:anywhere;}',
    '#sfaf-chat .sfaf-bot{align-self:flex-start;background:var(--sfaf-bot-bg);color:var(--sfaf-bot-fg);',
    '  border:1px solid var(--sfaf-border);border-bottom-left-radius:4px;}',
    '#sfaf-chat .sfaf-user{align-self:flex-end;background:var(--sfaf-user-bg);color:var(--sfaf-user-fg);',
    '  border-bottom-right-radius:4px;}',
    '#sfaf-chat .sfaf-error{background:var(--sfaf-error-bg);border-color:var(--sfaf-error-border);}',
    '#sfaf-chat .sfaf-msg a.sfaf-tel{color:var(--sfaf-accent-dark);text-decoration:underline;font-weight:600;}',
    '#sfaf-chat a.sfaf-tel{white-space:nowrap;}',

    // Link button
    '#sfaf-chat .sfaf-linkbtn{align-self:flex-start;display:inline-flex;align-items:center;gap:6px;',
    '  max-width:85%;background:var(--sfaf-accent-tint);color:var(--sfaf-accent-dark);',
    '  border:1px solid var(--sfaf-accent);border-radius:999px;padding:7px 14px;font-weight:600;',
    '  font-size:14px;transition:background .15s;}',
    '#sfaf-chat .sfaf-linkbtn:hover{background:#d3edf0;}',
    '#sfaf-chat .sfaf-linkbtn svg{width:15px;height:15px;flex:none;}',

    // Card
    '#sfaf-chat .sfaf-card{align-self:flex-start;width:85%;background:var(--sfaf-bot-bg);',
    '  border:1px solid var(--sfaf-border);border-radius:12px;overflow:hidden;}',
    '#sfaf-chat .sfaf-card img{display:block;width:100%;height:140px;object-fit:cover;',
    '  background:var(--sfaf-accent-tint);max-width:none;border:0;}',
    '#sfaf-chat .sfaf-card-body{padding:12px 14px 14px;}',
    '#sfaf-chat .sfaf-card-kind{font-size:12px;font-weight:700;color:var(--sfaf-accent-dark);',
    '  text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;}',
    '#sfaf-chat .sfaf-card-title{font-weight:700;font-size:15px;color:var(--sfaf-text);}',
    '#sfaf-chat .sfaf-card-sub{color:var(--sfaf-muted);font-size:13px;margin-top:3px;}',
    '#sfaf-chat .sfaf-card-cta{display:inline-block;margin-top:10px;background:var(--sfaf-accent-dark);',
    '  color:#fff;border-radius:999px;padding:7px 14px;font-weight:600;font-size:14px;}',
    '#sfaf-chat .sfaf-card-cta:hover{background:var(--sfaf-header-bg);}',

    // Typing indicator
    '#sfaf-chat .sfaf-typing{display:inline-flex;gap:4px;align-items:center;padding:13px 14px;}',
    '#sfaf-chat .sfaf-typing span{width:7px;height:7px;border-radius:50%;background:var(--sfaf-muted);',
    '  opacity:.4;animation:sfaf-dot 1.2s infinite ease-in-out;}',
    '#sfaf-chat .sfaf-typing span:nth-child(2){animation-delay:.15s;}',
    '#sfaf-chat .sfaf-typing span:nth-child(3){animation-delay:.3s;}',
    '@keyframes sfaf-dot{0%,80%,100%{opacity:.3;transform:none;}40%{opacity:1;transform:translateY(-3px);}}',

    // Input row
    '#sfaf-chat .sfaf-form{display:flex;gap:8px;padding:10px;border-top:1px solid var(--sfaf-border);',
    '  background:var(--sfaf-panel-bg);}',
    '#sfaf-chat .sfaf-input{flex:1;min-width:0;border:1px solid var(--sfaf-border);border-radius:999px;',
    '  padding:10px 14px;background:#fff;color:var(--sfaf-text);font-size:16px;outline:none;height:auto;}',
    '#sfaf-chat .sfaf-input:focus{border-color:var(--sfaf-accent);box-shadow:0 0 0 3px var(--sfaf-accent-tint);}',
    '#sfaf-chat .sfaf-send{flex:none;width:44px;height:44px;border-radius:50%;border:0;cursor:pointer;',
    '  background:var(--sfaf-accent);color:#fff;display:flex;align-items:center;justify-content:center;',
    '  transition:background .15s;}',
    '#sfaf-chat .sfaf-send:hover{background:var(--sfaf-accent-dark);}',
    '#sfaf-chat .sfaf-send:disabled{opacity:.5;cursor:default;}',
    '#sfaf-chat .sfaf-send svg{width:20px;height:20px;}',

    '#sfaf-chat .sfaf-foot{font-size:11.5px;color:var(--sfaf-muted);text-align:center;',
    '  padding:0 10px 9px;background:var(--sfaf-panel-bg);}',
    '#sfaf-chat .sfaf-foot a{color:var(--sfaf-muted);text-decoration:underline;}',

    '#sfaf-chat .sfaf-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);',
    '  white-space:nowrap;}',

    // Phones: full-screen panel.
    '@media (max-width:480px){',
    '  #sfaf-chat{right:12px;bottom:12px;}',
    '  #sfaf-chat.sfaf-open{inset:0;right:0;bottom:0;}',
    '  #sfaf-chat .sfaf-panel{width:100%;height:100%;max-height:none;border-radius:0;border:0;}',
    '}',

    '@media (prefers-reduced-motion:reduce){',
    '  #sfaf-chat *,#sfaf-chat *::before,#sfaf-chat *::after{animation:none !important;transition:none !important;}',
    '  #sfaf-chat .sfaf-typing span{opacity:.7;}',
    '}'
  ].join('\n');

  /* ============================ SFAF ALLOWLIST ============================ */
  // Card images only from SFAF domains. KEEP IN STEP with sfaf_is_sfaf_url() in
  // bootstrap.php: sfaf.org, *.sfaf.org, tweaker.org (+ www). HTTPS only.
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

  var ICON_CHAT = ['M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z'];
  var ICON_CLOSE = ['M18 6 6 18', 'M6 6l12 12'];
  var ICON_SEND = ['M22 2 11 13', 'M22 2l-7 20-4-9-9-4 20-7z'];
  var ICON_ARROW = ['M7 17 17 7', 'M8 7h9v9'];

  var style = el('style');
  style.textContent = CSS;
  document.head.appendChild(style);

  var root = el('div');
  root.id = 'sfaf-chat';

  var launcher = el('button', 'sfaf-launcher');
  launcher.type = 'button';
  launcher.setAttribute('aria-haspopup', 'dialog');
  launcher.setAttribute('aria-expanded', 'false');
  launcher.appendChild(svg(ICON_CHAT));
  launcher.appendChild(el('span', null, 'Ask a question'));

  var panel = el('div', 'sfaf-panel');
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-modal', 'false');
  panel.setAttribute('aria-labelledby', 'sfaf-chat-title');

  var header = el('div', 'sfaf-header');
  var title = el('h2', 'sfaf-title', 'SFAF Assistant');
  title.id = 'sfaf-chat-title';
  var closeBtn = el('button', 'sfaf-close');
  closeBtn.type = 'button';
  closeBtn.setAttribute('aria-label', 'Close chat');
  closeBtn.appendChild(svg(ICON_CLOSE));
  header.appendChild(title);
  header.appendChild(closeBtn);

  var messages = el('div', 'sfaf-messages');
  messages.setAttribute('role', 'log');
  messages.setAttribute('aria-live', 'polite');
  messages.setAttribute('aria-relevant', 'additions');

  var form = el('form', 'sfaf-form');
  form.setAttribute('novalidate', '');
  var label = el('label', 'sfaf-sr', 'Your question');
  label.setAttribute('for', 'sfaf-chat-input');
  var input = el('input', 'sfaf-input');
  input.id = 'sfaf-chat-input';
  input.type = 'text';
  input.maxLength = 600;
  input.autocomplete = 'off';
  input.placeholder = 'Type your question…';
  var sendBtn = el('button', 'sfaf-send');
  sendBtn.type = 'submit';
  sendBtn.setAttribute('aria-label', 'Send');
  sendBtn.appendChild(svg(ICON_SEND));
  form.appendChild(label);
  form.appendChild(input);
  form.appendChild(sendBtn);

  var foot = el('div', 'sfaf-foot');
  foot.appendChild(document.createTextNode('Automated assistant · not medical advice · call '));
  foot.appendChild(telLink(PHONE));
  foot.appendChild(document.createTextNode(' for help'));

  panel.appendChild(header);
  panel.appendChild(messages);
  panel.appendChild(form);
  panel.appendChild(foot);
  root.appendChild(launcher);
  root.appendChild(panel);
  document.body.appendChild(root);

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

  /* ============================ CONVERSATION ============================== */

  // In memory only. Each item: {role: "user"|"assistant", text}.
  var thread = [];
  var busy = false;
  var inflight = null;   // AbortController of the current request
  var session = 0;       // bumps on close, so a late reply can't land in a new thread

  function setBusy(on) {
    busy = on;
    sendBtn.disabled = on;
    input.setAttribute('aria-busy', on ? 'true' : 'false');
  }

  function ask(question) {
    var history = thread.slice(-MAX_HISTORY);
    var mySession = session;

    addBubble('user', question);
    setBusy(true);
    showTyping(true);

    var ctrl = window.AbortController ? new AbortController() : null;
    inflight = ctrl;
    var timer = setTimeout(function () { if (ctrl) ctrl.abort(); }, REQUEST_TIMEOUT_MS);

    var headers = { 'Content-Type': 'application/json' };
    if (CONFIG.nonce) headers['X-WP-Nonce'] = CONFIG.nonce;

    fetch(PROXY_URL, {
      method: 'POST',
      headers: headers,
      body: JSON.stringify({ question: question, history: history, scope: SCOPE }),
      signal: ctrl ? ctrl.signal : undefined,
      credentials: CONFIG.nonce ? 'same-origin' : 'omit',
      mode: 'cors'
    })
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
      })
      .catch(function () {
        if (mySession !== session) return;
        showTyping(false);
        addBubble('bot', ERROR_TEXT, 'sfaf-error');
      })
      .then(function () {
        clearTimeout(timer);
        if (mySession !== session) return;
        inflight = null;
        setBusy(false);
        input.focus();
      });
  }

  /* ============================ OPEN / CLOSE ============================== */

  function open() {
    if (root.classList.contains('sfaf-open')) return;
    root.classList.add('sfaf-open');
    launcher.setAttribute('aria-expanded', 'true');
    if (!messages.childNodes.length) addBubble('bot', GREETING);
    input.focus();
  }

  // Closing clears the conversation — nothing is kept.
  function close() {
    if (!root.classList.contains('sfaf-open')) return;
    session++;
    if (inflight) { try { inflight.abort(); } catch (e) {} inflight = null; }
    thread = [];
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

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var q = input.value.trim();
    if (!q || busy) return;
    input.value = '';
    ask(q);
  });

  // Esc closes from anywhere inside the panel.
  root.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' || e.key === 'Esc') {
      e.stopPropagation();
      close();
    }
  });
})();
