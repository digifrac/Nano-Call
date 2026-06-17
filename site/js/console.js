// Nano Call - operator console. The business keeps this page open; it goes
// online as the configured business handle (admin password required) and
// answers calls from website visitors, showing their subject and note.

'use strict';

(async function () {
  const $ = (id) => document.getElementById(id);
  const screens = { login: $('login'), waiting: $('waiting'), incoming: $('incoming'), inCall: $('inCall') };
  const show = (name) => { for (const k in screens) screens[k].classList.toggle('hidden', k !== name); };

  let cfg = {};
  try { cfg = await NC.getConfig(); } catch { /* defaults below */ }
  ncApplyTheme(cfg.theme, cfg.accent);

  const brand = cfg.brandName || 'Nano Call';
  document.title = brand + ' - console';
  $('brandName').textContent = brand;
  $('waitBrand').textContent = brand;

  let pendingOffer = null;
  let audioCtx = null, ringTimer = null;

  // ---------- ringtone (WebAudio, no files) ----------
  function ringBurst() {
    if (!audioCtx) return;
    const osc = audioCtx.createOscillator(), gain = audioCtx.createGain();
    osc.type = 'sine'; osc.frequency.value = 880;
    gain.gain.setValueAtTime(0.0001, audioCtx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.15, audioCtx.currentTime + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.4);
    osc.connect(gain).connect(audioCtx.destination);
    osc.start(); osc.stop(audioCtx.currentTime + 0.45);
  }
  function startRing() { stopRing(); if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume(); ringBurst(); ringTimer = setInterval(ringBurst, 1500); }
  function stopRing() { if (ringTimer) clearInterval(ringTimer); ringTimer = null; }

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

  // desktop notification: shows even when the console tab is in the background
  // or behind another window, so a call is not missed without staring at it
  let liveNotif = null;
  function showNotif(subject, note) {
    try {
      if (!('Notification' in window) || Notification.permission !== 'granted') return;
      clearNotif();
      liveNotif = new Notification(brand + ' - incoming call', {
        body: note ? subject + ' · ' + note : subject,
        tag: 'nano-call-incoming',
        requireInteraction: true,
      });
      liveNotif.onclick = () => { try { window.focus(); } catch {} clearNotif(); };
    } catch { /* notifications unavailable */ }
  }
  function clearNotif() { if (liveNotif) { try { liveNotif.close(); } catch {} liveNotif = null; } }

  function incoming(msg) {
    pendingOffer = msg;
    $('callSubject').textContent = msg.subject || 'Call';
    $('callNote').textContent = msg.note ? '“' + msg.note + '”' : '';
    $('callNote').classList.toggle('hidden', !msg.note);
    startRing();
    show('incoming');
    showNotif(msg.subject || 'Call', msg.note || '');
  }

  NC.setHooks({
    onNet: (ok) => $('netDot').classList.toggle('on', ok),
    onTakenOver: () => { show('login'); $('loginHint').textContent = 'This handle went online elsewhere. Log in again to take it back.'; },
    onIncoming: incoming,
    onConnected: () => { stopRing(); show('inCall'); startTimer(); },
    onEnded: () => {
      stopRing(); stopTimer(); clearNotif(); pendingOffer = null;
      $('muteBtn').setAttribute('aria-pressed', 'false'); $('muteLabel').textContent = 'Mute';
      document.querySelector('.ic-mic').classList.remove('hidden');
      document.querySelector('.ic-mic-off').classList.add('hidden');
      if (NC.name) show('waiting'); else show('login');
    },
    onFailed: () => { show('waiting'); $('waitHint').textContent = 'That last call could not connect its audio - the visitor may be on a network that needs a TURN relay (see RELAY.md).'; },
  });

  // ---------- go online ----------
  async function goOnline() {
    const pass = $('adminPass').value;
    if (!pass) { $('loginHint').textContent = 'Enter your admin password.'; return; }
    // the click is the user gesture browsers require before audio/mic
    if (!audioCtx) audioCtx = new AudioContext();
    if (audioCtx.state === 'suspended') audioCtx.resume();
    // ask for desktop-notification permission now (this click is the gesture)
    if ('Notification' in window && Notification.permission === 'default') { try { Notification.requestPermission(); } catch {} }

    // FAILSAFE: the operator must have a working mic (and, where detectable, a
    // speaker) before going online, so a visitor is never connected to a dead
    // line. Same check the caller runs - both ends are verified.
    const media = await ncCheckMedia();
    if (!media.mic) { $('loginHint').textContent = 'Allow microphone access (and connect a mic + speakers) to take calls.'; return; }
    if (!media.speaker) { $('loginHint').textContent = media.reason; return; }

    $('goOnline').disabled = true;
    try {
      const out = await NC.registerHost(cfg.business, pass);
      if (out.error === 'bad-password') { loginError('Wrong password. Try again.'); }
      else if (out.error === 'no-admin') { $('loginHint').textContent = 'Not set up yet - open the admin page (/admin/) to create your password and settings.'; }
      else if (out.error) { $('loginHint').textContent = 'Could not go online (' + out.error + ').'; }
      else {
        $('adminPass').value = '';
        clearLoginError();
        await NC.loadIce();
        NC.startPolling();
        $('waitHint').textContent = '';
        show('waiting');
      }
    } catch { $('loginHint').textContent = 'Server not reachable.'; }
    $('goOnline').disabled = false;
  }

  // wrong/blocked password: red field + hint, clear and refocus, brief shake
  function loginError(msg) {
    const p = $('adminPass'), h = $('loginHint');
    h.textContent = msg; h.classList.add('isError');
    p.classList.add('bad'); p.value = ''; p.focus();
    p.classList.remove('shake'); void p.offsetWidth; p.classList.add('shake');
  }
  function clearLoginError() {
    $('loginHint').classList.remove('isError');
    $('adminPass').classList.remove('bad', 'shake');
  }

  // show/hide the password
  function togglePeek() {
    const p = $('adminPass'), btn = $('pwPeek'), show = p.type === 'password';
    p.type = show ? 'text' : 'password';
    btn.setAttribute('aria-pressed', String(show));
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    btn.title = show ? 'Hide password' : 'Show password';
    btn.querySelector('.ic-eye').classList.toggle('hidden', show);
    btn.querySelector('.ic-eye-off').classList.toggle('hidden', !show);
    p.focus();
  }

  function setMuted(m) {
    NC.setMuted(m);
    $('muteBtn').setAttribute('aria-pressed', String(m));
    $('muteLabel').textContent = m ? 'Unmute' : 'Mute';
    document.querySelector('.ic-mic').classList.toggle('hidden', m);
    document.querySelector('.ic-mic-off').classList.toggle('hidden', !m);
  }

  // ---------- wire up ----------
  $('goOnline').onclick = goOnline;
  $('pwPeek').onclick = togglePeek;
  $('adminPass').onkeydown = (e) => { if (e.key === 'Enter') goOnline(); };
  $('adminPass').addEventListener('input', clearLoginError);   // clear the error as they retype
  $('answerBtn').onclick = () => { stopRing(); clearNotif(); const o = pendingOffer; pendingOffer = null; NC.answer(o); };
  $('rejectBtn').onclick = () => { stopRing(); clearNotif(); NC.reject(); pendingOffer = null; show('waiting'); };
  $('muteBtn').onclick = () => setMuted(!NC.isMuted());
  $('hangBtn').onclick = () => NC.end(true);

  // closing the page hangs up the business line - warn while online
  window.addEventListener('beforeunload', (e) => { if (NC.name) { e.preventDefault(); e.returnValue = ''; } });

  show('login');
})();
