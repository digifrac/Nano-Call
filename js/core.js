// Nano Call - shared call core (signaling + WebRTC).
// Used by the operator console (console.js) and the caller widget (widget.js).
// Plain JavaScript, no framework. The audio is end to end encrypted by WebRTC
// (DTLS-SRTP); this file only shuttles handshake notes through signal.php.

'use strict';

const NC = (() => {
  // signal.php sits next to whichever page loaded core.js
  const BASE = (window.NANO_CALL_BASE || '');
  const SIGNAL_URL = BASE + 'signal.php';
  const POLL_MS = 1500;

  // echo cancellation / noise suppression / auto gain, forced to mono - the
  // browser canceller is markedly more reliable on a single channel
  const AUDIO = {
    echoCancellation: { ideal: true },
    noiseSuppression: { ideal: true },
    autoGainControl: { ideal: true },
    channelCount: { ideal: 1 },
  };

  // per-browser token so a reload is not mistaken for someone else
  const TOKEN_KEY = 'nano-call.token';
  let token = localStorage.getItem(TOKEN_KEY);
  if (!token) { token = crypto.randomUUID(); localStorage.setItem(TOKEN_KEY, token); }

  let rtc = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] };
  let pc = null, mic = null, myName = null, peerName = null;
  let pending = [], pollTimer = null, muted = false, hooks = {};
  let aud = null, outGain = null, gateTimer = null;   // outgoing audio graph + half-duplex duck

  const normName = (s) =>
    String(s).trim().toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9_-]/g, '').slice(0, 40);

  async function api(body) {
    const res = await fetch(SIGNAL_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...body, token, me: myName || body.me }),
    });
    if (!res.ok) throw new Error('http ' + res.status);
    return res.json();
  }

  async function getConfig() {
    const out = await api({ action: 'config' });
    return out.config || {};
  }

  async function loadIce() {
    try {
      const ice = await api({ action: 'ice' });
      if (Array.isArray(ice.iceServers) && ice.iceServers.length) rtc = { iceServers: ice.iceServers };
      if (ice.relayOnly) rtc.iceTransportPolicy = 'relay';
    } catch { /* keep the STUN-only default */ }
  }

  // ---------- signaling poll loop ----------
  function startPolling() { stopPolling(); pollTimer = setInterval(poll, POLL_MS); poll(); }
  function stopPolling() { if (pollTimer) clearInterval(pollTimer); pollTimer = null; }

  async function poll() {
    if (!myName) return;
    let out;
    try { out = await api({ action: 'poll' }); hooks.onNet && hooks.onNet(true); }
    catch { hooks.onNet && hooks.onNet(false); return; }
    if (out.error === 'name-taken') { stopPolling(); myName = null; hooks.onTakenOver && hooks.onTakenOver(); return; }
    if ('peerOnline' in out) hooks.onPeerOnline && hooks.onPeerOnline(out.peerOnline);
    for (const m of out.messages || []) await handle(m);
  }

  async function handle(msg) {
    if (msg.type === 'hangup') { end(false); return; }
    if (msg.type === 'reject') { hooks.onReject && hooks.onReject(msg); end(false); return; }
    if (msg.sdp && msg.sdp.type === 'offer') {
      // one call at a time - a second caller while busy gets a polite decline,
      // and the active call's peer routing is left untouched
      if (pc) { send(msg.from, { type: 'reject' }); return; }
      peerName = msg.from; hooks.onIncoming && hooks.onIncoming(msg); return;
    }
    if (msg.sdp && msg.sdp.type === 'answer' && pc) { await pc.setRemoteDescription(msg.sdp); await flush(); return; }
    if (msg.candidate) {
      // candidates often arrive before the remote description is set - hold
      // them and apply once ready, or ICE never pairs
      if (pc && pc.remoteDescription && pc.remoteDescription.type) {
        try { await pc.addIceCandidate(msg.candidate); } catch { /* ignore */ }
      } else {
        pending.push(msg.candidate);
      }
    }
  }

  async function flush() {
    if (!pc) return;
    const q = pending; pending = [];
    for (const c of q) { try { await pc.addIceCandidate(c); } catch { /* ignore */ } }
  }

  async function send(to, msg) {
    try {
      const out = await api({ action: 'send', to, msg });
      if (out.error === 'unavailable') { hooks.onUnavailable && hooks.onUnavailable(out.who); end(false); }
    } catch { /* net hook already reflects trouble */ }
  }

  // ---------- WebRTC ----------
  async function setupPeer() {
    pc = new RTCPeerConnection(rtc);
    try {
      mic = await navigator.mediaDevices.getUserMedia({ audio: AUDIO });
    } catch {
      // no mic / permission denied - clean up so a retry is not blocked
      try { pc.close(); } catch { /* ignore */ }
      pc = null;
      throw new Error('mic');
    }

    // Route the mic through a gain node so the echo gate can DUCK it smoothly.
    // Toggling the track on/off (the old way) makes the far end hear choppy
    // cuts; a gentle gain ramp does not. Falls back to the raw track where
    // WebAudio is unavailable.
    let outStream = mic;
    try {
      const AC = window.AudioContext || window.webkitAudioContext;
      if (AC) {
        aud = new AC();
        if (aud.state === 'suspended') aud.resume();
        const srcNode = aud.createMediaStreamSource(mic);
        outGain = aud.createGain();
        outGain.gain.value = 1;
        const dest = aud.createMediaStreamDestination();
        srcNode.connect(outGain);
        outGain.connect(dest);
        outStream = dest.stream;
      }
    } catch { aud = null; outGain = null; outStream = mic; }
    outStream.getAudioTracks().forEach((t) => pc.addTrack(t, outStream));

    pc.ontrack = (e) => {
      const a = document.getElementById('ncRemoteAudio'); if (a) a.srcObject = e.streams[0];
      // Grow the jitter buffer a little so jittery mobile/relay paths play back
      // smoothly instead of cutting, at the cost of ~200ms of latency.
      try {
        const r = e.receiver || pc.getReceivers().find((x) => x.track && x.track.kind === 'audio');
        if (r) {
          if ('jitterBufferTarget' in r) r.jitterBufferTarget = 200;
          else if ('playoutDelayHint' in r) r.playoutDelayHint = 0.2;
        }
      } catch { /* not supported - keep the browser default buffer */ }
      startEchoGate(e.streams[0]);   // duck mic while the far end talks (anti-echo)
    };
    pc.onicecandidate = (e) => { if (e.candidate && peerName) send(peerName, { candidate: e.candidate }); };
    pc.onconnectionstatechange = () => {
      const st = pc.connectionState;
      if (st === 'connected') { hooks.onConnected && hooks.onConnected(); }
      else if (st === 'failed') { hooks.onFailed && hooks.onFailed(); end(false); }
      else if (st === 'disconnected') { end(false); }
    };
  }

  // caller side: create an offer, attaching whatever extra (subject/note) is given
  async function dial(target, extra) {
    if (pc) return;
    peerName = target;
    await setupPeer();
    const offer = await pc.createOffer();
    await pc.setLocalDescription(offer);
    await send(peerName, { sdp: pc.localDescription, ...(extra || {}) });
  }

  // operator side: answer a held offer
  async function answer(offerMsg) {
    if (pc || !offerMsg) return;
    peerName = offerMsg.from;
    await setupPeer();
    await pc.setRemoteDescription(offerMsg.sdp);
    await flush();
    const ans = await pc.createAnswer();
    await pc.setLocalDescription(ans);
    await send(peerName, { sdp: pc.localDescription });
  }

  function setMuted(m) { muted = m; if (mic) mic.getAudioTracks().forEach((t) => { t.enabled = !m; }); return muted; }
  function isMuted() { return muted; }

  // ---------- half-duplex echo gate ----------
  // Browser AEC handles mild speaker bleed, but a loud open speaker next to the
  // mic overwhelms it. So while the FAR end is audibly talking, we DUCK our own
  // mic (a smooth gain dip, not a hard cut) - their voice then cannot loop back
  // out our speakers and echo, and the far end never hears a choppy on/off. A
  // short hangover rides over natural pauses; it trades full double-talk for a
  // usable, echo-free call, the way hardware speakerphones do. Uses the WebAudio
  // gain node from setupPeer; without it we rely on the browser AEC alone.
  function startEchoGate(remoteStream) {
    if (gateTimer) { clearInterval(gateTimer); gateTimer = null; }
    if (!aud || !outGain) return;
    try {
      const src = aud.createMediaStreamSource(remoteStream);
      const an = aud.createAnalyser();
      an.fftSize = 512;
      src.connect(an);                       // analysis only - not routed to output
      const buf = new Uint8Array(an.fftSize);
      let openUntil = 0;
      gateTimer = setInterval(() => {
        an.getByteTimeDomainData(buf);
        let s = 0;
        for (let i = 0; i < buf.length; i++) { const d = buf[i] - 128; s += d * d; }
        const rms = Math.sqrt(s / buf.length);
        const now = Date.now();
        if (rms > 10) openUntil = now + 200;   // far end talking -> duck mic ~200ms
        const duck = now < openUntil;
        // smooth ramp instead of a hard cut; mute is handled on the source track
        outGain.gain.setTargetAtTime(duck ? 0.08 : 1, aud.currentTime, 0.04);
      }, 60);
    } catch { /* WebAudio unavailable - fall back to browser AEC alone */ }
  }
  function stopEchoGate() {
    if (gateTimer) { clearInterval(gateTimer); gateTimer = null; }
    if (aud) { try { aud.close(); } catch { /* ignore */ } aud = null; outGain = null; }
  }

  function end(tellPeer = true) {
    if (tellPeer && peerName) send(peerName, { type: 'hangup' });
    stopEchoGate();
    if (pc) { pc.close(); pc = null; }
    if (mic) { mic.getTracks().forEach((t) => t.stop()); mic = null; }
    muted = false; pending = [];
    const had = peerName; peerName = null;
    hooks.onEnded && hooks.onEnded(had);
  }

  // ---------- registration ----------
  async function registerHost(name, password) {
    myName = null;
    const out = await api({ action: 'register-host', me: name, password });
    if (out.registered) myName = out.registered;
    return out;
  }
  async function registerGuest(name) {
    const out = await api({ action: 'register', me: name });
    if (out.registered) myName = out.registered;
    return out;
  }

  // decline a held incoming offer: tell the caller, then drop it
  function reject() { if (peerName) send(peerName, { type: 'reject' }); peerName = null; pending = []; }

  return {
    normName, getConfig, loadIce, startPolling, stopPolling,
    dial, answer, reject, setMuted, isMuted, end, registerHost, registerGuest,
    setHooks: (h) => { hooks = h; },
    get name() { return myName; },
    BASE,
  };
})();

// ---------- shared media pre-flight ----------
// Both ends must have a working microphone before a call can connect, and a
// playback device where the browser can tell us. The caller runs this BEFORE
// the operator is ever rung (no mic = no offer sent), and the operator runs it
// before going online - so a "button pusher" with no audio kit cannot start or
// land a dead call. Returns { mic: bool, speaker: bool, reason: string }.
async function ncCheckMedia() {
  let stream;
  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true });
  } catch {
    return { mic: false, speaker: false, reason: 'Allow microphone access (and connect a mic) to make a call.' };
  }
  // granting the mic also unlocks device labels/kinds for the check below
  stream.getTracks().forEach((t) => t.stop());

  // best-effort speaker check. Reliable only where the browser enumerates
  // audio outputs (Chromium/Edge expose setSinkId); elsewhere we cannot tell,
  // so we do not block on it.
  let speaker = true;
  try {
    if ('setSinkId' in HTMLMediaElement.prototype && navigator.mediaDevices.enumerateDevices) {
      const devs = await navigator.mediaDevices.enumerateDevices();
      const outs = devs.filter((d) => d.kind === 'audiooutput');
      if (outs.length === 0) speaker = false;
    }
  } catch { /* leave speaker = true */ }

  return {
    mic: true,
    speaker,
    reason: speaker ? '' : 'No speaker or headphones detected. Turn your sound on, then try again.',
  };
}

// ---------- shared theme helper ----------
// From one brand hex we derive the whole accent set so a custom colour stays
// coherent: a darker press shade, a readable ink (the text ON the accent), a
// text shade with enough contrast on the page surface, and a translucent ring.
function ncHexToRgb(hex) {
  const m = /^#?([0-9a-f]{6})$/i.exec(String(hex || '').trim());
  if (!m) return null;
  const n = parseInt(m[1], 16);
  return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
}
function ncMix(rgb, target, amt) {                 // amt 0..1 toward target (0 or 255)
  const f = (c) => Math.round(c + (target - c) * amt);
  return { r: f(rgb.r), g: f(rgb.g), b: f(rgb.b) };
}
function ncCss(rgb) { return 'rgb(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ')'; }
function ncLum(rgb) {                               // relative luminance (sRGB)
  const ch = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
  return 0.2126 * ch(rgb.r) + 0.7152 * ch(rgb.g) + 0.0722 * ch(rgb.b);
}

function ncApplyTheme(theme, accent) {
  if (theme === 'light' || theme === 'dark') {
    document.documentElement.setAttribute('data-theme', theme);
  }
  const rgb = ncHexToRgb(accent);
  if (!rgb) return;

  const dark = theme === 'dark'
    || (theme !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches);

  const press = ncMix(rgb, 0, 0.14);                              // 14% toward black
  const ink   = ncLum(rgb) > 0.45 ? '#10131a' : '#ffffff';        // text ON the accent
  const text  = dark ? ncMix(rgb, 255, 0.3) : ncMix(rgb, 0, 0.22); // accent text on the surface

  const s = document.documentElement.style;
  s.setProperty('--accent', ncCss(rgb));
  s.setProperty('--accent-press', ncCss(press));
  s.setProperty('--accent-ink', ink);
  s.setProperty('--accent-text', ncCss(text));
  s.setProperty('--ring', 'rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',0.22)');
}
