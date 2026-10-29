# QuaverDesk

A web-based business phone system for small teams, built with Laravel and
its own FreeSWITCH voice server. Calls go out and come in over any SIP trunk you
choose, so there are no per-minute platform fees on top of your provider's.
Bring your own AI provider too; Plus and Pro features unlock with a licence key.

> **Source-available, at your own risk.** Licensed under the
> [Elastic License 2.0](LICENSE) — see [LICENSING.md](LICENSING.md) for a plain-
> English summary. Provided **as is, without warranty**. **Not a replacement
> for a landline or mobile for emergency (999) calls.** Read
> [DISCLAIMER.md](DISCLAIMER.md) before use.

## What's included

| Area | Included |
| --- | --- |
| Accounts | Owner set-up, sign-in, password reset, two-factor (required for admins), passkeys (Plus/Pro), roles, plan user limits, moving up a user band |
| Licensing | Signed keys (Free / Plus / Pro), automatic daily renewal |
| Calling | Phone app (installable, runs in the background, call notifications, click-to-call, `tel:` links), desk phones and mobile SIP apps (Plus, TLS/SRTP only), UK calls, fraud rules |
| Call handling | Transfer (straight away, or talk first), hold with your hold music, park and pick up from any phone, ring your mobile too (follow-me, with "press 1") |
| Routing | Numbers with opening hours, ring groups, IVR menus, voicemail fallback |
| Voice server | Bundled FreeSWITCH config; any UK SIP trunk (IP-authenticated or registration), several provider addresses per trunk |
| Voicemail & recording | Voicemail to email, call recording with spoken notice, encrypted storage, retention |
| AI (your own provider) | Transcripts and summaries (Plus); action items and sentiment (Pro) |
| Safety | Authenticated webhooks, loop protection, emergency calls never blocked or recorded, operator acknowledgement record |

## Requirements

- PHP 8.3+ with `sodium`, `pdo_sqlite` or `pdo_mysql`, `mbstring`, `openssl`
- Composer 2, Node 20+
- FreeSWITCH 1.10+ with mod_httapi and mod_sofia (see
  [docs/VOICE-SERVER.md](docs/VOICE-SERVER.md))
- A SIP trunk from any provider, for calls to and from phone numbers

## Install

1. **Set up the server:** follow [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). It
   covers the web app, database, background worker, email and backups.
2. **Install the voice server:** follow [docs/VOICE-SERVER.md](docs/VOICE-SERVER.md)
   to install FreeSWITCH and point it at the app, then run
   `php artisan voice:sync` to write its settings.
3. **Create the owner account:** open the site and choose **Set up this phone
   system**. Turn on two-factor (Security page) and complete **Setup & terms**;
   calling stays off until an owner or admin has done this.
4. **Connect a SIP trunk and your numbers** (below), then work through
   [the test list](docs/DEPLOYMENT.md#a12-test-before-real-calls).

## How calls work

QuaverDesk runs its own FreeSWITCH. The app writes FreeSWITCH's user list,
trunks and allow-list into `VOICE_CONF_DIR` and reloads it over the event
socket; FreeSWITCH asks the app what to do with each call over HTTP
(`/webhooks/voice/*`, authenticated with `VOICE_WEBHOOK_SECRET`). The phone app
in the browser signs in to FreeSWITCH over a secure WebSocket.

### Incoming calls

On a Plus or Pro licence, first connect a trunk (below), then go to
**Admin → Phone numbers**, add each number your provider gives you and choose
where calls go (a person, a ring group or an IVR menu), with optional opening
hours.

### The phone app

Everyone's phone sits in the bottom corner of every page, and keeps going
(calls included) while they move around the app. **↗** pops it out into its
own small window at `/phone`, which browsers can also install as an app
(**Phone → Install as an app**); closing that window puts the phone back in
the corner. It keeps ringing while minimised or behind other windows, shows a
notification with Answer/Decline for each call, and badges missed calls. Only
one window per browser runs the phone, so calls ring once. Settings per
computer: do not disturb, microphone and speaker, pop out after signing in,
notifications. **Phone** in the sidebar explains how to start the app with
the computer.

Click-to-call: **Call** buttons on the Phone page and in Call history dial
through the phone, and the installed app can handle `tel:` links from other
sites and apps (they fill in the number; you press Call).

### Greetings, hold music and voice

**Admin → Sounds** holds your own recordings: upload a file or record one in
the browser, then pick it on a voicemail box or IVR menu to play instead of
the text, or choose it as hold music. Everything else is read by the voice
chosen (after a listen) under **Admin → Voice**.

### Passkeys (Plus/Pro)

People can add passkeys under **Security** and then sign in with a fingerprint,
face or device PIN instead of a password and code (user verification is
required, so a passkey counts as both factors). Passkeys only work at the
`APP_URL` address.

### Voicemail and recording

- **Admin → Voicemail boxes:** choose who gets each box's emails and whose
  missed calls go there. A box can also be a destination for a number, ring
  group fallback or IVR option.
- **Admin → Recording (Plus/Pro):** the notice callers hear, outgoing-call
  recording, retention (90 days on Plus, your choice on Pro) and automatic AI.
  Switch recording on per number under Phone numbers.
- FreeSWITCH uploads each recording (WAV) to the app when it finishes; the
  app encrypts it with your `APP_KEY` and deletes the upload. **Back up `APP_KEY`**: without it, saved
  recordings, transcripts and AI keys cannot be read.

### Bring your own AI

**Admin → AI provider (Plus/Pro).** Transcription uses any OpenAI-compatible
`/audio/transcriptions` endpoint; summaries use Anthropic or any
OpenAI-compatible `/chat/completions` endpoint. Keys are encrypted and never
shown again. Use "Test summaries" to check the key and model. AI calls are
billed to you by your AI provider; the app never runs models itself.
Transcripts are treated as untrusted data in the prompt, and replies are
strictly parsed, so a caller cannot steer the AI through what they say.

### SIP trunks

**Admin → SIP trunks → Connect a trunk.** Pick your provider and enter:

- the provider addresses for outgoing calls (several are tried in turn);
- the IP addresses your provider sends calls from (only these may call in);
- for registration trunks, the username and password (stored encrypted).

The app turns each trunk into a FreeSWITCH gateway and allow-list entry and
reloads FreeSWITCH. The trunk page shows what to send your provider: the voice
server's address (`VOICE_TRUNK_ADDRESS`, port 5080). Calls to carriers use
G.711 (PCMA/PCMU). Confirm with your provider how 999 calls and caller location
work on the trunk.

### Desk phones and mobile apps (Plus)

**Admin → Phones and apps.** Add a desk phone or a mobile SIP app for a person; the page shows the settings
to type into the handset or app (server `VOICE_DOMAIN`, TLS port 5061, SRTP required,
username, password). Desk phones may only connect encrypted. Removing a phone
signs it out immediately. A person's desk
phones ring together with their softphone, in ring groups too. From a desk
phone, dial `*12` to call colleague user-12, or any UK number as normal.
Use the handset's own Transfer and Hold keys. To park a call, transfer it to
`**1` to `**9`; any phone picks it up by dialling the same code.

**Recommended mobile apps.** The app must support TLS and SRTP. An iPhone only
rings for an app that is closed if the app's maker wakes it with a push
notification, which this phone system can't do itself.

| App | Phones | Cost | Notes |
|---|---|---|---|
| Groundwire (Acrobits) | iPhone, Android | About £10, one-off | Best choice. Its push service keeps the account signed in, so it rings when closed. Add a Generic SIP account, transport TLS, SRTP Required, incoming calls by push. |
| Linphone | iPhone, Android | Free | Good on Android. On iPhone it only rings while open. |
| The phone window | Any browser | Free | Open the company's address on the phone and add it to the home screen. Rings while open. |

Without an app, follow-me (on a person's profile) rings their own mobile
number too.

### Transfer, hold and park

All plans. In the phone, **Transfer** sends the call to a number or extension
(including a ring group's), either straight away or after you've talked to them
(**Talk first**). **Park** holds the call in a numbered space (`**1` to `**9`,
one set per company); anyone dials that code to pick it up, and an unclaimed
call rings whoever parked it after 3 minutes. Every transfer is checked like a
call the transferring person made themselves (company, plan, fraud limits), and
a transfer that can't go through comes back to them. **999 and 112 calls are
never transferred or parked**: they go straight back to the caller.

The voice server needs `mod_valet_parking` (see [docs/VOICE-SERVER.md](docs/VOICE-SERVER.md)).

### Ring my mobile too (follow-me)

Under **Phone**, each person can add their UK mobile. It must be confirmed
first: the system calls it and they press 1. Calls to that person then ring the
mobile too, at once or after a few seconds. Whoever answers on the mobile
presses 1 to take the call, so the mobile's voicemail can't take it. Calls to
mobiles are outside calls: they show the company's number and count towards the
person's fraud limits; when a limit is reached, only their phones ring. Calls to
ring groups don't ring members' mobiles.

## Licensing

QuaverDesk runs on the Free plan with no key. For Plus or Pro, buy a licence
at [quaverdesk.app/pricing](https://quaverdesk.app/pricing) and paste the key
on **Admin → Licence**. Keys are signed, so they are checked on your own
server; with `LICENCE_SERVER_URL=https://quaverdesk.app` your install fetches
its renewed key once a day, sending only the current key. If a subscription
ends, the last key runs to its expiry date and the install drops back to Free.
Nothing is deleted.

## Tests

```bash
php artisan test
```

Unit tests cover licence signing and verification, plan features, UK number
handling (including SIP addresses from trunks), emergency-call handling, fraud
rules, opening hours across British Summer Time, ring groups, IVR menus, loop
protection, FreeSWITCH call documents and generated voice server settings. Feature tests cover sign-up, admin access, user
limits, licence activation, signed webhooks (including tampered callback URLs),
incoming routing on each plan, outgoing calls over a SIP trunk, voicemail to
email, encrypted recordings, bring-your-own AI, desk phones and licence
renewal.

## Project documents

- [LICENSE](LICENSE) · [LICENSING.md](LICENSING.md) · [DISCLAIMER.md](DISCLAIMER.md)
- [COMMERCIAL-TERMS.md](COMMERCIAL-TERMS.md) (terms for Plus and Pro licences)
- [SECURITY.md](SECURITY.md) · [TRADEMARKS.md](TRADEMARKS.md) · [CONTRIBUTING.md](CONTRIBUTING.md) · [NOTICE](NOTICE)
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — setting up a production server
- [docs/VOICE-SERVER.md](docs/VOICE-SERVER.md) — installing and running FreeSWITCH
