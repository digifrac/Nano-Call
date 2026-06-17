/* Nano Call - embed loader.
 *
 * Paste ONE of these on any website:
 *
 *   Floating button (parks in a corner):
 *     <script src="https://YOURHOST/call/js/embed.js" data-nano-call="floating"></script>
 *
 *   Inline button (renders where you drop the placeholder):
 *     <script src="https://YOURHOST/call/js/embed.js"></script>
 *     ... then anywhere in the page:
 *     <span data-nano-call-button></span>
 *
 * Both open the same caller popup (widget.html) in an overlay. You can use
 * both at once. Branding, label, position and the "Powered by" line come from
 * the server config; data-* attributes can override label/position locally.
 */
(function () {
  'use strict';

  var script = document.currentScript;
  if (!script) return;
  // site root, e.g. .../call/ - strip the js/ folder this script lives in
  // (still works if embed.js is served from the root, for compatibility)
  var base = script.src.replace(/(?:js\/)?embed\.js(\?.*)?$/, '');
  var widgetUrl = base + 'widget.html';
  var mode = (script.getAttribute('data-nano-call') || '').toLowerCase();   // 'floating' | 'inline' | ''

  // ---------- styles (scoped to .nanocall- classes) ----------
  var css = ''
    + '.nanocall-launch{position:fixed;z-index:2147483000;display:inline-flex;flex-direction:column;align-items:stretch;gap:6px;font-family:system-ui,"Segoe UI",sans-serif}'
    + '.nanocall-launch.br{right:20px;bottom:20px}.nanocall-launch.bl{left:20px;bottom:20px}'
    + '.nanocall-launch.tr{right:20px;top:20px}.nanocall-launch.tl{left:20px;top:20px}'
    + '.nanocall-btn{display:inline-flex;align-items:center;gap:9px;border:0;border-radius:999px;cursor:pointer;'
    + 'font:600 15px/1 system-ui,"Segoe UI",sans-serif;color:#fff;background:#ff4d00;padding:13px 64px;'
    + 'box-shadow:0 6px 20px rgba(0,0,0,.18);transition:transform .12s,filter .12s}'
    + '.nanocall-btn:hover{filter:brightness(1.05)}.nanocall-btn:active{transform:scale(.97)}'
    + '.nanocall-btn svg{width:18px;height:18px;fill:currentColor;flex:none}'
    + '.nanocall-inline{display:inline-flex}'
    + '.nanocall-pb{font:400 11px/1.3 system-ui,sans-serif;color:#888;text-align:center;margin:0}'
    + '.nanocall-pb a{color:#888;text-decoration:none}.nanocall-pb a:hover{text-decoration:underline}'
    + '.nanocall-overlay{position:fixed;inset:0;z-index:2147483600;display:none;align-items:center;justify-content:center;'
    + 'background:rgba(10,12,16,.55);backdrop-filter:blur(2px);padding:16px}'
    + '.nanocall-overlay.open{display:flex}'
    + '.nanocall-frameWrap{position:relative;width:min(380px,96vw);height:min(600px,92vh);'
    + 'border-radius:18px;overflow:hidden;box-shadow:0 24px 70px rgba(0,0,0,.5);background:#fff}'
    + '.nanocall-frameWrap iframe{width:100%;height:100%;border:0;display:block}';
  var styleEl = document.createElement('style');
  styleEl.textContent = css;
  document.head.appendChild(styleEl);

  var PHONE_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 10.8c1.4 2.7 3.9 5.2 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.2.2 2.4.6 3.6.1.3 0 .7-.2 1l-2.3 2.2z"/></svg>';

  // ---------- the shared overlay (built once, on first open) ----------
  function widgetUrlWith(subject) {
    if (!subject) return widgetUrl;
    return widgetUrl + (widgetUrl.indexOf('?') >= 0 ? '&' : '?') + 'subject=' + encodeURIComponent(subject);
  }
  var overlay = null;
  function openOverlay(subject) {
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.className = 'nanocall-overlay';
      overlay.innerHTML = '<div class="nanocall-frameWrap">'
        + '<iframe allow="microphone; autoplay" title="Call us"></iframe>'
        + '</div>';
      document.body.appendChild(overlay);
      overlay.addEventListener('click', function (e) { if (e.target === overlay) closeOverlay(); });
    }
    var frame = overlay.querySelector('iframe');
    frame.src = widgetUrlWith(subject);        // (re)load with the chosen subject
    overlay.classList.add('open');
  }
  function closeOverlay() {
    if (!overlay) return;
    overlay.classList.remove('open');
    // reset so the next open starts fresh (and any call is torn down)
    var frame = overlay.querySelector('iframe');
    frame.src = ''; setTimeout(function () { frame.src = ''; }, 0);
  }
  // the widget asks us to close when the visitor finishes
  window.addEventListener('message', function (e) { if (e.data === 'nano-call:close') closeOverlay(); });

  // On phones we open the widget as its OWN first-party page instead of an
  // in-page cross-origin iframe. Microphone permission in a third-party iframe
  // is unreliable on iOS Safari (and fiddly on small screens), but a top-level
  // page on the Nano Call host always gets a normal mic prompt.
  function isMobile() {
    var ua = navigator.userAgent || '';
    // iPadOS reports as "Macintosh" but is touch-capable
    var ios = /iP(hone|od|ad)/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
    var coarseNarrow = false;
    try {
      coarseNarrow = window.matchMedia('(pointer: coarse)').matches
        && Math.min(window.innerWidth || 9999, window.innerHeight || 9999) <= 820;
    } catch (e) { /* matchMedia unsupported - fall through */ }
    return ios || coarseNarrow;
  }

  function launch(subject) {
    if (isMobile()) {
      // first-party full page (new tab) - keeps the client's site behind it
      window.open(widgetUrlWith(subject), '_blank');
    } else {
      openOverlay(subject);
    }
  }

  function makeButton(label, subject, cls, styleStr, block, guard) {
    var b = document.createElement('button');
    b.type = 'button';
    if (cls) {
      // inherit the host site's own button styling (no Nano Call pill, no icon).
      // Reset only the bits where a <button> differs from the host's <a> button
      // (UA border + font), so it renders identically to the site's own button.
      b.className = cls;
      b.textContent = label;
      b.style.cssText = 'border:0;cursor:pointer;font-family:inherit;font-size:inherit;line-height:inherit';
    } else {
      b.className = 'nanocall-btn';
      b.innerHTML = PHONE_SVG + '<span></span>';
      b.querySelector('span').textContent = label;
    }
    if (block) b.style.cssText += ';display:block;width:100%';   // full-width block button
    if (styleStr) b.style.cssText += ';' + styleStr;             // optional per-button overrides
    b.addEventListener('click', function () {
      if (guard && !guard()) {
        console.warn('Nano Call: keep the "Powered by Nano Call" link visible, or add a licence for this domain to remove it.');
        return;   // unlicensed + attribution removed -> button does nothing
      }
      launch(subject);
    });
    return b;
  }
  function poweredByEl() {
    var p = document.createElement('p');
    p.className = 'nanocall-pb';
    p.innerHTML = 'Powered by <a href="https://www.digitalfracture.co.uk/nano.php" target="_blank" rel="noopener noreferrer">Nano Call</a>';
    return p;
  }

  // Unlicensed buttons only work while the attribution stays visible. A valid
  // per-domain licence sets cfg.poweredBy=false, which lifts the check entirely.
  function attribGuard(cfg, pb) {
    return function () {
      if (!cfg.poweredBy) return true;                            // licensed - always works
      if (!pb || !pb.isConnected || !pb.querySelector('a')) return false;
      var cs = window.getComputedStyle(pb);
      return cs.display !== 'none' && cs.visibility !== 'hidden' && parseFloat(cs.opacity || '1') >= 0.1;
    };
  }

  // ---------- build launchers once config arrives ----------
  function build(cfg) {
    var label = script.getAttribute('data-label') || cfg.buttonLabel || 'Call us';
    var accent = cfg.accent || '#ff4d00';
    var pos = (script.getAttribute('data-position') || cfg.position || 'bottom-right').toLowerCase();
    var posClass = { 'bottom-right': 'br', 'bottom-left': 'bl', 'top-right': 'tr', 'top-left': 'tl' }[pos] || 'br';

    // accent override for every Nano Call button on the page
    styleEl.textContent += '.nanocall-btn{background:' + accent + '}';

    var inlineTargets = document.querySelectorAll('[data-nano-call-button]');
    var wantFloating = (mode === 'floating' || mode === 'both') || (mode === '' && inlineTargets.length === 0);

    if (wantFloating) {
      var wrap = document.createElement('div');
      wrap.className = 'nanocall-launch ' + posClass;
      var fpb = cfg.poweredBy ? poweredByEl() : null;
      wrap.appendChild(makeButton(label, script.getAttribute('data-subject') || '', script.getAttribute('data-class') || '', script.getAttribute('data-style') || '', false, attribGuard(cfg, fpb)));
      if (fpb) wrap.appendChild(fpb);
      document.body.appendChild(wrap);
    }

    inlineTargets.forEach(function (t) {
      var block = t.hasAttribute('data-block');
      var holder = document.createElement('span');
      holder.className = 'nanocall-inline';
      holder.style.display = block ? 'block' : 'inline-flex';
      holder.style.flexDirection = 'column';
      holder.style.gap = '5px';
      var pb = cfg.poweredBy ? poweredByEl() : null;
      holder.appendChild(makeButton(t.getAttribute('data-label') || label, t.getAttribute('data-subject') || '', t.getAttribute('data-class') || '', t.getAttribute('data-style') || '', block, attribGuard(cfg, pb)));
      if (pb) holder.appendChild(pb);
      t.replaceWith(holder);
    });
  }

  // simple cross-origin GET (no preflight); fall back to defaults on failure
  fetch(base + 'signal.php?action=config')
    .then(function (r) { return r.json(); })
    .then(function (d) { build((d && d.config) || {}); })
    .catch(function () { build({}); });
})();
