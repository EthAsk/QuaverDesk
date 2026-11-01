# Changelog

Each release of QuaverDesk and what changed in it. Read the release you're updating to before running
`sudo quaverdesk-deploy`: anything you need to do by hand is listed under it. The version you're running is shown at
the foot of every page.

Versions follow [semantic versioning](https://semver.org): a patch (1.0.1) is for fixes and security updates, a minor
version (1.1.0) adds features, and a major version (2.0.0) may need changes on your side.

## Unreleased

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
