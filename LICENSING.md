# Licensing in plain English

This summary is for convenience only. The binding terms are in [LICENSE](LICENSE)
(Elastic License 2.0), [DISCLAIMER.md](DISCLAIMER.md) and, for paid plans,
[COMMERCIAL-TERMS.md](COMMERCIAL-TERMS.md). Where they differ from this page, they win.

## The code is source-available, not open source

The source code is public so you can read it, audit it, run it and change it.
It is licensed under the **Elastic License 2.0 (ELv2)**, which is a
source-available licence, not an open-source (OSI) licence.

### You may

- Download, run and modify the software for your own business.
- Use the Free plan at no cost.
- Fix bugs and make changes for your own use, and suggest changes back to us
  (see [CONTRIBUTING.md](CONTRIBUTING.md)).
- Share copies, as long as the licence and notices go with them, and any copy
  you have changed carries a clear notice saying it was modified.

### You may not

- **Offer the software to others as a hosted or managed service** (for example,
  selling "phone systems" to other companies that run on this code).
- **Run an installation for anyone but your own organisation** (you and your
  group companies). This includes turning on the multi-company mode, which is
  only for our own hosted service.
- **Remove, disable, bypass or tamper with the licence-key checks** that unlock
  Plus and Pro features. This includes editing the code so paid features run
  without a valid key.
- Remove or hide the licensing, copyright or other notices.

The first, third and fourth are the limitations ELv2 imposes; the second spells
out how the hosted-service limit applies to this software, and paid licences
state it in [COMMERCIAL-TERMS.md](COMMERCIAL-TERMS.md). Everything else is
allowed.

### Reselling and partners

Reselling, hosting or managing QuaverDesk for your clients will need a written
partner agreement with us. Until you have one, each installation is for one
organisation only.

## Paid plans

The code for every feature is in this repository. Plus and Pro features switch
on when you install a licence key bought from Ethan Askew T/A Quaver Desk. Keys are signed,
so they can be verified but not created by anyone else. Paid keys are sold on
the terms in [COMMERCIAL-TERMS.md](COMMERCIAL-TERMS.md).

| | Free | Plus | Pro |
| --- | --- | --- | --- |
| Users | Up to 3 | Per licence | Per licence |
| Calls between colleagues in the browser | Yes | Yes | Yes |
| Calls to and from phone numbers over your own SIP trunk | 1 trunk, 1 number | Yes | Yes |
| Opening hours, ring groups and IVR menus | 1 ring group, 1 menu | Yes | Yes |
| Voicemail to email | Yes | Yes | Yes |
| Desk phones and mobile SIP apps | — | Yes | Yes |
| Call recording | — | Yes | Yes |
| AI transcripts and summaries (your own AI provider) | — | Yes | Yes |
| Passkey sign-in | — | Yes | Yes |
| AI action items and caller sentiment | — | — | Yes |

Each time a subscription renews, a new key is issued. If you set
`LICENCE_SERVER_URL`, your install asks for it once a day. The only thing it
sends is your current licence key: no call data, user details or usage
statistics. Every key it receives is checked against the signature before use.
If you'd rather not, leave the setting empty and paste new keys by hand.

AI features use **your own** AI provider account. We do not run AI models or
receive your call audio.

## At your own risk

You run this software yourself, at your own risk, with no warranty. Read
[DISCLAIMER.md](DISCLAIMER.md) — especially the sections on emergency calls,
call recording law and call charges — before you put it into use.

## Trademarks

The licence covers the code, not the product name or logo. See
[TRADEMARKS.md](TRADEMARKS.md).
