// Nano Call - caller widget. The website visitor's view: pick a subject, add
// an optional note, tap Call. No account, no name, no phone book. Registers a
// throwaway visitor name and dials the configured business handle.

'use strict';

(async function () {
  const $ = (id) => document.getElementById(id);
  const screens = { intro: $('intro'), calling: $('calling'), inCall: $('inCall'), ended: $('ended') };
  const show = (name) => { for (const k in screens) screens[k].classList.toggle('hidden', k !== name); };

  let cfg = {};
  try { cfg = await NC.getConfig(); } catch { /* defaults below */ }
  ncApplyTheme(cfg.theme, cfg.accent);

  const brand = cfg.brandName || 'us';
  const ctaLabel = cfg.buttonLabel || ('Call ' + brand);
  document.title = 'Call ' + brand;
  $('brandName').textContent = brand;
  $('callingBrand').textContent = brand;
  $('greeting').textContent = cfg.greeting || '';
  const labelEl = $('callBtn').querySelector('.label');
  labelEl.textContent = ctaLabel;

  // brand identity: logo if set, otherwise an initial avatar
  $('brandAvatar').textContent = (brand.trim() || 'C').charAt(0);
  if (cfg.logo) { $('logo').src = cfg.logo; $('logo').classList.remove('hidden'); $('brandAvatar').classList.add('hidden'); }

  // subjects: default to the first so the visitor can call in one tap
  const subjects = (cfg.subjects && cfg.subjects.length) ? cfg.subjects : ['General enquiry'];
  const sel = $('subject');
  sel.innerHTML = '';
  subjects.forEach((s) => { const o = document.createElement('option'); o.value = s; o.textContent = s; sel.appendChild(o); });

  // a button can preselect a subject via ?subject= (e.g. a "Call Sales" link).
  // If it is not one of the configured subjects, honour it anyway by adding it.
  const wanted = new URLSearchParams(location.search).get('subject');
  if (wanted) {
    const match = Array.from(sel.options).find((o) => o.value.toLowerCase() === wanted.toLowerCase());
    if (match) { sel.value = match.value; }
    else { const o = document.createElement('option'); o.value = wanted; o.textContent = wanted; sel.insertBefore(o, sel.firstChild); sel.value = wanted; }
  }
  if (sel.options.length < 2) $('subjectRow').classList.add('hidden');   // nothing to choose

  // "Powered by Nano Call" - shown until a valid per-domain licence is set
  $('poweredBy').classList.toggle('hidden', !cfg.poweredBy);

  // ---------- live availability (offline notice) ----------
  // Disable Call and show a notice when the operator console is not open, so
  // visitors don't ring into nothing. Re-checks every few seconds in case the
  // business comes online while the popup is sitting there.
  let online = cfg.online !== false;
  function reflectOnline() {
    $('callBtn').disabled = !online;
    if (!online) introHint(brand + ' is offline right now. Please try again later.');
    else if ($('callHint').textContent.indexOf('offline') !== -1) introHint('');
  }
  async function refreshOnline() {
    try { const c = await NC.getConfig(); online = c.online !== false; } catch { /* keep last */ }
    reflectOnline();
  }
  reflectOnline();
  setInterval(() => { if (!document.hidden) refreshOnline(); }, 8000);

  // ---------- call timer ----------
  let timerId = null;
  function startTimer() {
    const t0 = Date.now(); stopTimer();
    timerId = setInterval(() => {
      const s = Math.floor((Date.now() - t0) / 1000);
      $('callTimer').textContent = String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
    }, 1000);
  }
  function stopTimer() { if (timerId) clearInterval(timerId); timerId = null; $('callTimer').textContent = '00:00'; }

  // a terminal reason (decline / unavailable / failure) is set just before the
  // core tears the call down and fires onEnded - so onEnded shows it rather
  // than overwriting it with the generic "Call ended."
  let endReason = '';
  function ended(msg) {
    stopTimer();
    $('endMsg').textContent = msg || 'Call ended.';
    show('ended');
  }

  NC.setHooks({
    onConnected: () => { show('inCall'); startTimer(); },
    onEnded: () => { ended(endReason); endReason = ''; },
    onReject: () => { endReason = 'The call was declined.'; },
    onUnavailable: () => { endReason = (cfg.brandName || 'They') + ' are not available right now. Please try again later.'; },
    onFailed: () => { endReason = 'Could not connect the audio. Please try again.'; },
  });

  // ---------- place the call ----------
  let placing = false;
  function introHint(msg) { $('callHint').textContent = msg || ''; }

  async function call() {
    if (placing) return;                 // stop double taps / button mashing
    if (!online) { reflectOnline(); return; }   // console offline - don't ring into nothing
    placing = true;
    $('callBtn').disabled = true;
    labelEl.textContent = 'Connecting…';
    introHint('');

    // FAILSAFE: the visitor must have a working mic (and, where detectable, a
    // speaker) before we ring the business at all. No kit = no dead call.
    const media = await ncCheckMedia();
    if (!media.mic || !media.speaker) {
      introHint(media.reason);
      $('callBtn').disabled = false;
      labelEl.textContent = ctaLabel;
      placing = false;
      return;                            // stay on intro, never rang the operator
    }

    const subject = sel.value || subjects[0];
    const note = $('note').value.trim().slice(0, 280);
    $('callingSubject').textContent = subject;
    show('calling');

    // throwaway, unguessable visitor name - never shown to the visitor
    const guest = 'visitor-' + Math.random().toString(36).slice(2, 8) + Math.random().toString(36).slice(2, 6);
    try {
      const r = await NC.registerGuest(guest);
      if (r.error) { ended('Could not connect. Please try again.'); return; }
      await NC.loadIce();
      NC.startPolling();
      await NC.dial(cfg.business, { subject, note });
    } catch (e) {
      ended(e && e.message === 'mic'
        ? 'Allow microphone access to make the call, then try again.'
        : 'Could not start the call. Please try again.');
    } finally {
      placing = false;
      $('callBtn').disabled = false;
      labelEl.textContent = ctaLabel;
    }
  }

  function setMuted(m) {
    NC.setMuted(m);
    $('muteBtn').setAttribute('aria-pressed', String(m));
    $('muteLabel').textContent = m ? 'Unmute' : 'Mute';
    document.querySelector('.ic-mic').classList.toggle('hidden', m);
    document.querySelector('.ic-mic-off').classList.toggle('hidden', !m);
  }

  function closeWidget() {
    // embedded popup: ask the host page to close the overlay
    try {
      if (window.parent && window.parent !== window) { window.parent.postMessage('nano-call:close', '*'); return; }
    } catch {}
    // standalone full-page tab (mobile fallback): close the tab if the browser
    // allows it (it was opened by script), otherwise leave the page as is
    try { window.close(); } catch {}
  }

  // ---------- wire up ----------
  $('callBtn').onclick = call;
  $('muteBtn').onclick = () => setMuted(NC.isMuted() ? false : true);
  $('hangBtn').onclick = () => { NC.end(true); ended('Call ended.'); };
  $('cancelBtn').onclick = () => { NC.end(true); closeWidget(); };
  $('againBtn').onclick = () => { $('note').value = ''; show('intro'); };
  document.querySelectorAll('.closeBtn').forEach((b) => { b.onclick = closeWidget; });

  show('intro');
})();
