# Disclaimer and operator responsibilities

**Please read this in full before installing or using QuaverDesk.**

In this document, "we", "us" and "the licensor" mean Ethan Askew T/A Quaver Desk. "You" and
"the operator" mean the person or organisation that installs, configures or
runs the software, and anyone acting for them.

By installing, running or using the software you accept this disclaimer, in
addition to the [LICENSE](LICENSE). Before calling is switched on, the software
asks an owner or administrator to confirm the key points below and keeps a
record of that confirmation.

---

## 1. No warranty — provided "as is"

The software is provided **"as is" and "as available", without warranty of any
kind**, express or implied, including any implied warranty of satisfactory
quality, fitness for a particular purpose, accuracy, availability or
non-infringement. We do not promise the software will be uninterrupted,
error-free, secure, or that calls, recordings, transcripts, voicemails or
messages will be delivered, kept or accurate.

## 2. Limitation of liability

To the fullest extent permitted by law, we will not be liable to you or anyone
else for any loss or damage arising from or connected with the software or its
use, whether in contract, tort (including negligence), breach of statutory duty
or otherwise. This includes, without limitation: loss of profit, revenue,
business, contracts, goodwill or data; missed, dropped, misrouted or
unconnected calls (including emergency calls); call charges or fraud; regulatory
fines or penalties; and any indirect or consequential loss.

**Nothing in this disclaimer limits or excludes liability for death or personal
injury caused by negligence, for fraud or fraudulent misrepresentation, or for
anything else that cannot lawfully be limited or excluded.**

If you have bought a paid licence, our total liability is further limited as set
out in [COMMERCIAL-TERMS.md](COMMERCIAL-TERMS.md).

## 3. We are not your phone company

The software is a tool for managing calls. **We do not provide telephone
service, phone numbers, call routing, internet access or emergency-service
access.** Those come from the carrier, SIP trunk provider and internet provider
*you* choose (for example Twilio, or your own UK SIP trunk provider), under your
own agreements with them.

As the operator, you are responsible for meeting every legal and regulatory
obligation that applies to you as a user or provider of communications
services, including where relevant Ofcom's General Conditions, rules on caller
identification (CLI), number use and porting, and informing your users of any
service limitations.

## 4. Emergency calls (999 / 112) — important

- The software **may not connect emergency calls**, may not pass an accurate
  caller location, and **will not work** during a power cut, internet outage,
  carrier outage, or if it is misconfigured.
- Before setup is complete, the software **cannot** place calls to the phone
  network at all, including 999.
- Whether emergency calls work, and what location is passed, depends entirely on
  your carrier or SIP trunk provider and how you configure them.
- **You must make sure everyone who uses the software has another reliable way
  to call emergency services**, and you must tell them about these limitations.
- Do not rely on the software as your only means of making emergency calls.

## 5. Call recording, transcription and monitoring

Recording, transcribing, summarising or otherwise analysing calls is regulated.
If you switch these features on, **you** are the controller of that data and are
responsible for complying with the law that applies to you, which in the UK
includes UK GDPR, the Data Protection Act 2018 and the rules on intercepting
and monitoring communications. In particular you must, where required:

- tell callers and staff that calls may be recorded or analysed, and why;
- have a lawful basis for doing so;
- set and enforce sensible retention periods; and
- respond to data-subject requests.

If you email voicemails, recordings or transcripts, they leave the software
and are stored by your email providers; email is not end-to-end encrypted.

We do not receive or process your call data. We are not your data processor.

## 6. Bring-your-own AI

AI features send call audio, transcripts and related data to **the AI provider
you connect, using your own account and API keys**. That transfer happens under
your agreement with that provider, not with us. You are responsible for choosing
the provider, its data location, its retention and training settings, and any
data-processing agreement you need.

AI output (transcripts, summaries, action items, sentiment) **can be wrong,
incomplete or biased**. Do not rely on it for legal, medical, financial,
employment or other significant decisions without human review.

## 7. Call charges and fraud

You are responsible for **all charges** from your carrier, SIP trunk provider
and AI provider, including charges caused by misconfiguration, compromised
credentials or fraudulent calls (for example "toll fraud" to premium-rate or
international numbers). The software includes fraud controls (such as blocking
international and premium-rate calls by default), but they reduce risk rather
than remove it. Keep credentials secret, use strong passwords and two-factor
authentication, set spending limits with your providers, and monitor usage.

## 8. Security and hosting

You are responsible for securely hosting, configuring, updating, backing up and
monitoring your installation, including the server, database, network, TLS
certificates, secrets and user accounts. Apply security updates promptly. We
recommend an independent security review before you use the software in
production. Report vulnerabilities as described in [SECURITY.md](SECURITY.md).

## 9. Third-party services

The software works with third-party services such as Twilio, SIP trunk
providers, email providers and AI providers. We are **not affiliated with,
endorsed by or responsible for** any of them. Their availability, pricing,
terms and conduct are outside our control. Product names are trademarks of
their respective owners.

## 10. Your indemnity

To the extent permitted by law, you agree to indemnify us against claims,
losses, fines and costs (including reasonable legal fees) brought by third
parties — including your users, callers, regulators and providers — that arise
from your installation, configuration or use of the software, your breach of
the licence or this disclaimer, or your breach of any law.

## 11. Not legal advice

Nothing in the software or its documentation is legal, regulatory or compliance
advice. Take your own professional advice about your obligations.

## 12. General

If any part of this disclaimer is found unenforceable, the rest continues to
apply. This disclaimer is governed by the law of England and Wales, and the
courts of England and Wales have exclusive jurisdiction, except where the law
of your country gives you a right to bring proceedings elsewhere.

*Version 2026-10-29.*
