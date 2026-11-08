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
| Routing | Numbers with opening hours, ring groups, IVR menus, call queues with hold music (Plus), voicemail fallback |
| Voice server | Bundled FreeSWITCH config; any UK SIP trunk (IP-authenticated or registration), several provider addresses per trunk |
| Voicemail & recording | Voicemail to email, call recording with spoken notice, encrypted storage, retention |
| AI (your own provider) | Transcripts and summaries (Plus); action items, sentiment, search across calls and an AI receptionist (Pro) |
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
- **Dial `*00`** from a softphone or desk phone to play your voicemail: replay,
  skip or delete each message. You can record the greeting of your own box
  (one where you're the only member); admins can record any box's greeting
  this way. Boxes set to delete messages once they're emailed have none to
  play.
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

### Search across calls (Pro)

**Voicemail & recordings → Search.** Finds calls and voicemails by what was
said (transcripts, summaries and action items) or by part of the caller's
number, best match first. **Ask AI** sends the best matches, as summaries and
transcript excerpts, to your summaries provider with your question; the
answer cites the calls it used. Everyone only searches what they can already
see: admins see everything, others their own calls and their mailboxes.
Recordings past their retention period are never included.

### AI receptionist (Pro)

**Admin → AI receptionists.** A receptionist answers in plain conversation.
It can do three things:

- Tell callers what you've written under "What it can tell callers", and
  nothing else.
- Put callers through to one of up to 8 options you set: a name, when to
  choose it, and a destination.
- Take a written message for a mailbox. The message is emailed like a
  voicemail.

Choose it as a number's destination, an IVR option or a fallback, like
anything else.

Each time the caller speaks, the audio goes to your transcription provider
and the conversation to your summaries provider, so both must be set up.
Expect a pause of a second or two between turns. The AI can only choose
among the options you set; it can never dial anything else, and what callers
say is treated as data. If anything goes wrong (no reply, an unusable
answer, the provider is down, or the caller stays silent twice), the caller
goes to the receptionist's fallback.

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

A phone can be locked to where it signs in from: up to 5 IP addresses,
ranges or host names (for example the office's public IP, or its dynamic DNS
name, which is looked up again every 5 minutes; if a lookup fails the last
address is kept). From anywhere else the voice
server refuses it, even with the right password, for registering and calling
alike. The voice server must see phones' real addresses, so forward port 5061
to it directly rather than through a proxy.

A locked phone may also sign in from local network addresses (10.x, 172.16–31.x,
192.168.x and IPv6 fc00::/7), unless its **Filter LAN addresses** box is ticked.
Those addresses can only reach the server from its own network, so phones in the
same building as the server work without listing them. This relies on the
server seeing phones' real addresses: if a router or proxy in front of it
replaces internet callers' addresses with a local one, tick the box.

**Automatic set-up (Yealink, Grandstream GRP, Fanvil X series).** Give a phone
its make and MAC address, then enter the company's provisioning address, user
name and password on the phone once (or set DHCP option 66). The phone fetches
its SIP account (TLS, compulsory SRTP), a new random admin password for its
web page, UK time, `*00` for voicemail and its busy lamp keys, and checks again every day.
**Send settings now** asks a signed-in phone to fetch at once (SIP NOTIFY
check-sync). Admin passwords are shown only after confirming your password.
Every download, refusal (wrong login, unknown MAC, outside the phone's lock)
and every time someone shows or changes a password is kept in the **phone log**,
which the operator also sees on the company's page.

**Busy lamp keys (BLF).** Choose colleagues for a phone, and it gets a key for
each one. The key lights while they're on a call and flashes while their phone
rings; press it to call them, or to transfer a call to them. Each person's
lamp is watched through a secret address that only phones in their own company
are given. The whole process, including DHCP option 66 and troubleshooting, is
in [docs/DESK-PHONES.md](docs/DESK-PHONES.md).

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

### Live wallboard (Pro)

**Admin → Live wallboard.** A page for a screen on the wall, updated every 5
seconds:

- **Today:** live calls, callers waiting, calls in, calls out, missed calls and
  talk time.
- **Each queue:** callers waiting, the longest wait, agents logged in (and how
  many are on a call), and today's answered, abandoned and overflowed calls.
- **Calls in progress:** who's on each, and for how long.
- **Everyone:** whether they're free or on a call, which queues they're in, and
  their calls today.

**Full screen** fills the display. Admins only. Who is on a call comes from the
voice server; if it can't be reached, everything else still updates and people
show as unknown.

### Call queues

Plus and Pro. A queue holds callers in turn: they hear its greeting, then hold
music (the queue's own or the company's) and their place in the queue ("You are
number 2") when they join, when it moves and every minute. When it's their turn,
the queue's free agents ring together while the caller keeps hearing the music;
an agent already on a call is skipped. If nobody answers, the caller holds again
and is rung again. After the queue's longest wait, or straight away when no
agent is logged in (unless you untick that), the caller goes to the queue's
overflow: voicemail, a person, a ring group, a menu or another queue.

Point a number, menu option or fallback at a queue, or give it an extension to
dial or transfer calls to. Agents log in and out on their dashboard, or by
dialling **`*45`** from any of their phones (all their queues at once); admins
can log anyone in or out on the queue's page, which also shows who's waiting.

### Ring my mobile too (follow-me)

Under **Phone**, each person can add their UK mobile. It must be confirmed
first: the system calls it and they press 1. Calls to that person then ring the
mobile too, at once or after a few seconds. Whoever answers on the mobile
presses 1 to take the call, so the mobile's voicemail can't take it. Calls to
mobiles are outside calls: they show the company's number and count towards the
person's fraud limits; when a limit is reached, only their phones ring. Calls to
ring groups don't ring members' mobiles.

### Forward-only contacts

**Admin → Users → Add a forward-only contact** is for people who don't use the
phone system day to day. They get an extension but no sign-in, softphone or
desk phone, so there's no account to secure. Calls to them ring their mobile
(once they've confirmed it by pressing 1); with no mobile, or no answer, the
caller leaves a voicemail that's emailed to them with the audio attached.
Forward-only contacts are free on every plan and don't count towards its
users.

**Make forward-only** on an existing user signs them out everywhere and removes
their desk phones, passkeys, two-factor and ring group places; **Give a
sign-in** turns a contact back into a user (it takes one of the plan's users)
and emails them a link to set a password.

### Desk-phone-only users

For a reception, lobby or hall phone, add a user with the role **Desk phones
only (no sign-in)**, then add their phone under **Phones and apps**. They have
an extension, ring groups and voicemail, but no sign-in, softphone or invite
email, and they count as one of the plan's users. **Make desk phones only** on
an existing user signs them out everywhere and ends their softphone, passkeys
and two-factor; their desk phones, ring groups and voicemail stay. **Phones and
apps** shows whether each phone is online, with the address it signs in from
and its model.

### Who can call out, and voicemail

On **Admin → Users**, **Calls** can be set to **Colleagues only**. That
person's phones can then call any extension, but no outside numbers, whether
dialled or transferred to. 999 and 112 always work. Use this rather than
**Stop outgoing calls to phone numbers** on the Trunks page. If no trunk is set
for outgoing calls, 999 and 112 still go out on any active trunk, showing one
of the company's numbers. The Trunks page warns you when no trunk is active,
because then 999 can't be made.

**Voicemail on / off** per user: off, calls they don't answer just end. Their
box and its messages are kept, so turning it back on restores everything.

Removing anyone also deletes any voicemail box that no longer reaches anyone
(no members and no extra email addresses), with its messages and a greeting
recorded on the phone, so a departed person's voicemail isn't kept. Shared
boxes stay.

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
- Got an idea? [Open a feature request](../../issues/new?template=feature_request.yml) (no code needed) · [Roadmap](https://quaverdesk.co.uk/roadmap.html)
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — setting up a production server
- [docs/VOICE-SERVER.md](docs/VOICE-SERVER.md) — installing and running FreeSWITCH
- [docs/DESK-PHONES.md](docs/DESK-PHONES.md) — setting up desk phones automatically, and busy lamp keys
