# Deploying QuaverDesk

This guide takes a fresh Ubuntu server to a running QuaverDesk, step by step.
It covers the phone system your business runs: calls, numbers, voicemail,
recording and AI.

> QuaverDesk is provided "as is", without warranty. It is **not a replacement
> for a landline or mobile for emergency (999) calls**. Read
> [DISCLAIMER.md](../DISCLAIMER.md) before going live.

Everything below assumes **Ubuntu 24.04 LTS** (its default PHP is 8.3), nginx
and MySQL. The phone system also runs FreeSWITCH, whose packages are built for
Debian 12; see [VOICE-SERVER.md](VOICE-SERVER.md#install). Commands starting with `sudo` run as an admin user; commands shown
with `sudo -u quaverdesk` run as the app's own user.

---

## Contents

- [Before you start](#before-you-start)
- [Set-up steps](#set-up-steps)
- [Updating](#updating)
- [Backups and restoring](#backups-and-restoring)
- [Monitoring](#monitoring)
- [Troubleshooting](#troubleshooting)
- [Go-live checklist](#go-live-checklist)

---

## Before you start

You will need:

- A server: 2 vCPU, 4 GB RAM and 40 GB disk is plenty for a small team. Choose
  a **UK or EU region** so call data stays there (most providers have London).
- A domain or subdomain pointing at the server (an `A` record), e.g.
  `phone.yourbusiness.co.uk`.
- An SMTP account with a transactional email provider, for voicemail and
  password-reset emails.
- A SIP trunk from a UK provider, and a free SignalWire account
  (to download FreeSWITCH).
- [age](https://age-encryption.org) on a computer other than the server, to
  make the backup encryption key.

**About Cloudflare:** the simplest set-up is DNS-only (grey cloud). If you
proxy through Cloudflare or any load balancer, you must set
`TRUSTED_PROXIES` to its IP ranges. Softphones and desk phones connect to the
voice server directly, so its DNS name must not be proxied (grey cloud).

---

## Set-up steps

### A1. Secure the server

Log in as your admin user with an SSH key, then:

```bash
sudo apt update && sudo apt full-upgrade -y
sudo apt install -y ufw fail2ban unattended-upgrades
sudo timedatectl set-timezone Etc/UTC          # the app shows UK time itself

# Firewall: SSH and web only.
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full' 2>/dev/null || { sudo ufw allow 80/tcp; sudo ufw allow 443/tcp; }
sudo ufw enable

# Security updates install themselves.
sudo dpkg-reconfigure -plow unattended-upgrades
```

In `/etc/ssh/sshd_config`, set `PasswordAuthentication no` and
`PermitRootLogin no`, then `sudo systemctl reload ssh`. Make sure your key login
works in a second terminal **before** closing the first.

### A2. Install the software

```bash
sudo apt install -y nginx mysql-server git unzip age certbot python3-certbot-nginx ffmpeg \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-sqlite3 php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-zip php8.3-intl php8.3-bcmath composer

# Node 22 LTS for building the front end (Ubuntu's own Node is too old).
curl -fsSL https://deb.nodesource.com/setup_22.x -o /tmp/nodesource_setup.sh
sudo bash /tmp/nodesource_setup.sh && sudo apt install -y nodejs

php -v && php -m | grep -E 'sodium|pdo_mysql' && node -v && composer --version
```

`sodium` must appear in that list: it signs and checks licence keys.

### A3. Create the database

```bash
sudo mysql_secure_installation          # accept the secure defaults
sudo mysql
```

```sql
CREATE DATABASE quaverdesk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'quaverdesk'@'localhost' IDENTIFIED BY 'A-LONG-RANDOM-PASSWORD';
GRANT ALL PRIVILEGES ON quaverdesk.* TO 'quaverdesk'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Generate the password with `openssl rand -base64 24` and keep it in your
password manager.

### A4. Create the app user and fetch the code

The app runs as its own user, which owns nothing outside `/srv/quaverdesk`.

```bash
sudo adduser --system --group --home /srv/quaverdesk --shell /bin/bash quaverdesk
sudo -u quaverdesk -H git clone https://github.com/EthAsk/QuaverDesk.git /srv/quaverdesk/tmp-clone
sudo -u quaverdesk -H bash -c 'shopt -s dotglob && mv /srv/quaverdesk/tmp-clone/* /srv/quaverdesk/ && rmdir /srv/quaverdesk/tmp-clone'
```

### A5. Configure `.env`

```bash
cd /srv/quaverdesk
sudo -u quaverdesk cp deploy/env.phone-system.example .env
sudo -u quaverdesk chmod 600 .env
sudo -u quaverdesk nano .env      # fill in every blank
```

Key settings:

| Setting | Value |
| --- | --- |
| `APP_URL` | `https://your.domain` |
| `ALLOW_WEB_SETUP` | `false` — you'll create the owner from the command line |
| `DB_PASSWORD` | the database password from A3 |
| `MAIL_*` | your SMTP provider's details |
| `LICENCE_SERVER_URL` | `https://quaverdesk.app` (or empty to renew keys by hand) |
| `VOICE_*` | filled in at step A10 |

Then generate the encryption key:

```bash
sudo -u quaverdesk php artisan key:generate
sudo -u quaverdesk grep APP_KEY .env
```

> **Copy `APP_KEY` into your password manager now.** It encrypts call
> recordings, transcripts, desk phone passwords and AI provider keys. If it is
> lost, none of those can ever be read again, even from backups.

### A6. Install, build and set up the database

```bash
cd /srv/quaverdesk
sudo -u quaverdesk -H composer install --no-dev --optimize-autoloader --no-interaction
sudo -u quaverdesk -H npm ci && sudo -u quaverdesk -H npm run build
sudo -u quaverdesk php artisan migrate --force
sudo -u quaverdesk php artisan optimize

# Only nginx needs to traverse the folder and read public/.
sudo chmod 755 /srv/quaverdesk
sudo chmod -R o-rwx /srv/quaverdesk/storage /srv/quaverdesk/bootstrap/cache
```

### A7. Web server and HTTPS

```bash
cd /srv/quaverdesk
sudo cp deploy/php-fpm/quaverdesk.conf /etc/php/8.3/fpm/pool.d/quaverdesk.conf
sudo systemctl reload php8.3-fpm

sudo cp deploy/nginx/quaverdesk.conf /etc/nginx/sites-available/quaverdesk
sudo sed -i 's/phone.example.co.uk/your.domain/' /etc/nginx/sites-available/quaverdesk
sudo ln -s /etc/nginx/sites-available/quaverdesk /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

sudo certbot --nginx -d your.domain --redirect     # free certificate, auto-renewing
```

Check: `curl -I https://your.domain/up` should return `200`.

### A8. Background worker, scheduler and backups

The worker saves recordings, runs AI and sends voicemail emails. The scheduler
deletes expired recordings and checks daily for a renewed licence key.

```bash
cd /srv/quaverdesk
sudo cp deploy/systemd/quaverdesk-queue.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now quaverdesk-queue
systemctl status quaverdesk-queue --no-pager

# Root-owned copies of the root scripts. Never run a root job from the app
# folder: the app user can write there.
sudo install -o root -g root -m 700 deploy/backup.sh /usr/local/sbin/quaverdesk-backup
sudo install -o root -g root -m 700 deploy/deploy.sh /usr/local/sbin/quaverdesk-deploy

sudo cp deploy/cron/quaverdesk /etc/cron.d/quaverdesk && sudo chmod 644 /etc/cron.d/quaverdesk
```

Set up backup encryption. **On a computer other than the server**, so the
private key never sits beside the backups:

```bash
age-keygen -o quaverdesk-backup-key.txt     # keep this file safe and OFF the server
grep 'public key' quaverdesk-backup-key.txt  # copy the age1… value
```

Then **on the server**:

```bash
sudo mkdir -p /etc/quaverdesk && sudo chmod 700 /etc/quaverdesk
echo 'age1…PASTE-PUBLIC-KEY…' | sudo tee /etc/quaverdesk/backup-recipient.txt

# Database login for backups.
sudo tee /etc/quaverdesk/backup.my.cnf >/dev/null <<'EOF'
[client]
user=quaverdesk
password=THE-DATABASE-PASSWORD
EOF
sudo chmod 600 /etc/quaverdesk/backup.my.cnf

sudo quaverdesk-backup                      # test it now
ls -l /var/backups/quaverdesk/
```

Backups on the same server are not backups. Copy `/var/backups/quaverdesk` to
another provider daily, for example with `rclone` to object storage in the UK.

### A9. Create the owner account

```bash
cd /srv/quaverdesk
sudo -u quaverdesk php artisan user:create-owner
```

Then in the browser:

1. Sign in at `https://your.domain`.
2. **Security**: turn on two-factor authentication. It's required for admin pages.
3. **Setup & terms**: read and accept the operator statements. Calling stays off
   until this is done, and the acceptance is recorded.
4. **Admin → Licence** (owner only): paste the licence key for Plus or Pro.

### A10. Voice server and SIP trunk

1. **Install FreeSWITCH** and connect it to the app: follow
   [VOICE-SERVER.md](VOICE-SERVER.md) (install, `VOICE_*` settings,
   certificates, firewall).
2. **SIP trunk:** sign up with a UK SIP trunk provider and get one or more UK
   numbers on the trunk. In QuaverDesk, **Admin → SIP trunks → Connect a
   trunk**, enter the provider's addresses, then send them what the trunk page
   shows (this server's address, port 5080).
3. **Numbers:** **Admin → Phone numbers**: add each number and choose where its
   calls go. Set the caller ID under **Admin → Calling rules**.
4. **Fraud limits with your provider** (on top of QuaverDesk's own rules):
   ask them to bar international and premium-rate destinations you don't call,
   cap daily spend, and alert you on unusual usage.

Apply the new settings and check the worker picked them up:

```bash
cd /srv/quaverdesk && sudo -u quaverdesk php artisan optimize && sudo -u quaverdesk php artisan queue:restart
```

**Desk phones and mobile apps (Plus):** **Admin → Phones and apps**. Phones connect to this server
over TLS on port 5061 with SRTP (opened in the firewall step). An office
firewall must allow the phones out to it.

### A11. Email that arrives

QuaverDesk emails people:

- **invites** to new users, with a link to set their password (valid 7 days, works once);
- **voicemails**, with the audio and (Plus/Pro, if switched on) a transcript and summary;
- **password resets**, and **security alerts** when a password changes, two-factor
  is turned on or off, or a passkey is added or removed.

Use any SMTP service: a transactional provider such as Postmark, Amazon SES,
Mailgun or Brevo (best for deliverability), or Google Workspace. Put its details
in `MAIL_*`. For **Microsoft 365**, use the `graph` mailer below rather than
SMTP: Microsoft is retiring password sign-in for SMTP.

**Microsoft 365 (`MAIL_MAILER=graph`).** QuaverDesk signs in as an app, not as
a person, and may send only as one mailbox:

1. Create a **shared mailbox** for `MAIL_FROM_ADDRESS` (no licence needed).
2. Entra admin centre → **App registrations → New**. Note the **Directory
   (tenant) ID** and **Application (client) ID**, and add a **client secret**
   (note when it expires: mail stops then). Do **not** add Graph API
   permissions here; they would cover every mailbox.
3. In Exchange Online PowerShell, allow it to send as that mailbox only (the
   object ID is on the app's **Enterprise applications** page):

   ```powershell
   New-ServicePrincipal -AppId <client-id> -ObjectId <enterprise-app-object-id> -DisplayName "QuaverDesk mailer"
   New-ManagementScope -Name "QuaverDesk mailbox" -RecipientRestrictionFilter "PrimarySmtpAddress -eq 'noreply@your.domain'"
   New-ManagementRoleAssignment -App <client-id> -Role "Application Mail.Send" -CustomResourceScope "QuaverDesk mailbox"
   ```

4. Set `MAIL_MAILER=graph`, `MAIL_GRAPH_TENANT_ID`, `MAIL_GRAPH_CLIENT_ID`
   and `MAIL_GRAPH_CLIENT_SECRET`, then `php artisan optimize`. Changes to the
   role assignment can take up to an hour to apply.

Long voicemails are uploaded in pieces, so the 3 MB limit on a single Graph
request doesn't apply. These emails are useless in a spam folder, so with
your email provider:

- Verify the sending domain in `MAIL_FROM_ADDRESS`.
- Add the **SPF** and **DKIM** DNS records they give you, plus a **DMARC**
  record (start with `v=DMARC1; p=none; rua=mailto:you@your.domain`).
- Send a test with **Forgot password** on the sign-in page.

### A12. Test before real calls

Work through this list with a colleague before anyone relies on the system:

- [ ] Softphone to softphone call (Free plan feature).
- [ ] Outgoing call to a mobile; the right caller ID shows.
- [ ] Incoming call to each number reaches the right person, ring group or menu.
- [ ] Out-of-hours routing (set hours briefly to "closed").
- [ ] An unanswered call leaves a voicemail; the email arrives with audio.
- [ ] A recorded call: the notice plays; the recording appears under
      **Voicemail & recordings**.
- [ ] AI: **Admin → AI provider → Test**, then "Transcribe & summarise" on a recording.
- [ ] A call to an international number you haven't allowed is refused.
- [ ] Emergency calls: **never place a test call to 999.** Confirm in writing
      with your trunk provider how emergency calls and caller
      location work for your numbers, and record the answer.
- [ ] Desk phone registers, rings and dials out (Plus).
- [ ] Transfer: during a call from outside, **Transfer now** to a colleague's
      extension, then **Talk first** to another; both reach them. Transfer to a
      ring group's extension and to a mobile.
- [ ] Hold plays your hold music to the other person.
- [ ] Park: **Park** a call, then dial `**` and the space number from another
      phone (or desk phone) to pick it up. Leave one parked for 3 minutes: it
      rings the person who parked it.
- [ ] Desk phone: transfer a call with the handset's own Transfer key, and park
      one by transferring it to `**1`.
- [ ] Follow-me: under **Phone**, add your mobile, confirm it (press 1), turn
      it on, then call your number: the mobile rings, says to press 1, and
      connects. Let it go to the mobile's voicemail: the call keeps ringing
      your other phones instead.
- [ ] `sudo quaverdesk-backup` runs, and a restore works (see below).

---

## Updating

To update to the latest version:

```bash
sudo quaverdesk-deploy
```

This pulls the code, installs dependencies, builds assets, migrates the
database, rebuilds caches, reloads PHP and restarts the worker. Calls keep
being answered during the short maintenance window. Deploy out of hours, and
read the commit messages for anything to do by hand.

If you changed the scripts in `deploy/`, reinstall the root-owned copies
(step A8) before running them.

**Rolling back:** find the commit you were on with
`sudo -u quaverdesk git -C /srv/quaverdesk log --oneline`, then go back to it
with `sudo -u quaverdesk git -C /srv/quaverdesk reset --hard COMMIT` and repeat
step A6. If the update changed the database, restore the backup taken just
before it rather than trying to reverse migrations. The next
`sudo quaverdesk-deploy` brings you up to date again.

---

## Backups and restoring

Every night, `quaverdesk-backup` writes one encrypted file to
`/var/backups/quaverdesk`, holding the database, `.env` (with `APP_KEY`)
and the encrypted recordings. It keeps 14 days.

**Restore** (on a freshly built server at step A6, before migrating):

```bash
# On the server, with your backup private key copied over temporarily:
mkdir -p /tmp/restore && chmod 700 /tmp/restore
age -d -i quaverdesk-backup-key.txt quaverdesk-YYYYMMDDTHHMMSSZ.tar.gz.age | tar -xz -C /tmp/restore
sudo -u quaverdesk tar -xf /tmp/restore/files.tar -C /srv/quaverdesk     # .env and recordings
mysql -u quaverdesk -p quaverdesk < /tmp/restore/database.sql
shred -u quaverdesk-backup-key.txt && rm -rf /tmp/restore
```

Then carry on from `php artisan optimize` in A6. Test a restore once before
you go live, then every few months. An untested backup isn't a backup.

---

## Monitoring

- **Uptime:** point an uptime monitor at `https://your.domain/up` (returns
  `200` when the app is healthy).
- **Worker:** `systemctl status quaverdesk-queue`. If it stops, voicemails
  aren't emailed and recordings aren't saved.
- **Failed jobs:** `sudo -u quaverdesk php artisan queue:failed`. Retry them
  with `queue:retry all`.
- **Logs:** `storage/logs/laravel-YYYY-MM-DD.log`, `/var/log/nginx/quaverdesk.*`,
  `/var/log/quaverdesk-backup.log`.
- **Disk:** recordings are the main thing that grows. Check `df -h` monthly,
  and shorten retention under **Admin → Recording** if needed.
- **Voice server:** `systemctl status freeswitch`. Your trunk provider's spend
  alerts (step A10) are your early warning for fraud.

---

## Troubleshooting

| Symptom | Likely cause and fix |
| --- | --- |
| Every call fails; logs say "Rejected an unauthenticated voice webhook" | FreeSWITCH is sending an old `VOICE_WEBHOOK_SECRET`. Run `php artisan optimize`, then `php artisan voice:sync`. |
| A setting change in `.env` has no effect | Config is cached in production. Run `sudo -u quaverdesk php artisan optimize`, then `php artisan queue:restart`. |
| Softphone says "phone service is not configured" | `VOICE_DOMAIN` or `VOICE_WEBSOCKET_URL` is missing. More voice checks: [VOICE-SERVER.md](VOICE-SERVER.md#checking-it-works). |
| Voicemail recorded, but no email | Worker not running (`systemctl status quaverdesk-queue`), or `MAIL_*` wrong. Check `queue:failed`. |
| Licence page says the key was rejected | The key has expired, or was copied incompletely. Paste the whole key from your latest licence email. |
| "Page expired" (419) after signing in | `SESSION_SECURE_COOKIE=true` but the site was opened over http, or the clock is wrong (`timedatectl`). |
| Recordings or AI keys suddenly unreadable | `APP_KEY` changed. Restore the original from your password manager. |
| Desk phone won't register | Port 5061 open; it must use TLS with SRTP set to compulsory, and the username and password from **Admin → Phones and apps → Show settings**. |

---

## Go-live checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `ALLOW_WEB_SETUP=false`, `SESSION_SECURE_COOKIE=true`
- [ ] `APP_KEY` and database password stored in a password manager
- [ ] HTTPS working; `/up` returns 200 and is monitored
- [ ] Owner and all admins have two-factor on
- [ ] Setup & terms accepted; recording notice wording checked
- [ ] Trunk provider: international/premium barred unless needed; spend cap and alerts on
- [ ] Voice server firewall: 5080 only from the trunk provider; 5060 and 8021 closed
- [ ] Worker running; scheduler entry in `/etc/cron.d`
- [ ] Nightly backup runs, is copied off the server, and a restore has been tested
- [ ] Staff told the system is **not** for emergency calls
- [ ] Test plan in A12 passed
