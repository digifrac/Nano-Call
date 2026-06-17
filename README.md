# Nano Call

A one-click **"Call us"** button for any website. A visitor taps it, picks why they are calling, and talks to the business live in the browser. Free, encrypted, peer to peer. Hand built, no frameworks, no Node, no database. Runs on any ordinary PHP web host.

- **Caller** sees a button (floating or inline), a short reason picker, and a Call button. No account, no app, nothing to install.
- **Operator** (the business) keeps one page open as their desk phone and answers incoming calls, with the caller's subject and note shown before they pick up.
- **The voice call never touches your server** - audio flows directly between the two browsers (WebRTC, Opus, end to end encrypted). That is what keeps it free to run.

> **Operator setup (read this):** like any phone line, the person answering should use a **headset** - or run the console on a phone. Open desk speakers sitting next to a microphone cause echo (a browser cannot fully cancel it); a headset removes it completely. Website callers are usually on a phone and sound clean either way. Details in [Audio: echo, and why you want a headset](#audio-echo-and-why-you-want-a-headset).

## The three pieces

| Piece | File | Who uses it |
| --- | --- | --- |
| **Admin** | `admin/` | You, once, to set it up. **Removable** afterwards (delete the folder). |
| **Operator console** | `index.html` | The business - kept open all day to receive calls. |
| **Caller widget** | `js/embed.js` + `widget.html` | Website visitors - the button + call popup. |

## Put it live

1. Put the frontend into a folder called `phone/` on your host - unzip **nano-call-frontend.zip**, which unpacks to `phone/`. Then add the admin by unzipping **nano-call-admin.zip** into it, giving you `phone/admin/`. Both live together in `phone/` until you remove admin (step "Harden it").
2. Make sure the `data/` folder is writable by PHP (most hosts: already is; otherwise set it to 755).
3. Visit `https://yoursite.com/phone/admin/` and:
   - create an **admin password** (this also protects the operator console),
   - set the **business handle**, display name, button label, accent, greeting,
   - list your **call subjects** (one per line - the first is pre-selected),
   - set the **Site URL** (where Nano Call is installed - this binds your licence),
   - paste a **licence key** if you have one (removes the "Powered by" line),
   - copy the **embed snippet** it shows you.
4. Open `https://yoursite.com/phone/` (the console), enter the admin password, **Go online**, and leave the tab open.
5. Paste the embed snippet onto the website that should have the button.

HTTPS is required (browsers only allow microphone access over HTTPS). Your host already has it.

> **You will most likely need a relay.** Calls connect browser to browser, and many real visitors cannot connect directly - on mobile data, on office or public Wi-Fi, or behind strict firewalls. When that happens the call fails to connect (or rings then drops). The fix is a TURN relay. A test call on your own network may work without one and still fail for real visitors, so treat a relay as expected, not optional, for a live deployment. Full setup (self-hosted, free managed, or relay-only privacy mode) is in **[RELAY.md](RELAY.md)**.

### Harden it

Once configured, you can **delete the `admin/` folder** from the host - the call line keeps running off your saved settings in `data/`. Re-upload it whenever you want to change settings.

## Add the button to a site

The embed script gives you two styles - use either or both, on any website:

**Floating button** (parks in a page corner):

```html
<script src="https://yoursite.com/phone/js/embed.js" data-nano-call="floating"></script>
```

**Inline button** (renders exactly where you drop the placeholder):

```html
<script src="https://yoursite.com/phone/js/embed.js"></script>
<span data-nano-call-button></span>
```

Both open the same call popup. Branding, label, position and the "Powered by" line come from your admin settings; `data-label` / `data-position` on the tag can override per placement. The popup is an iframe served from your Nano Call host, so the microphone prompt is for your domain.

## How a call flows

```
Visitor (any site)        signal.php              Operator console
  | taps Call, picks         |                        |
  | subject + note           |                        |
  | offer (SDP + subject) --> |  (waits in mailbox)    |
  |                          | <-- console polls, sees |
  |                          |     subject + note,     |
  |                          |     answers (SDP) <-----|
  | <----- ICE candidates -> | <---- ICE candidates -->|
  |==========  P2P audio (Opus) - direct  =============|
```

Browsers poll `signal.php` every ~1.5s, so a call takes a couple of seconds to ring. Once connected, audio is direct peer to peer.

## Licence (per domain)

Nano Call is **MIT licensed** (see `LICENSE`) - the code is free to use, and free to run with the small **"Powered by Nano Call"** line shown. To remove that line, buy a **per-domain licence key for £19 (one off, per domain)**. The line shows under the button and in the call popup until a valid key for that domain is set.

- Verification is **local and cryptographic** (Ed25519, embedded public key) - no phone-home, no network call.
- The licence binds to the host in your **Site URL** setting (never the request header, so it cannot be spoofed). `www.` is treated the same as the bare domain.
- Dev hosts (`localhost`, `*.test`, `*.local`, anything with a port) never show the line.
- The licence covers the **software only** - it does not include relay hosting. Bring your own TURN relay (see below), or take our optional managed-relay add-on (£5/month).
- Licences are minted with the private `nano-licence-tools` toolkit (`--product=nano-call`).

## Relay server (TURN) - REQUIRED for real-world calls

Calls connect browser to browser. Many real connections cannot go direct (mobile data, strict firewalls, two devices behind one router) and need a TURN relay, or they fail with "ICE failed". This is the one piece that cannot run on PHP hosting.

Three options - self-hosted coturn, the free Metered relay, or any managed TURN - plus a relay-only privacy mode that hides both callers' IPs. **Full setup is in [RELAY.md](RELAY.md).** Start there the moment a real call will not connect.

**Bring your own relay.** The £19 licence covers the software; running a relay is yours to arrange - self-host coturn on a small VPS/seedbox, or use Metered's free tier. RELAY.md walks through both. If you would rather not run one, we also offer an **optional managed relay** for **£5/month** (we host coturn for you) - [get in touch](mailto:gruda@hotmail.co.uk) to set it up.

## Audio: echo, and why you want a headset

Nano Call runs in the browser, so it can only use the browser's built-in echo cancellation. That handles mild cases, but it is weaker than a native app's. Read this before you judge the call quality.

- **The cause of echo is always the same:** a speaker plays the caller's voice out loud, the open microphone picks it back up, and the caller hears themselves. The worst case is a desktop PC with open speakers and a far-field mic (a webcam mic especially), in an empty room.
- **The guaranteed fix is a headset.** Any headphones or earbuds, wired or Bluetooth, even a single earbud, removes the loop completely because the audio goes into your ear instead of the room. This is why call desks use headsets, not desk speakers. The operator should wear one while manning the line - the ringtone plays in the headset too, so calls are not missed.
- **No headset? Practical alternatives:**
  - **Run the operator console on a phone or tablet.** Phones have hardware echo cancellation (the same thing that makes WhatsApp clean on speakerphone), so a phone kept open as the console handles speaker echo well. It also rings out loud.
  - **Turn the speaker volume right down.** The browser's canceller copes far better when the speakers are quiet.
  - **Separate the mic and speakers**, and point the speakers away from the mic.
- **What Nano Call already does:** echo cancellation, noise suppression and auto gain are on for every call, plus a half-duplex gate that briefly closes your mic while the caller is talking so their voice cannot loop back. This makes a single-speaker side usable, but it cannot make a loud open speaker next to a live mic fully echo-free - no browser app can. That is a limit of the web platform, not a bug.
- **Why a native app (WhatsApp, Zoom desktop) does better:** it gets a sample-accurate copy of the speaker output and taps the operating system and audio driver echo cancellation. A web page in a sandbox cannot reach that audio plumbing.

Bottom line: for a business line, give the operator a cheap headset, or run the console on a phone. Then it is clean.

## Privacy and security

- **Audio is end to end encrypted** (WebRTC DTLS-SRTP). Your host never hears it, the optional TURN relay only ever sees encrypted data. Automatic, not optional.
- **The operator console is password protected.** Only someone with the admin password can put the business handle online, so a stranger cannot grab your line and intercept calls.
- **No accounts, numbers, or call logs.** The server stores only the business config, a per-browser token, and transient call notes. Visitors are anonymous throwaway names, swept automatically.
- **Direct calls reveal IP addresses** to the other party (inherent to peer to peer). To hide them, set `$RELAY_ONLY = true` in `signal.php` (needs a TURN server) - all audio then routes through the relay.

## Test locally

With PHP installed (e.g. XAMPP):

```
php -S localhost:8090
```

Open `http://localhost:8090/admin/` to set up, then `http://localhost:8090/` for the console. The caller widget is at `http://localhost:8090/widget.html`.
