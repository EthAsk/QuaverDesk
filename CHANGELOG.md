# Changelog

Each release of QuaverDesk and what changed in it. Read the release you're updating to before running
`sudo quaverdesk-deploy`: anything you need to do by hand is listed under it. The version you're running is shown at
the foot of every page.

Versions follow [semantic versioning](https://semver.org): a patch (1.0.1) is for fixes and security updates, a minor
version (1.1.0) adds features, and a major version (2.0.0) may need changes on your side.

## Unreleased

## 1.4.0 (13 November 2026)

- **Laravel 13:** the framework, the build tools (Vite 8) and the CI actions are on their latest major versions. Run
  `sudo quaverdesk-deploy` as usual; the server needs PHP 8.3 or later.
- **Security updates:** Laravel 12.72 and the Symfony components it uses, which fixes the advisories reported against
  them, plus Fortify, passkeys, jssip and Vite patch releases. Run `sudo quaverdesk-deploy` as usual.
- **Simpler menu:** admin pages are now in groups you open and close (Phone system, People & devices, Voicemail &
  sounds, AI, Calls & reports, Integrations, Account). The plan, seats and licence details moved from the dashboard to
  a new **Admin → Overview**; everyone's first page is now **Home**.
- **Wallboard for everyone (Pro):** the live wallboard is now in every person's menu at `/wallboard` (the old
  `/admin/wallboard` address redirects). Listening in on calls is still only for the admins chosen as supervisors.
  Admins can tick **Hide callers' numbers** on the wallboard so only admins see them; numbers show by default.
- **Trunk firewall (self-hosted):** the trunk ports (5080 and 5081) now only accept your SIP trunk providers, and the
  firewall follows **Admin → SIP trunks** by itself. A new fail2ban jail bans SIP scanners that never send a
  password. One-off setup for existing installs: see "Firewall" in docs/VOICE-SERVER.md. If you added ufw rules per
  provider before, you can replace them with `ufw allow 5080` and `ufw allow 5081/tcp` once it's running.

## 1.3.0 (10 November 2026)

- **Admin → System (self-hosted):** the version, health checks and backups in one place: **Back up now**, the last
  backup's result, and downloads of the encrypted backups. Needs a one-off step for existing installs: see "A8.
  Background worker, scheduler and backups" in docs/DEPLOYMENT.md (a sudoers rule and a status folder).
- **Update available:** admins see a notice when a newer release is out. Updating is still `sudo quaverdesk-deploy`,
  which now takes a backup first and runs a health check afterwards (`php artisan quaverdesk:health`). Turn the daily
  check off with `QUAVERDESK_UPDATE_CHECK=false`.
- **Call monitoring (Pro):** chosen admins can listen in on, whisper to, or join a live call from the wallboard. The owner
  confirms callers are told first; emergency calls are never monitored; every use is logged. Under Admin → Call monitoring.
- **Click-to-call over the API (Pro):** a calling token can ring a person's phones and dial a number for them once
  they answer, with per-token limits, UK-only by default, an audit trail and a pause switch. Under Admin → API.
- **Queue strategies (Plus; priority and backups Pro):** queues can ring the agent idle longest, the next in turn, or the
  one with the fewest calls today, instead of everyone; on Pro, first choices ring before backups.
- **Reports and export (Plus, queue and people reports Pro):** call summaries by day and hour, CSV download of calls,
  queue reports with waits and service level, a report per person, and a weekly or monthly emailed report. Under
  Admin → Reports.
- **API (Plus and Pro):** a read-only API for your own software: people, numbers and call history, with tokens made
  under Admin → API.
- **Directory sync (Pro):** Microsoft Entra, Okta and other identity providers can add people, keep their names and
  emails in step and switch them off when they leave (SCIM 2.0). Nobody is deleted. Under Admin → Single sign-on.
- **HubSpot caller names (Pro):** incoming calls from a HubSpot contact show their name and company on the phone and in
  call history.
- **Single sign-on (Pro):** sign in with Microsoft Entra or Google Workspace, for people already added under Users.
  Limited to your own directory or domain, and two-factor still applies. Under Admin → Single sign-on.
- **HubSpot CRM (Pro):** calls are logged against the HubSpot contact with that number, with the AI summary and a
  recording link added when ready. HubSpot's phone number links open the phone ready to dial. Under Admin → HubSpot.
- **Webhooks and Zapier (Plus and Pro):** signed, retried webhooks for calls starting and ending, voicemails,
  recordings and AI summaries, under Admin → Webhooks. Works with Zapier, Make and n8n.

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
