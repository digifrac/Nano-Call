# Changelog

All notable changes to Nano Call are recorded here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/); versions follow [SemVer](https://semver.org/).

## [1.0.0] - 2026-06-16

First release. A one-click "Call us" button for any website: a visitor taps it,
picks why they are calling, and talks to the business live in the browser. Free,
encrypted, peer to peer, on ordinary PHP hosting.

### Added

- **Caller widget** (`js/embed.js` + `widget.html`): floating corner button and/or
  inline button, both opening a call popup with a subject picker (defaults to the
  first, so one tap calls) and an optional note. Anonymous visitors, no account,
  no phone book.
- **Operator console** (`index.html` + `js/console.js`): the business keeps it open
  to receive calls; admin password required to go online; incoming calls show the
  caller's subject and note before answering.
- **Removable admin** (`admin/`): password-gated setup that writes
  `data/config.json` (business handle, branding, subjects, theme, site URL,
  licence). Delete the file after setup to harden; re-upload to edit.
- **Signaling** (`signal.php`): file-based mailboxes, presence, public config
  endpoint (CORS), STUN/TURN config, disposable-visitor sweep. No database.
  Every file under `data/` (admin password hash, presence tokens, signaling,
  cached relay creds) is `.php`-named and written with a PHP guard as its first
  line, so a direct web request returns 403 with an empty body even where
  `.htaccess` is ignored (nginx) - defence in depth alongside `data/.htaccess`.
  Pre-existing `.json` config/admin files are migrated to the guarded form
  automatically on first read.
- **Licence** (`licence.php`): per-domain Ed25519 verification (no phone-home,
  embedded public key, host from config not request) that removes the
  "Powered by Nano Call" line on a licensed domain. Part of the Digital Fracture
  Nano licence suite (`--product=nano-call`).
- **TURN relay** support with a full setup guide ([RELAY.md](RELAY.md)), including
  a relay-only privacy mode that hides both callers' IPs. Uses coturn
  time-limited credentials (`use-auth-secret`): `signal.php` mints a short-lived
  username/password per call from a server-only secret, so nothing reusable is
  ever handed to the browser. Ships with no relay configured - host and secret
  are filled in only on the live server, never committed.
- MIT licence; light/dark theme; reduced-motion support.

### Notes

- Renamed and repurposed from the earlier two-sided "Nano Phone" prototype:
  removed the encrypted phone book, sign-in screen, and outbound dialer in favour
  of the single "Call us" use case.
- Audio quality: mono capture with echo cancellation / noise suppression / auto
  gain, plus a one-call-at-a-time guard so a second simultaneous caller cannot
  disturb an active call.
