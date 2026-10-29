# Security policy

## Reporting a vulnerability

Please report security problems **privately**, never in public issues,
discussions or pull requests.

- Email: **security@quaverdesk.app**
- Or use GitHub's **Report a vulnerability** button on this repository
  (Security → Advisories).

Please include:

- what you found and why it matters (what an attacker could do);
- steps to reproduce it, or a proof of concept;
- the commit you tested (`git log -1 --oneline`);
- how you'd like to be credited, if at all.

### What happens next

| When | What we do |
| --- | --- |
| Within 3 UK business days | Confirm we've received your report. |
| Within 10 UK business days | Tell you whether we've reproduced it, and how serious we think it is. |
| Until it's fixed | Keep you updated at least every 2 weeks. |
| When it's fixed | Release the fix, publish an advisory, and credit you (if you want). |

We ask you to keep the details private until a fix is released, or for 90 days
from your report, whichever is sooner. If we need longer, we'll explain why and
agree a date with you.

We do not currently run a paid bug bounty.

### Safe harbour

We won't pursue or support legal action against good-faith research that
follows this policy. While testing, please:

- use your own installation, or one you have written permission to test;
- not access, change or delete other people's data, recordings or messages;
- not place calls at someone else's expense, or attack phone networks or trunks;
- not degrade a service for its users (no denial-of-service or spam);
- stop and tell us straight away if you reach data that isn't yours.

### In scope

This repository: the web app, the voice-server configuration in `deploy/`, the
browser phone, and the licence code.

### Out of scope

- FreeSWITCH, PHP, Laravel or other upstream projects (report those to them;
  tell us too if QuaverDesk's use of them makes it worse).
- Findings on installations whose operator hasn't followed the checklist below.
- Missing best-practice headers or settings with no demonstrated impact.
- Social engineering, and physical attacks.

## Supported versions

Only the latest version on the `main` branch receives security fixes.

## Operator checklist

You run your own installation, so its security is in your hands. At minimum:

- Serve the app only over HTTPS and set `TRUSTED_PROXIES` correctly behind a
  load balancer (webhook URLs and client IPs depend on it).
- Set `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`.
- Keep `.env`, the voice-server webhook secret, SIP trunk passwords and
  database backups out of version control and away from public storage.
- Keep the FreeSWITCH event socket on localhost and open only the voice ports
  listed in docs/VOICE-SERVER.md; allow SIP port 5080 only from your trunk
  provider, and never open 5060.
- Review the call limits under **Admin → Calling rules**, and set spend limits
  and usage alerts with your trunk and AI providers too.
- Encourage everyone to use two-factor authentication or a passkey.
- Keep the server, PHP, ffmpeg and FreeSWITCH updated (unattended upgrades),
  and dependencies current (`composer audit`, `npm audit`).

## What the software does to protect you

**Toll fraud**

- Daily limits on outside calls and minutes per person, a cap on simultaneous
  outside calls, and a maximum call length enforced by the voice server itself.
  Admins are emailed when a limit is hit. Emergency calls are never limited.
- International and premium-rate calls are blocked unless an admin allows them.

**Accounts**

- Login and two-factor attempts are rate-limited.
- Owners and admins must use two-factor authentication to reach admin pages,
  and re-enter their password for sensitive changes (trunks, licence, desk
  phones, calling rules).
- Passkeys (Plus and Pro).
- Changing a password signs out every other session and gives the person's
  phones a new SIP password, so a stolen session or phone login stops working.
- People are emailed when their password, two-factor or passkeys change.
- Invite links are signed, expire after 7 days and work once.

**Calls and phones**

- Every phone must authenticate to the voice server; who is calling is taken
  from that login, never from caller ID.
- The voice server's requests to the app carry a shared secret; it may only
  play audio, gather digits, record and bridge calls.
- Calls from trunks are only accepted from the provider addresses you list.
- Desk phones must encrypt their audio (TLS and SRTP).
- Extensions can never shadow 999, 112 or other UK service numbers.

**Data**

- Recordings and voicemail are encrypted at rest, are never served from a file
  URL, and only the people involved (and admins) can play them.
- People only see their own call history.
- API keys for AI providers are stored encrypted, and are only ever sent over
  https (or to a model on the same server).
- Uploaded audio is converted in a locked-down way: the file's real type
  decides how it is read, and it can't make the converter open other files.

**Web**

- Content-Security-Policy, HSTS over HTTPS, frame denial and no-sniff headers
  on every page; cross-site request forgery protection on every form.
