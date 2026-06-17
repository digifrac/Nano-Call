# Relay server (TURN) setup

Everything about the one piece of Nano Call that does not run on your PHP host: the TURN relay. Most of the time it is the difference between "works on my desk" and "works for real callers", so it is worth getting right.

If you only want calls between two machines on good home/office networks, you can skip this entirely - the built-in Google STUN server is enough and no relay is used. Read on the moment a real call fails to connect.

## Contents

- [What a relay does and why you need one](#what-a-relay-does-and-why-you-need-one)
- [Why it cannot live on your web host](#why-it-cannot-live-on-your-web-host)
- [Pick an option](#pick-an-option)
- [Option A - self-hosted coturn (full walkthrough)](#option-a---self-hosted-coturn-full-walkthrough)
- [Option B - free Metered relay (no server admin)](#option-b---free-metered-relay-no-server-admin)
- [Option C - any other managed TURN](#option-c---any-other-managed-turn)
- [Point the app at your relay](#point-the-app-at-your-relay)
- [Relay-only mode (hide IP addresses)](#relay-only-mode-hide-ip-addresses)
- [How many ports do I need](#how-many-ports-do-i-need)
- [Test that it actually relays](#test-that-it-actually-relays)
- [Troubleshooting](#troubleshooting)
- [Cost and bandwidth](#cost-and-bandwidth)
- [Security notes](#security-notes)

## What a relay does and why you need one

Nano Call calls go browser to browser. WebRTC first tries to connect the two devices directly, with a little help from a STUN server (which just tells each browser its own public address). When both sides can reach each other directly, the relay is never touched and the call is free.

But many real connections cannot go direct:

- a phone on mobile data (carrier-grade NAT),
- two devices behind the same home router (hairpin NAT),
- a corporate or public-Wi-Fi firewall that blocks peer to peer,
- symmetric NAT, where each new connection gets a different port.

In those cases the audio has to pass through a relay - a **TURN** server - or the call simply fails (you will see `ICE failed` / `connectionState: failed` in the browser console, and the app shows "this network needs a TURN relay"). Every calling app (WhatsApp, Discord, Teams) relays a meaningful share of its calls the same way. For a deployment that real people will use, a relay is not optional.

The relay never gets to listen in: WebRTC audio is end to end encrypted (DTLS-SRTP), so the TURN server only ever forwards encrypted packets it cannot read.

## Why it cannot live on your web host

Shared PHP hosting only runs PHP when a web request arrives, and only exposes the web ports (80/443). A TURN server is a separate long-running program that listens on its own (mostly UDP) ports 24/7. PHP cannot be that program.

So you need a second machine where you control a service and can open UDP ports:

- a small **VPS** (the usual choice - a $5/month box is plenty for a handful of simultaneous calls), or
- a **seedbox / box** that gives you SSH, root, and a UDP port range (the reference deploy uses an Appbox), or
- a **managed TURN service** (Option B), where someone else runs the box for you.

`signal.php` keeps the relay's address and credential and hands short-lived details to browsers - that part runs fine on your normal PHP host. Only the relay itself lives elsewhere.

## Pick an option

| Option | You run a server? | Cost | Best when |
| --- | --- | --- | --- |
| **A. Self-hosted coturn** | Yes (VPS / seedbox) | Price of the box | You want full control and predictable cost |
| **B. Metered free relay** | No | Free tier, then paid | You do not want to admin a server |
| **C. Other managed TURN** | No | Varies | You already have a TURN provider |

You can configure both A and B at once - the app will offer all of them to the browser and WebRTC picks whatever connects.

## Option A - self-hosted coturn (full walkthrough)

[coturn](https://github.com/coturn/coturn) is the standard open-source TURN/STUN server. You need: a machine with a **public IP**, SSH/root, and the ability to open UDP ports - one listening port plus a range for relaying audio (one port per active call).

### 1. Install

On Ubuntu/Debian:

```bash
sudo apt update && sudo apt install -y coturn
```

### 2. Configure

Write `/etc/turnserver.conf`. Fill in YOUR public IP and YOUR own secret - generate a fresh random one (`openssl rand -hex 32`) and never reuse an example:

```bash
sudo tee /etc/turnserver.conf >/dev/null <<'EOF'
listening-port=15385                 # the one port browsers first connect to
min-port=15386                       # start of the relay range
max-port=15484                       # end of the relay range (100 ports = ~100 calls)
use-auth-secret                      # time-limited credentials (TURN REST API)
static-auth-secret=YOUR_LONG_SECRET  # the shared secret signal.php signs with
realm=nanophone
external-ip=YOUR_PUBLIC_IP           # the box's public IP
# --- abuse hardening ---
denied-peer-ip=10.0.0.0-10.255.255.255       # never relay into private ranges
denied-peer-ip=172.16.0.0-172.31.255.255     # (stops internal-network probing)
denied-peer-ip=192.168.0.0-192.168.255.255
denied-peer-ip=169.254.0.0-169.254.255.255
denied-peer-ip=127.0.0.0-127.255.255.255
user-quota=12                        # cap concurrent allocations per credential
total-quota=100                      # cap concurrent allocations server-wide
max-bps=200000                       # cap per-session bandwidth (~200 kbit/s)
no-tls                               # not using turns:// (see "TLS" below)
no-dtls
fingerprint
no-multicast-peers                   # belt-and-braces: block multicast relaying
EOF
```

Notes on the knobs:

- **`use-auth-secret` + `static-auth-secret`** turn on coturn's time-limited credential mode. Browsers are never given this secret - `signal.php` signs a short-lived username/password with it per call (expires after ~1h), so a credential captured from DevTools stops working almost immediately. This is the single most important anti-abuse setting: a static `user=...` password, by contrast, works forever for anyone who reads it off the wire.
- **`external-ip`** must be the box's public IP. If the box is itself behind NAT (some seedboxes are), use `external-ip=PUBLIC_IP/PRIVATE_IP` so coturn advertises the public address but binds the private one.
- **`min-port`/`max-port`** is the relay range. Each active relayed call uses one port from it. 100 ports is generous for personal use - see [How many ports do I need](#how-many-ports-do-i-need).
- **`denied-peer-ip`** stops the relay being used to reach private/internal addresses (a classic open-relay abuse and SSRF vector). The ranges above cover RFC1918, link-local, and loopback.
- **`user-quota` / `total-quota` / `max-bps`** cap how much a single credential and the whole server can relay at once, so a leaked or abused credential cannot run away with your bandwidth.
- **`no-tls`/`no-dtls`** keep this simple by using plain `turn:` rather than encrypted `turns:`. The media inside is already encrypted regardless. If your callers sit behind firewalls that only allow port 443/TLS out, add a certificate and a `turns:` entry instead (see [TLS](#tls-turns-for-strict-firewalls)).

`signal.php` and coturn must hold the **same** secret: copy `static-auth-secret`'s value into `$TURN_SECRET` (next section). The clocks on the two boxes should be roughly in sync (NTP) - a minted credential carries an expiry timestamp coturn checks against its own clock.

### 3. Open the firewall

Open, as **UDP** (and TCP if you offer the TCP transport line), both the listening port and the entire relay range - on the box's own firewall **and** in the provider's control panel:

```bash
sudo ufw allow 15385/udp
sudo ufw allow 15386:15484/udp
sudo ufw allow 15385/tcp     # only if you advertise a turn:...?transport=tcp URL
```

This is the step people miss. If the ports are not open end to end, the relay looks alive but no call ever connects.

### 4. Run it, and keep it running

```bash
sudo turnserver -c /etc/turnserver.conf -o     # -o runs it in the background
```

Survive a reboot. The clean way on a normal systemd box is to enable the service:

```bash
sudo sed -i 's/#TURNSERVER_ENABLED=1/TURNSERVER_ENABLED=1/' /etc/default/coturn
sudo systemctl enable --now coturn
```

On a container/seedbox without systemd, use cron instead:

```bash
(crontab -l 2>/dev/null; echo "@reboot /usr/bin/turnserver -c /etc/turnserver.conf -o") | crontab -
```

(Some container hosts do not fire `@reboot`. If calls stop after a reboot, re-run the `turnserver` line - or better, ask the host how they want long-running services started.)

### TLS (turns:) for strict firewalls

Some corporate and public networks block everything except outbound 443. To reach callers there, run TURN over TLS on 443:

1. Get a certificate for a hostname pointing at the box (Let's Encrypt is fine).
2. In `turnserver.conf` drop `no-tls`/`no-dtls` and add `cert=/path/fullchain.pem`, `pkey=/path/privkey.pem`, and `tls-listening-port=443`.
3. Set `$TURN_TLS = 'YOURHOST:443';` in `signal.php` so it also advertises a `turns:YOURHOST:443?transport=tcp` entry (it reuses the same minted credential).

This is the single biggest reliability upgrade for callers on locked-down networks, at the cost of needing a hostname and cert.

## Option B - free Metered relay (no server admin)

If you would rather not run a box, the free [Metered Open Relay](https://www.metered.ca/tools/openrelay/) is built in:

1. Sign up and create an app - you get an app domain and an API key.
2. Paste them into the top of `signal.php`:

   ```php
   $METERED_DOMAIN = 'yourapp.metered.live';
   $METERED_KEY    = 'your-api-key';
   ```

3. Re-upload `signal.php`.

The API key never leaves the server: `signal.php` fetches short-lived TURN credentials from Metered and hands only those to browsers, caching them for an hour so the API is not hit on every sign-in. The free tier covers light personal use; heavier use moves to their paid plans.

## Option C - any other managed TURN

Any TURN provider (Twilio, Cloudflare Calls, Xirsys, a relay a friend runs) works - they all speak the same protocol. Put their `turn:`/`turns:` URLs and credentials into the `$TURN_STATIC` array near the top of `signal.php`, one entry per URL:

```php
$TURN_STATIC = [
    ['urls' => 'turn:turn.example.com:3478', 'username' => 'PROVIDER_USER', 'credential' => 'PROVIDER_PASS'],
];
```

These are sent alongside whatever the coturn block (`$TURN_HOST`/`$TURN_SECRET`) and Metered produce, so you can run several relays at once and let WebRTC pick. If the provider issues short-lived credentials via an API rather than a static password, fetch them in `signal.php`'s `ice` case the same way the Metered branch does. Keep any real provider credential out of committed copies.

## Point the app at your relay

For the self-hosted coturn above, fill in the relay block near the top of `signal.php` and re-upload that one file. You only give it the host and the shared secret - `signal.php` mints the per-call credential itself:

```php
$TURN_HOST   = 'YOUR_PUBLIC_IP:15385';  // host:port, no scheme
$TURN_SECRET = 'YOUR_LONG_SECRET';      // same value as static-auth-secret in coturn
$TURN_TTL    = 3600;                    // minted credentials live this many seconds
$TURN_TCP    = true;                    // also advertise turn:...?transport=tcp
$TURN_TLS    = '';                      // 'YOURHOST:443' only if you set up TLS below
```

On each `ice` request `signal.php` builds the `stun:`, `turn:` (UDP), optional `turn:...?transport=tcp`, and optional `turns:` (TLS) entries from these, each carrying a freshly signed, soon-to-expire username/password. Offering UDP, TCP, and (if set up) TLS gives WebRTC the best chance of finding a path that the caller's network allows. There is no need to touch the client - `core.js` takes whatever the server sends.

Keep the host and secret blank in any copy you commit or publish, and fill them in only on the live server, so a real secret is never pushed to a public repo.

## Relay-only mode (hide IP addresses)

A direct peer to peer call means the two devices connect to each other, so each side can see the other's IP - that is inherent to direct connections. To prevent it, force **all** audio through the relay so neither caller ever learns the other's address:

```php
$RELAY_ONLY = true;   // in signal.php
```

When true, `signal.php` tells the browser to use `iceTransportPolicy: 'relay'`, so only relayed paths are tried. The cost is more relay bandwidth and a little extra latency, and it **requires a working TURN server** - with no relay configured, relay-only calls cannot connect at all. Leave it `false` for normal use; turn it on when IP privacy matters more than cost.

## How many ports do I need

The relay range (`min-port`..`max-port`) sets how many calls can be relayed at once. A single 1:1 audio call uses **one** relayed port (sometimes two if both directions relay). So:

- a personal line: 20-50 ports is plenty,
- the example 15386-15484 (about 100 ports): comfortably dozens of simultaneous relayed calls.

Remember most calls do not relay at all, so the range only needs to cover the share that does. Widen it if you ever see calls fail under load; there is no harm in a generous range as long as the firewall opens the whole thing.

## Test that it actually relays

1. **Trickle ICE.** Open Google's [Trickle ICE tester](https://webrtc.github.io/samples/src/content/peerconnection/trickle-ice/), enter your `turn:` URL, username, and credential, and click "Gather candidates". You should see at least one candidate of type **`relay`**. If you only get `host`/`srflx` and no `relay`, the TURN server or its ports are not reachable - fix that before blaming the app.
2. **Real call across networks.** Put one end on home Wi-Fi and the other on a phone's mobile data (mobile data is the classic case that needs a relay). If that call connects, your relay is doing its job.
3. **Force it.** Temporarily set `$RELAY_ONLY = true` and make a call. If it connects, the relay path works; if it fails, the relay is the problem, not direct connectivity.

## Troubleshooting

- **"ICE failed" / call drops right after "checking".** No working relay and no direct path. Confirm the relay gives a `relay` candidate in Trickle ICE, and that the UDP listening port + relay range are open on both the box firewall and the provider panel.
- **Trickle ICE shows no `relay` candidate.** Wrong IP/port/secret, the server is not running, or ports are closed. Check `external-ip` matches the box's real public IP, and that `static-auth-secret` in the config is identical to `$TURN_SECRET` in `signal.php`. To test the relay directly in Trickle ICE you need a matching credential pair - grab one from `signal.php?action=ice` (with a token) or temporarily set a `user=` line in coturn.
- **`401 Unauthorized` from the relay, or calls relay then drop after an hour.** The minted credential expired or the two boxes' clocks drift. Confirm both run NTP and that `$TURN_TTL` is long enough (an hour is plenty); the secret on both sides must match exactly.
- **Works on UDP-friendly networks, fails behind a corporate firewall.** That network only allows 443/TLS out. Set up `turns:` on 443 (see [TLS](#tls-turns-for-strict-firewalls)).
- **Calls connect but die after a reboot.** The relay did not restart - your `@reboot`/systemd entry did not fire. Re-run `turnserver` and switch to the systemd service if available.
- **Box is itself behind NAT (some seedboxes).** Use `external-ip=PUBLIC/PRIVATE` so coturn advertises the public address.
- **One-way or no audio with relay-only on.** The relay range is too small or partly blocked - widen `min-port`..`max-port` and open the whole range.

## Cost and bandwidth

A relayed voice call is Opus audio, roughly 30-50 kbit/s each way, so about 0.4-0.8 MB per minute per call through the relay. Direct calls cost the relay nothing. A small VPS handles many simultaneous relayed calls without trouble; the practical limit is your provider's bandwidth allowance, not CPU. Metered's free tier is fine for light personal use and meters by relayed GB beyond that.

## Security notes

- The minted username/password **is** meant to be shared with browsers - that is how TURN authenticates clients. It only authorises relaying media, grants nothing else on the box, and expires after `$TURN_TTL`, so one captured from DevTools is useless within the hour. The `static-auth-secret` it is derived from is **not** shared and must never leave the server.
- The relay only ever sees **encrypted** media (DTLS-SRTP). It cannot hear the call even when every packet flows through it.
- Rotate the secret if it ever leaks: change `static-auth-secret` in `turnserver.conf` and `$TURN_SECRET` in `signal.php` to a new random value, restart coturn, and re-upload `signal.php`. Every outstanding minted credential dies instantly because it no longer verifies against the new secret.
- The `denied-peer-ip` ranges and the `user-quota`/`total-quota`/`max-bps` caps in the config are what stop a relay being abused as an open proxy or run up your bandwidth - keep them in place.
- Keep `signal.php` as the only place the secret / API key lives - never put a relay secret in client JavaScript or anything the browser downloads in full.

---

See the main [README](README.md) for the rest of Nano Call (putting it live, the admin, the operator console, the embed button, and privacy).
