# Desk phones: automatic set-up and busy lamp keys

QuaverDesk can set up Yealink, Grandstream and Fanvil desk phones by itself.
You add the phone in the admin pages, point it at the company's provisioning
address once (or let the office router do that with DHCP option 66), and the
phone downloads everything it needs. This includes:

- its SIP account;
- a new password for its own web page;
- UK time;
- the voicemail code;
- busy lamp keys for the colleagues you choose.

It then checks for changes every day. Every download and every refusal is
recorded in the phone log.

Any other SIP phone or app still works: you type its settings in by hand
(**Admin → Phones and apps → Show settings**).

## What you need

- **Plus or Pro plan.** Desk phones are a Plus feature.
- **The voice server running with TLS** (`VOICE_TLS=true`, see
  [VOICE-SERVER.md](VOICE-SERVER.md)). Desk phones only connect encrypted:
  TLS on port 5061 for signalling and compulsory SRTP for audio.
- **A supported phone with current firmware:**

  | Make | Models | File it asks for |
  | --- | --- | --- |
  | Yealink | T-series (T3x, T4x, T5x) | `<mac>.cfg` |
  | Grandstream | GRP26xx | `cfg<mac>.xml` |
  | Fanvil | X series (X3U to X7) | `<mac>.cfg` |

  Phones must trust the web server's certificate (Let's Encrypt is trusted
  by current firmware). Very old firmware may need updating first.

## How it works

1. Each company has one **provisioning address**,
   `https://<your web address>/provision/<token>/`. The token is random, and
   the address also needs a user name (`phones`) and a password. Both are
   shown under **Admin → Phones and apps → Automatic set-up**.
2. A phone given that address asks for its own file by MAC address (see the
   table above).
3. QuaverDesk serves the file only if:
   - the token, user name and password are right;
   - a phone with that MAC address has been added in this company;
   - the request comes from an address the phone is locked to, if it is
     locked.

   Anything else gets nothing, and the refusal is logged. Model-wide files
   and firmware are not served. Each IP address may make 30 requests a
   minute.
4. The file sets:

   | Setting | Value |
   | --- | --- |
   | Account 1 | The phone's SIP username and password, server `VOICE_DOMAIN`, port 5061, TLS |
   | Audio | SRTP compulsory |
   | Display name and label | The person's name (and extension) |
   | Web page logins | `admin` gets a random password made for this phone. So do the other built-in logins (Yealink `user` and `var`, Grandstream `user`, Fanvil `guest`), so none keeps its default password. |
   | Voicemail key | `*00` |
   | Time | UK time (London, with summer time), NTP `uk.pool.ntp.org` |
   | Checks for changes | Every 24 hours |
   | Busy lamp keys | Only once chosen for the phone (see below) |

   Changes made on the phone itself are replaced at its next check.

## Setting up a phone

### 1. Add the phone

**Admin → Phones and apps → Add a phone.** Choose the person, give the phone a
name, choose its make under **Set up automatically**, and enter its MAC address
(on the label on the back, in any format, for example `80:5E:C0:12:34:56`).

For a phone that's already in the list, choose its make and MAC address on its
**Automatic set-up** line and press **Save**.

Optionally fill in **Only sign in from**: the office's public IP address,
range or host name (up to 5, comma-separated). The phone can then only sign
in, make calls and download its settings from there, or from a local network
address (10.x, 172.16–31.x, 192.168.x), which only reaches the server from its
own network. Tick **Filter LAN addresses** to allow only the listed addresses.

### 2. Point the phone at the provisioning address

Copy the values from the **Automatic set-up** card. Each one has a copy button.

- **Yealink:** web page → *Settings → Auto Provision*: enter the **Server URL**,
  **User Name** and **Password**, then press *Auto Provision Now*.
- **Grandstream (GRP):** web page → *Maintenance → Upgrade and Provisioning*:
  1. Set *Config Upgrade Via* to HTTPS.
  2. Enter the *Config Server Path*. This is the address **without**
     `https://`; the card shows it with its own copy button.
  3. Enter the *HTTP/HTTPS User Name* and *Password*.
  4. Press *Provision*.
- **Fanvil (X series):** web page → *System → Auto Provision*: enter the
  **Server URL**, **User Name** and **Password**, then press *Apply*.

A phone's web page is at its IP address (shown in its network menu). A new
phone's default login is usually `admin` / `admin`. After the first download it
is the phone's own admin password: **Show admin password** on its line.

### 3. Or use DHCP option 66

Instead of typing the address into each phone, you can set it once on the
office router as **DHCP option 66** (a text value). The card shows the value
with the login included: `https://phones:<password>@<your web address>/provision/<token>/`.

Before you turn it on, check these:

- **Option 66 only says where the settings are.** The phone still needs adding
  first (step 1). Any other phone on the network that tries is refused, and
  the refusal shows in the phone log.
- **Phones need factory settings**, or their DHCP option setting left on. A
  second-hand phone may have it turned off or another server saved, so
  factory-reset it first.
- **The login inside the address** works on Yealink. Some Grandstream and
  Fanvil firmware ignores it. If the log shows *Wrong provisioning login* for
  such a phone, enter the user name and password on the phone once by hand.
- **The password can be read by anything on that network**, because DHCP
  answers anyone. With the password and a phone's MAC address, someone could
  download that phone's SIP password. Either:
  - use option 66 only on a separate network for phones (a voice VLAN); or
  - use it only for the rollout, then remove it and press **Make a new
    address and password**. Phones already set up keep working, but can't
    fetch changes until they're given the new values.

### The set-up window and new addresses

A MAC address isn't secret, so the provisioning login and MAC address alone
aren't enough to get a phone's settings. When you add a phone, or change its
make or MAC address, a **set-up window** opens for 24 hours. The phone's first
download in that time is accepted from anywhere, and the address it came from
becomes the phone's **trusted address**. Then the window closes.

After that, the phone's daily checks from its trusted address carry on as
normal. A download from any other address is held back, and the phone's line
shows **Waiting for approval**, with the address and phone model. The same goes
for a phone that has moved, or an office whose internet address has changed. If
it really is your phone, press **Approve**: that address becomes trusted and the
phone is told to fetch its settings. For a replacement handset or a move to a
new site, press **Open set-up window** instead. Approvals and set-up windows are
recorded in the phone log.

### 4. Check it worked

Look in the **phone log** at the bottom of the page. You should see *Phone
downloaded its settings*, followed by the phone signing in. The phone's line
shows when it last fetched its settings, from where, and its model and
firmware.

## Busy lamp keys (BLF)

A busy lamp key shows a colleague's phone status:

| Lamp | Meaning |
| --- | --- |
| Off (or green) | Free |
| Flashing | Their phone is ringing |
| On (or red) | They're on a call |

Colours and patterns vary a little by make. Press the key to call them. Press
it during a call to transfer the call to them. A ringing lamp calls the
colleague; it doesn't pick up their call.

**To choose them:** open **Busy lamp keys** on the phone's line, tick the
colleagues and press **Save keys**. Key 1 stays the phone's own line. The
colleagues follow on keys 2 onwards in extension order, up to 20. A phone set
up automatically is told to fetch them straight away. The change is recorded
in the phone log.

- Until you first save keys for a phone, QuaverDesk leaves its keys alone, so
  keys set up on the phone by hand aren't wiped.
- After that, keys 2 to 21 are managed: unticked colleagues' keys are cleared.
- People who are removed, or made forward-only, drop off the keys at the
  phone's next check.

**On a phone set up by hand,** the same panel lists a value for each
colleague. On the phone's web page, set a key's type to **BLF**, account 1,
and enter that value.

**Privacy.** Phones of every company share the voice server's SIP domain, so a
phone never watches a colleague by username or extension. Each person has a
secret busy lamp address (`blf-` and 20 letters and digits, made from the app
key), given only to phones of their own company. Only signed-in phones may
subscribe, within their IP lock. Pressing the key works only for a colleague
in the caller's own company. The voice server doesn't announce sign-ins as
presence.

If you change the app key (`APP_KEY`), everyone's address changes. Phones set
up automatically get the new ones at their next check. Phones set up by hand
need re-entering.

## Phones without a person, and phones that can't call out

For a reception, lobby or hall phone, add a user and choose **Desk phones only (no sign-in)** as the role, then add their phone. They have an extension, ring groups and voicemail like anyone else, but no sign-in, softphone or invite email. They count as one of your plan's users. Under **Admin → Users** you can switch anyone between a full account, desk phones only and forward-only.

To stop a person's phones calling outside numbers, set **Calls** to **Colleagues only** on the Users page. They can still call any extension in the company, and 999 and 112 always work. This applies to transfers too, and to their softphone as well as their desk phones.

Use Colleagues only to block outside calls, not **Stop outgoing calls to phone numbers** on the Trunks page. If no trunk is set for outgoing calls, 999 and 112 still go out on any active trunk (the one with most of your numbers first), showing one of your numbers. The Trunks page warns you if no trunk is active, because then 999 can't be made.

**Voicemail on / off**, also on the Users page, controls where calls they don't answer go. With voicemail off the call just ends. Their voicemail box and any messages in it are kept, so turning it back on puts everything back as it was.

**Admin → Phones and apps** shows whether each phone is online. When it is, you also see the address it signs in from and the phone model. If the voice server can't be reached, the status shows as *Unknown*.

## Making changes

| To | Do |
| --- | --- |
| Send changed settings now | **Send settings now** on the phone's line (the phone is asked by SIP NOTIFY to fetch at once, if it's signed in). Otherwise it fetches at its daily check or when it restarts. |
| See the phone's web page password | **Show admin password** (after confirming your own password; recorded) |
| Change it | **New admin password**: the phone gets it at its next fetch, which is asked for straight away |
| Stop automatic set-up | Choose *Off* on its **Automatic set-up** line. The phone keeps its settings but gets no more. |
| Replace a broken phone | Change the MAC address on its line, then point the new phone at the provisioning address |
| Change the company's provisioning login | **Make a new address and password**. The old one stops working at once; give phones the new one. |
| Remove a phone | **Remove**: it's signed out at once and its file is no longer served |

## The phone log

Every event is kept with when, which phone, who did it (or *The phone*), from
which IP address, and details:

- phone added or removed;
- automatic set-up changed;
- busy lamp keys changed;
- settings downloaded, or download refused;
- SIP settings shown;
- admin password shown or changed;
- phone told to fetch its settings;
- sign-in lock changed;
- provisioning login changed.

Admins see the last 100 under **Phones and apps**. On a hosted service, the
operator sees them on the company's page.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Nothing in the phone log | The phone didn't reach the address. Check the token in the address (a wrong one gets nothing, and isn't logged), the phone can reach the web server on HTTPS, and its firmware trusts the certificate. On Grandstream, check the server path has no `https://`. |
| *Wrong provisioning login* | Re-enter the user name and password (or the login isn't being taken from the DHCP address: enter it by hand). |
| *No phone is set up with MAC address …* | Add the phone, or correct its MAC address. |
| *Asked from outside the addresses the phone is locked to* | The address in the log isn't in **Only sign in from**. If it's a local address (192.168.x and so on), untick **Filter LAN addresses** or add it. |
| Downloaded, but won't sign in | Port 5061 must reach the voice server directly, not through a proxy, and the phone must be on its lock's address. In `fs_cli`: `sofia status profile internal reg`. |
| Signs in, but calls have no audio | Check that compulsory SRTP is supported on the firmware, and that the RTP ports are open (see [VOICE-SERVER.md](VOICE-SERVER.md)). |
| Busy lamp keys stay dark | Press **Send settings now**. Check the voice server has presence turned on (see [VOICE-SERVER.md](VOICE-SERVER.md#busy-lamp-keys)). |
