# Product

## Register

product

## Users

Three kinds of people:

- **The website visitor (caller).** Arrives on a business's site, often non-technical, on any browser. Has never seen the app, installs nothing, and wants to be talking to the business within seconds. Sees only: a button, a short "why are you calling?" picker, and a Call button.
- **The operator (the business).** Keeps the console open all day on a shop or office PC as a live phone line. Logs in once with the admin password, then answers calls - seeing the caller's subject and note before picking up.
- **The installer (a web designer).** Sets the line up once via the removable admin: branding, subjects, licence, and the embed snippet to paste onto a client's site. Often deploying it as a paid feature for a client.

## Product Purpose

A one-click "Call us" button for any website: a visitor taps it and talks to the business live in the browser. Peer to peer WebRTC audio (end to end encrypted) brokered by one PHP file on ordinary shared hosting. Success: a visitor reaches the open console in a few seconds without help; the business trusts the page enough to leave it on as their phone all day; the installer can brand, license, and embed it in minutes.

## Brand Personality

Nano: small, hand-built, honest. Calm confidence of a desk phone, not the bustle of a chat app. Three words: minimal, dependable, quiet.

## Anti-references

- Not Discord/Teams/Slack: no channels, presence walls, chrome, or notification noise.
- Not a SaaS chat-widget: no bot, no canned-response tree, no "we're away" forms. It is a real voice line, not a deflection funnel.
- No framework aesthetics (Material, Bootstrap) - this is a hand-built tool and should feel like one, in the good sense: precise, light, nothing generic.

## Design Principles

- One screen, one job: each state (choose subject, calling, talking; or for the operator: log in, waiting, incoming, on call) shows only what that moment needs.
- The call is the interface: the loudest element on any screen is the action that moves the call forward.
- Restraint over ornament: one accent color (set per business), generous whitespace, motion only to convey state (house ethos shared with the owner's other nano projects).
- Context before commitment: the operator sees the subject and note before answering; the caller is never blocked behind a form (the subject defaults so one tap calls).

## Accessibility & Inclusion

- WCAG AA contrast minimums on the dark theme; verify muted text against card surfaces.
- Full keyboard operation (Enter to submit, focus-visible rings, logical tab order).
- prefers-reduced-motion alternatives for the ring pulse and screen transitions.
- Callers may be elderly or non-technical: large hit targets, plain-language labels and errors.
