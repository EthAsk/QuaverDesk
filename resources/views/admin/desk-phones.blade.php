@extends('layouts.app')
@section('title', 'Phones and apps')
@section('content')
    <div class="page-head">
        <h1>Phones and apps</h1>
        <p class="muted">Desk phones (for example Yealink, Poly, Grandstream) and mobile SIP apps. Each one rings together with its person's softphone.</p>
        <p class="small muted">The app must support TLS and SRTP. On iPhone, choose one with push notifications (for example Groundwire), or it only rings while it is open.</p>
    </div>

    @if (! $service)
        <div class="card" style="max-width:640px">
            <h2>Voice server not configured</h2>
            <p>Phones and apps sign in to this phone system's voice server. Set <code>VOICE_DOMAIN</code> (and the other <code>VOICE_*</code> settings) in <code>.env</code>, as described in docs/VOICE-SERVER.md, then reload this page.</p>
        </div>
    @else
        <div class="card">
            <h2>Phones</h2>
            @error('allowed_ips', 'lock')<div class="flash bad">{{ $message }}</div>@enderror
            @error('mac', 'provisioning')<div class="flash bad">{{ $message }}</div>@enderror
            <p class="small muted">Service address: <code>{{ $service['domain'] }}</code>. To call a colleague from a phone or app, dial * then their number, e.g. <strong>*12</strong> for user-12.</p>

            @if ($phones->isEmpty())
                <p class="muted">No phones yet.</p>
            @else
                <table>
                    <thead><tr><th>Phone</th><th>Person</th><th>Username</th><th>Signs in from</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($phones as $p)
                        <tr>
                            <td>{{ $p->label }}</td>
                            <td>{{ $p->user?->name }}</td>
                            <td><code>{{ $p->sip_username }}</code></td>
                            <td>
                                <form method="POST" action="{{ route('admin.desk-phones.lock', $p) }}" class="rename">
                                    @csrf @method('PATCH')
                                    <input type="text" name="allowed_ips" value="{{ $p->allowed_ips ? $p->allowedIpsLabel() : '' }}" placeholder="Anywhere"
                                           style="width:13em" aria-label="Addresses {{ $p->label }} may sign in from">
                                    <label class="small" style="font-weight:normal;white-space:nowrap" title="Unticked, the phone may also sign in from any local network address (10.x, 172.16–31.x, 192.168.x)">
                                        <input type="checkbox" name="filter_lan" value="1" @checked($p->filter_lan)> Filter LAN addresses
                                    </label>
                                    <button class="btn ghost small" type="submit">Save</button>
                                </form>
                            </td>
                            <td class="row">
                                <form method="POST" action="{{ route('admin.desk-phones.reveal', $p) }}">
                                    @csrf
                                    <button class="btn ghost" type="submit">Show settings</button>
                                </form>
                                <form method="POST" action="{{ route('admin.desk-phones.destroy', $p) }}" data-confirm="Remove {{ $p->label }}? It will stop working immediately.">
                                    @csrf @method('DELETE')
                                    <button class="btn ghost" type="submit">Remove</button>
                                </form>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="5" class="small" style="border-top:0;padding-top:0">
                                <div class="row" style="flex-wrap:wrap;gap:8px">
                                    <form method="POST" action="{{ route('admin.desk-phones.provisioning', $p) }}" class="row">
                                        @csrf @method('PATCH')
                                        <span class="muted">Automatic set-up:</span>
                                        <select name="brand" aria-label="Make of {{ $p->label }}">
                                            <option value="">Off (enter settings by hand)</option>
                                            @foreach (\App\Models\DeskPhone::BRANDS as $key => $name)
                                                <option value="{{ $key }}" @selected($p->brand === $key)>{{ $name }}</option>
                                            @endforeach
                                        </select>
                                        <input type="text" name="mac" value="{{ $p->mac ? $p->macLabel() : '' }}" placeholder="MAC address" maxlength="20"
                                               style="width:11em" aria-label="MAC address of {{ $p->label }}">
                                        <button class="btn ghost small" type="submit">Save</button>
                                    </form>
                                    @if ($p->provisioned())
                                        <span class="muted">
                                            @if ($p->provisioned_at)
                                                Last fetched its settings {{ $p->provisioned_at->timezone('Europe/London')->format('j M Y, H:i') }} from {{ $p->provisioned_ip }}{{ $p->provisioned_agent ? ' ('.\Illuminate\Support\Str::limit($p->provisioned_agent, 60).')' : '' }}.
                                            @else
                                                Hasn't fetched its settings yet.
                                            @endif
                                        </span>
                                        <form method="POST" action="{{ route('admin.desk-phones.admin-password', $p) }}">
                                            @csrf <button class="btn ghost small" type="submit">Show admin password</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.desk-phones.admin-password.new', $p) }}" data-confirm="Make a new admin password for {{ $p->label }}'s web page? The old one stops working once the phone fetches its settings.">
                                            @csrf <button class="btn ghost small" type="submit">New admin password</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.desk-phones.send-settings', $p) }}">
                                            @csrf <button class="btn ghost small" type="submit">Send settings now</button>
                                        </form>
                                    @endif
                                </div>
                                @php($watched = $p->blfColleagues())
                                <details style="margin-top:8px" @if ($errors->keys->any() && old('phone') == $p->id) open @endif>
                                    <summary>Busy lamp keys ({{ $watched->count() ?: 'none' }}){{ $watched->isNotEmpty() ? ': '.$watched->pluck('name')->implode(', ') : '' }}</summary>
                                    <form method="POST" action="{{ route('admin.desk-phones.keys', $p) }}" style="margin-top:8px">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="phone" value="{{ $p->id }}">
                                        @error('blf_keys', 'keys')<div class="error">{{ $message }}</div>@enderror
                                        <p class="small muted" style="margin:0 0 6px">Each colleague chosen gets a key that lights when they're on a call and flashes while
                                            their phone rings. Press it to call them, or during a call to transfer the call to them. Key 1 stays this phone's own line;
                                            the rest follow in extension order (up to {{ \App\Models\DeskPhone::MAX_BLF_KEYS }}).</p>
                                        <div class="row" style="flex-wrap:wrap;gap:4px 14px">
                                            @foreach ($users->where('id', '!=', $p->user_id)->sortBy(fn ($u) => [$u->extension === null, $u->extension, $u->name]) as $u)
                                                <label class="small" style="font-weight:normal">
                                                    <input type="checkbox" name="blf_keys[]" value="{{ $u->id }}" @checked($watched->contains('id', $u->id))>
                                                    {{ $u->name }}@if ($u->extension) <span class="muted">{{ $u->extension }}</span>@endif
                                                </label>
                                            @endforeach
                                        </div>
                                        <button class="btn ghost small" type="submit" style="margin-top:6px">Save keys</button>
                                        @if ($p->provisioned())
                                            <span class="small muted">The phone is sent its new keys straight away.</span>
                                        @endif
                                    </form>
                                    @if (! $p->provisioned() && $watched->isNotEmpty())
                                        <p class="small" style="margin:8px 0 4px">This phone is set up by hand: on its web page, set each key's type to <strong>BLF</strong>, account 1, with these values.</p>
                                        <table class="copy">
                                            @foreach ($watched as $u)
                                                <tr><td>{{ trim($u->name.' '.$u->extension) }}</td><td><code>{{ $u->blfId() }}</code></td>
                                                    <td><button class="btn ghost small" type="button" data-copy="{{ $u->blfId() }}" aria-label="Copy the key value for {{ $u->name }}">Copy</button></td></tr>
                                            @endforeach
                                        </table>
                                    @endif
                                </details>
                                @if ($revealAdmin === $p->id && $p->admin_password)
                                    <div class="flash warn" style="margin:8px 0 0">
                                        Admin login for {{ $p->label }}'s web page (recorded in the phone log). It takes effect when the phone next fetches its settings, and is also the password for the phone's other logins (Yealink <code>user</code> and <code>var</code>, Grandstream <code>user</code>, Fanvil <code>guest</code>):
                                        <table class="copy" style="margin-top:6px">
                                            <tr><td>User name</td><td><code>admin</code></td><td></td></tr>
                                            <tr><td>Password</td><td><code>{{ $p->admin_password }}</code></td>
                                                <td><button class="btn ghost small" type="button" data-copy="{{ $p->admin_password }}">Copy</button></td></tr>
                                        </table>
                                    </div>
                                @endif
                            </td>
                        </tr>
                        @if ($reveal === $p->id)
                            <tr>
                                <td colspan="5">
                                    <div class="flash warn" style="margin:0">
                                        <strong>Settings for {{ $p->label }}</strong> — enter these on the handset's account page. Keep the password private.
                                        <table class="copy" style="margin-top:8px">
                                            @foreach (['SIP server / registrar' => $service['domain'], 'Transport' => 'TLS (port 5061)', 'Media encryption' => 'SRTP (compulsory)',
                                                'Username' => $p->sip_username, 'Auth ID' => $p->sip_username, 'Password' => $p->sip_password] as $name => $value)
                                                <tr><td>{{ $name }}</td><td><code>{{ $value }}</code></td>
                                                    <td>@unless (in_array($name, ['Transport', 'Media encryption'], true))<button class="btn ghost small" type="button" data-copy="{{ $value }}" aria-label="Copy {{ $name }}">Copy</button>@endunless</td></tr>
                                            @endforeach
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.desk-phones.store') }}" class="card" style="max-width:640px">
            @csrf
            <h2>Add a phone</h2>
            <div class="grid">
                <div class="field">
                    <label for="user_id">Person</label>
                    <select id="user_id" name="user_id">
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}" @selected(old('user_id') == $u->id)>{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="label">Name for the phone</label>
                    <input id="label" type="text" name="label" value="{{ old('label') }}" placeholder="Reception desk" required>
                    @error('label')<div class="error">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="field">
                <label for="allowed_ips">Only sign in from (optional)</label>
                <input id="allowed_ips" type="text" name="allowed_ips" value="{{ old('allowed_ips') }}" placeholder="Anywhere, or e.g. 203.0.113.7 or office.example.com">
                @error('allowed_ips')<div class="error">{{ $message }}</div>@enderror
                <div class="small muted">Lock a desk phone to your office's public IP address, or its host name if the office has a dynamic address
                    (up to {{ \App\Models\DeskPhone::MAX_ALLOWED_IPS }} addresses, ranges or names, comma-separated). Names are checked again every 5 minutes.
                    From anywhere else it can't sign in or make calls, even with the right password. Leave blank for mobile apps and phones that move about.</div>
                <label class="small" style="font-weight:normal;display:block;margin-top:6px">
                    <input type="checkbox" name="filter_lan" value="1" @checked(old('filter_lan'))> Filter LAN addresses
                </label>
                <div class="small muted">Unticked, a locked phone may also sign in from local network addresses (10.x, 172.16–31.x, 192.168.x), which can only come
                    from the server's own network: useful when phones are in the same building as the server. Tick it to allow only the addresses above.</div>
            </div>
            <div class="grid">
                <div class="field">
                    <label for="brand">Set up automatically (optional)</label>
                    <select id="brand" name="brand">
                        <option value="">No, I'll enter the settings by hand</option>
                        @foreach (\App\Models\DeskPhone::BRANDS as $key => $name)
                            <option value="{{ $key }}" @selected(old('brand') === $key)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="mac">MAC address</label>
                    <input id="mac" type="text" name="mac" value="{{ old('mac') }}" placeholder="80:5E:C0:12:34:56" maxlength="20">
                    @error('mac')<div class="error">{{ $message }}</div>@enderror
                    <div class="small muted">On the label on the back of the phone.</div>
                </div>
            </div>
            <button class="btn" type="submit">Add phone</button>
        </form>

        @if ($provisioning)
            <div class="card" style="max-width:820px">
                <h2>Automatic set-up</h2>
                <p class="small">Phones set up automatically fetch their account, a new admin password for their web page, UK time,
                    <strong>*00</strong> for voicemail and their busy lamp keys from this address, and check it again every day. Give each phone its make and MAC address above,
                    then enter this on the phone once (or set it as DHCP option 66 on the office router):</p>
                @php($dhcp = preg_replace('#^https://#', 'https://'.$provisioning['username'].':'.$provisioning['password'].'@', $provisioning['url']))
                <table class="copy">
                    @foreach (['Server URL' => $provisioning['url'], 'User name' => $provisioning['username'], 'Password' => $provisioning['password'], 'DHCP option 66' => $dhcp] as $name => $value)
                        <tr>
                            <td>{{ $name }}</td>
                            <td><code>{{ $value }}</code></td>
                            <td><button class="btn ghost small" type="button" data-copy="{{ $value }}" aria-label="Copy {{ $name }}">Copy</button></td>
                        </tr>
                    @endforeach
                </table>
                <p class="small muted">Grandstream's Config Server Path takes the address without <code>https://</code>:
                    <code>{{ preg_replace('#^https://#', '', $provisioning['url']) }}</code>
                    <button class="btn ghost small" type="button" data-copy="{{ preg_replace('#^https://#', '', $provisioning['url']) }}">Copy</button></p>
                <ul class="small" style="margin-top:10px">
                    <li><strong>Yealink:</strong> web page → Settings → Auto Provision: Server URL, User Name, Password. Then Auto Provision Now.</li>
                    <li><strong>Grandstream (GRP):</strong> web page → Maintenance → Upgrade and Provisioning: Config Upgrade Via HTTPS, Config Server Path
                        (the address without <code>https://</code>), HTTP/HTTPS User Name and Password. Then Provision.</li>
                    <li><strong>Fanvil (X series):</strong> web page → System → Auto Provision: Server URL, User Name, Password. Then Apply.</li>
                </ul>
                <p class="small muted">Keep the password private: with it and a phone's MAC address, someone could fetch that phone's SIP password.
                    A phone locked to an address only gets its settings from there. Every download and refusal is in the phone log below.</p>
                <form method="POST" action="{{ route('admin.desk-phones.provisioning-login') }}"
                      data-confirm="Make a new provisioning address and password? Phones keep working, but can't fetch changes until each is given the new ones.">
                    @csrf <button class="btn ghost small" type="submit">Make a new address and password</button>
                </form>
            </div>
        @endif

        <div class="card">
            <h2>Phone log</h2>
            <p class="small muted">Every settings download and refusal, and who showed or changed phone passwords. The last 100 are shown.</p>
            @if ($events->isEmpty())
                <p class="muted">Nothing yet.</p>
            @else
                <table>
                    <thead><tr><th>When</th><th>Phone</th><th>What</th><th>Who</th><th>From</th><th>Details</th></tr></thead>
                    <tbody>
                    @foreach ($events as $e)
                        <tr>
                            <td class="small">{{ $e->created_at->timezone('Europe/London')->format('j M Y, H:i') }}</td>
                            <td>{{ $e->phone_label ?? '—' }}</td>
                            <td>{{ $e->label() }}</td>
                            <td>{{ $e->user?->name ?? ($e->user_id ? 'Removed user' : 'The phone') }}</td>
                            <td class="small"><code>{{ $e->ip }}</code></td>
                            <td class="small muted">{{ $e->detail }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif
@endsection
