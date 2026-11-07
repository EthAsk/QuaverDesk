# Changelog

Each release of QuaverDesk and what changed in it. Read the release you're updating to before running
`sudo quaverdesk-deploy`: anything you need to do by hand is listed under it. The version you're running is shown at
the foot of every page.

Versions follow [semantic versioning](https://semver.org): a patch (1.0.1) is for fixes and security updates, a minor
version (1.1.0) adds features, and a major version (2.0.0) may need changes on your side.

## Unreleased

## 1.2.0 (7 November 2026)

- **Live wallboard (Pro):** live calls, queues, who's free and today's totals on one page for admins, updated every 5
  seconds, with a full-screen mode for a wall display.
- **Call queues (Plus and Pro):** callers wait their turn with hold music and hear their place in the queue, while the
  free agents logged in to it are rung. After the longest wait, or when nobody is logged in, calls go to an overflow
  such as voicemail. Agents log in and out on their dashboard or by dialling `*45`.
- **Powered by QuaverDesk:** an install given a name of its own (`APP_NAME`) says "Powered by QuaverDesk" at the
  foot of its pages, unless its licence includes own branding.
- **Search across calls (Pro):** search what was said in calls and voicemails, or ask your AI provider a question
  about them, from Voicemail & recordings. People only search what they can already see.
- **AI receptionist (Pro):** answers calls in conversation, puts callers through to options you choose, or takes a
  written message for a mailbox. Uses your own transcription and summaries providers.
- Upgrade prompts for Pro-only features now say they're on the Pro plan.

## 1.1.0 (6 November 2026)

- **DECT bases:** Yealink W70B bases are set up automatically. Every handset shares the base's extension, or up to
  10 handsets can each have their own (added as handsets on the base).

## 1.0.0 (1 November 2026)

The first numbered release. It includes everything in the [README](README.md), and these recent additions:

- **Desk phones:** automatic provisioning, with a set-up window and approval of each new phone before it gets its
  settings. Busy lamp fields, phone web logins and LAN locks.
- **Desk-phone-only users:** a user can have a desk phone without a web sign-in. Users can also be limited to calling
  colleagues only, and voicemail can be turned off per user. Each phone's online status is shown.
- **Emergency calls:** 999 and 112 use any active trunk when no default outbound trunk is set.
- **Two-factor set-up:** a "Can't scan it?" option shows the key to type into an authenticator app by hand.
- **Calls between your own users** stay on the system, without going out over a trunk.
- **Trunks:** secure transport (TLS/SRTP) and several provider addresses per trunk.
- **Handsets:** voicemail and call forwarding to other users from the phone, and recording sounds by phone.
- **Call logs**, and a fix for calls left showing as in progress.
