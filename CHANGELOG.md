# Changelog

All notable changes to Nano Call are recorded here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/); versions follow [SemVer](https://semver.org/).

## [Unreleased]

### Fixed

- **Button could overflow narrow mobile viewports.** The launch button used a fixed
  wide horizontal padding with no width cap, so on a narrow phone it could push the
  page wider than the screen, toggling a horizontal scrollbar and jolting other
  fixed-position elements (most visible on pages with two buttons). The button and
  inline holder now cap at `max-width:100%` with `box-sizing:border-box`, the
  padding is responsive (`clamp(28px,7vw,64px)`), and the floating launcher is
  capped at `calc(100vw - 40px)`. Multiple buttons on one page already shared a
  single overlay; this makes that fully mobile-safe.

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
- **Web installer** (`install.php` + `bootstrap.example.php`): first-run setup
  that creates an **outside-webroot config directory** (one level above the
  document root, per-host slug) and writes a gitignored `bootstrap.php` pointing
  at it. Refuses to re-run once configured; self-deletes after install.
- **Removable admin** (`admin/`): password-gated setup that writes the business
  handle, branding, subjects, theme, site URL and licence key to `config.json`,
  and the operator password hash to `admin.json` - both in the **outside-webroot
  config directory**, never web-reachable. Delete the folder after setup to
  harden; re-upload to edit.
- **Signaling** (`signal.php`): file-based mailboxes, presence, public config
  endpoint (CORS), STUN/TURN config, disposable-visitor sweep. No database.
  Persistent secrets (settings, licence key, password hash) live outside the
  webroot; the transient in-webroot files under `data/` (call mailboxes,
  presence, cached relay creds) are `.php`-named and written with a PHP guard as
  their first line, so a direct request 403s with an empty body even where
  `.htaccess` is ignored (nginx) - defence in depth alongside `data/.htaccess`.
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
