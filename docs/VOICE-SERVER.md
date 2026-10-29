# The voice server (FreeSWITCH)

QuaverDesk carries calls on its own [FreeSWITCH](https://signalwire.com/freeswitch)
server instead of a calling platform. You pay only your SIP trunk provider for
calls to and from phone numbers; calls between colleagues cost nothing.

When you set up a new system, work through
[the test list](DEPLOYMENT.md#a12-test-before-real-calls) before anyone relies on it.

## How it fits together

```
 Browsers ──wss 7443──┐                         ┌── SIP trunk provider(s)
 Desk phones ─TLS 5061┤      FreeSWITCH         │   (5080, from their IPs only)
                      └─► internal profile ◄────┴── external profile
                               │       ▲
            HTTP webhooks      │       │ event socket (127.0.0.1 only):
            (mod_httapi)       ▼       │ reload users, trunks, allow-list
                          QuaverDesk app (Laravel)
                               │
                writes VOICE_CONF_DIR: users, gateways, ACL, vars
```

- **Settings flow app → FreeSWITCH.** When users, desk phones or trunks change,
  the app rewrites the files in `VOICE_CONF_DIR` and reloads FreeSWITCH over
  its event socket (`php artisan voice:sync` does the same by hand). The rest
  of the FreeSWITCH config is in `deploy/freeswitch/conf` and changes only
  with the code.
- **Calls flow FreeSWITCH → app.** For every call FreeSWITCH asks the app what
  to do (`/webhooks/voice/*`), authenticated with `VOICE_WEBHOOK_SECRET`. The
  app answers with an httapi document: ring these people, play this, record a
  voicemail. Recordings are uploaded back to the app, which encrypts them.
- FreeSWITCH may only run the `bridge` application and read one variable on
  the app's behalf (see `autoload_configs/httapi.conf.xml`), so even a
  compromised app could not make it run arbitrary commands.

Run FreeSWITCH on the same server as the app. The event socket then never
leaves the machine, and webhooks stay local.

## Install

FreeSWITCH packages come from SignalWire and are built for **Debian 12**. On
Ubuntu 24.04, build from source instead (see the FreeSWITCH docs) or put the
phone system on Debian 12; the rest of [DEPLOYMENT.md](DEPLOYMENT.md) works on
both.

1. Create a free SignalWire account and a **Personal Access Token**
   (SignalWire dashboard → Personal Access Tokens). It is only used to
   download packages.
2. Add the repository and install the modules QuaverDesk uses:

   ```bash
   TOKEN=pat_xxxxxxxx   # your token
   sudo apt install -y gnupg2 wget lsb-release
   sudo wget --http-user=signalwire --http-password="$TOKEN" \
     -O /usr/share/keyrings/signalwire-freeswitch-repo.gpg \
     https://freeswitch.signalwire.com/repo/deb/debian-release/signalwire-freeswitch-repo.gpg
   echo "machine freeswitch.signalwire.com login signalwire password $TOKEN" \
     | sudo tee /etc/apt/auth.conf.d/freeswitch.conf >/dev/null
   sudo chmod 600 /etc/apt/auth.conf.d/freeswitch.conf
   echo "deb [signed-by=/usr/share/keyrings/signalwire-freeswitch-repo.gpg] https://freeswitch.signalwire.com/repo/deb/debian-release/ $(lsb_release -sc) main" \
     | sudo tee /etc/apt/sources.list.d/freeswitch.list
   sudo apt update
   sudo apt install -y freeswitch freeswitch-mod-sofia freeswitch-mod-httapi \
     freeswitch-mod-event-socket freeswitch-mod-commands freeswitch-mod-dptools \
     freeswitch-mod-valet-parking \
     freeswitch-mod-dialplan-xml freeswitch-mod-loopback freeswitch-mod-sndfile \
     freeswitch-mod-native-file freeswitch-mod-tone-stream freeswitch-mod-opus \
     freeswitch-mod-flite freeswitch-mod-console freeswitch-mod-logfile
   ```

3. Replace the default config with QuaverDesk's, and give the app a folder it
   can write and FreeSWITCH can read:

   ```bash
   sudo systemctl stop freeswitch
   sudo mv /etc/freeswitch /etc/freeswitch.dist
   sudo cp -r /srv/quaverdesk/deploy/freeswitch/conf /etc/freeswitch
   sudo install -d -o quaverdesk -g freeswitch -m 2750 /etc/freeswitch/quaverdesk
   sudo install -d -o freeswitch -g freeswitch -m 750 /etc/freeswitch/tls
   ```

   The setgid folder (`2750`) makes everything the app writes there readable
   by FreeSWITCH and by nobody else.

4. In the app's `.env`:

   | Setting | Value |
   | --- | --- |
   | `VOICE_DOMAIN` | the name phones sign in to, e.g. `phone.yourbusiness.co.uk` (can be the app's own domain) |
   | `VOICE_WEBHOOK_SECRET` | `openssl rand -hex 24` |
   | `VOICE_APP_URL` | empty (uses `APP_URL`) |
   | `VOICE_CONF_DIR` | `/etc/freeswitch/quaverdesk` |
   | `VOICE_WEBSOCKET_URL` | `wss://VOICE_DOMAIN:7443` |
   | `VOICE_EXTERNAL_IP` | the server's public IP (empty = detect) |
   | `VOICE_TLS` | `true` |
   | `VOICE_TRUNK_ADDRESS` | `sip:PUBLIC-IP:5080`, shown to you on each trunk page |
   | `VOICE_ESL_PASSWORD` | `openssl rand -hex 24` |
   | `VOICE_ESL_PORT` | `8021` |
   | `VOICE_CALLER_ID` | your main number, e.g. `+442079460000` |

   Then `sudo -u quaverdesk php artisan optimize`.

5. **Certificates.** Desk phones and browsers only connect encrypted, with a
   certificate for `VOICE_DOMAIN`. Reuse the Let's Encrypt certificate from
   [A7](DEPLOYMENT.md#a7-web-server-and-https) and copy it in after every renewal:

   ```bash
   sudo tee /etc/letsencrypt/renewal-hooks/deploy/freeswitch.sh >/dev/null <<'EOF'
   #!/bin/sh
   D=phone.yourbusiness.co.uk
   L=/etc/letsencrypt/live/$D
   for f in agent.pem wss.pem; do
     cat "$L/fullchain.pem" "$L/privkey.pem" > /etc/freeswitch/tls/$f
   done
   cp "$L/chain.pem" /etc/freeswitch/tls/cafile.pem
   chown freeswitch:freeswitch /etc/freeswitch/tls/*.pem
   chmod 600 /etc/freeswitch/tls/*.pem
   systemctl is-active --quiet freeswitch && fs_cli -P 8021 -p "$(grep -oP '^VOICE_ESL_PASSWORD=\K.*' /srv/quaverdesk/.env)" -x 'sofia profile internal restart' || true
   EOF
   sudo chmod 700 /etc/letsencrypt/renewal-hooks/deploy/freeswitch.sh
   sudo /etc/letsencrypt/renewal-hooks/deploy/freeswitch.sh
   ```

   Edit `D=` to your `VOICE_DOMAIN`. (`/etc/freeswitch/tls` is the Debian
   package's certificate folder.)

6. Write the generated settings **before** starting FreeSWITCH, then start it:

   ```bash
   sudo -u quaverdesk php artisan voice:sync
   sudo systemctl enable --now freeswitch
   ```

   From then on the app reloads FreeSWITCH by itself whenever people, phones or
   trunks change (through the queue worker).

## Firewall

| Port | From | For |
| --- | --- | --- |
| 5061/tcp | anywhere (or your offices) | desk phones (TLS) |
| 7443/tcp | anywhere | browser softphones (wss) |
| 5080/udp, 5080/tcp | **your trunk provider's IPs only** | calls to and from phone numbers |
| 16384–32768/udp | anywhere | call audio (RTP) |

```bash
sudo ufw allow 5061/tcp
sudo ufw allow 7443/tcp
sudo ufw allow from 203.0.113.10 to any port 5080   # repeat per provider IP
sudo ufw allow 16384:32768/udp
```

Never open 5060 or the event socket (8021). FreeSWITCH also checks trunk calls
against the allow-list the app writes, but the firewall stops SIP scanners
before they reach it.

## Speech

Prompts (menus, voicemail greetings, the recording notice, system messages)
are spoken by a free neural voice that runs on this server: no per-use cost
and no text leaves the machine. Each distinct prompt is made once, cached in
`storage/app/prompts`, and made ahead of time when a greeting is saved.

Admins pick the voice and its pace under **Admin → Voice**, after hearing
each one read a sentence of their choice, either at full quality or as
callers hear it over a phone line. Saving remakes every prompt in the new
voice. The `TTS_*` settings below say which voices are installed and which
one to use until an admin chooses.

Better still, record real greetings: **Admin → Sounds** takes an uploaded
file (MP3, WAV, M4A, OGG, FLAC) or a recording made in the browser, and any
voicemail box or IVR menu can play it instead of its text. Hold music is
chosen there too. Uploads are converted with ffmpeg (`apt install ffmpeg`)
to 16 kHz mono WAV at an even loudness, and stored in `storage/app/sounds`.

### Kokoro (most natural)

[Kokoro](https://github.com/thewh1teagle/kokoro-onnx) has the most lifelike
voices (British and American, female and male). Each prompt takes a couple of
seconds to make on a small server, which only matters the first time.

```bash
sudo apt install -y python3-venv
sudo python3 -m venv /opt/kokoro/venv
sudo /opt/kokoro/venv/bin/pip install kokoro-onnx soundfile
cd /opt/kokoro
R=https://github.com/thewh1teagle/kokoro-onnx/releases/download/model-files-v1.0
sudo wget $R/kokoro-v1.0.onnx $R/voices-v1.0.bin
```

```
TTS_DRIVER=kokoro
TTS_KOKORO_PYTHON=/opt/kokoro/venv/bin/python
TTS_KOKORO_MODELS=/opt/kokoro
TTS_KOKORO_VOICE=bf_emma
```

### Piper (lighter)

[Piper](https://github.com/OHF-Voice/piper1-gpl) is quicker and smaller, and a
little less natural. Install it in its own Python environment, with one or
more voices in one folder:

```bash
sudo apt install -y python3-venv
sudo python3 -m venv /opt/piper
sudo /opt/piper/bin/pip install piper-tts
sudo mkdir -p /opt/piper/voices && cd /opt/piper/voices
V=https://huggingface.co/rhasspy/piper-voices/resolve/v1.0.0/en/en_GB
sudo wget $V/cori/high/en_GB-cori-high.onnx $V/cori/high/en_GB-cori-high.onnx.json
```

```
TTS_DRIVER=piper
TTS_PIPER_BINARY=/opt/piper/bin/piper
TTS_PIPER_VOICE=/opt/piper/voices/en_GB-cori-high.onnx
```

| British voices | Sound |
| --- | --- |
| `cori/high/en_GB-cori-high` | Female, southern English, clearest |
| `alba/medium/en_GB-alba-medium` | Female, Scottish |
| `jenny_dioco/medium/en_GB-jenny_dioco-medium` | Female, warm |
| `alan/medium/en_GB-alan-medium` | Male, Scottish |
| `northern_english_male/medium/en_GB-northern_english_male-medium` | Male, northern English |

Every voice in the folder appears under Admin → Voice.

Both can be installed at once: Admin → Voice lists them all. After changing
`TTS_*` settings run `sudo -u quaverdesk php artisan optimize && sudo -u quaverdesk php artisan voice:prompts`.

`TTS_DRIVER=freeswitch` uses FreeSWITCH's own voice (mod_flite) instead: it
needs nothing extra but sounds robotic. `TTS_DRIVER=command` runs any other
program that writes a WAV file (`TTS_COMMAND`, a JSON array; text on standard
input, `{out}` is the file to write).

## Checking it works

```bash
fs_cli -P 8021 -p "$VOICE_ESL_PASSWORD"     # FreeSWITCH console
> sofia status                              # internal and external profiles RUNNING
> sofia status gateway                      # one gateway per trunk address
> sofia status profile internal reg         # signed-in softphones and desk phones
```

| Problem | Check |
| --- | --- |
| Softphone can't connect | `VOICE_WEBSOCKET_URL` uses `wss://` and the certificate matches `VOICE_DOMAIN`; port 7443 open. |
| Calls connect but there's no audio | `VOICE_EXTERNAL_IP` is the public IP; RTP ports open. |
| Every call is silent then drops; app log shows 403 on `/webhooks/voice/*` | `VOICE_WEBHOOK_SECRET` changed: run `php artisan voice:sync`. |
| Trunk calls rejected | The provider's IPs are on the trunk page, and the firewall allows them on 5080. |
| Changes to people or trunks don't take effect | Queue worker running; `php artisan voice:sync` shows any error. |

## Placing a test call

To place a test call without a phone, run this in `fs_cli`:

```
originate {origination_caller_id_number=+447700900123}loopback/+442079460000/public &endless_playback(tone_stream://%(1000,500,440))
```

That arrives as a call from a mobile to `+442079460000`, as a trunk would
deliver it.
