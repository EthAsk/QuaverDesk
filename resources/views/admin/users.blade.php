@extends('layouts.app')
@section('title', 'Users')
@section('content')
    <div class="page-head">
        <h1>Users</h1>
        <p class="muted">{{ $seated }} of {{ $maxUsers }} users allowed on your plan. Forward-only contacts are free and don't count; desk-phone-only users do. Colleagues call each other by extension: 3 to 5 digits, starting with 2 to 8.</p>
        @error('extension', 'extension')<div class="flash bad">{{ $message }}</div>@enderror
        @error('mobile', 'mobile')<div class="flash bad">{{ $message }}</div>@enderror
        @error('type', 'type')<div class="flash bad">{{ $message }}</div>@enderror
    </div>

    @if (session('invited'))
        <div class="flash ok">
            <strong>{{ session('invited')['email'] }}</strong> was added and emailed a link to set their password (valid for {{ \App\Support\Invites::DAYS }} days).
            If the email doesn't arrive, send them this link another way. It won't be shown again:
            <code class="codes" style="margin-top:8px;white-space:normal;word-break:break-all">{{ session('invited')['link'] }}</code>
            <button class="btn ghost small" type="button" data-copy="{{ session('invited')['link'] }}" style="margin-top:6px">Copy link</button>
        </div>
    @endif

    <div class="card">
        <table>
            <thead><tr><th>Name</th><th>Email</th><th>Extension</th><th>Role</th><th>2FA</th><th>Calls</th><th></th></tr></thead>
            <tbody>
            @foreach ($users as $u)
                <tr>
                    <td>{{ $u->name }}</td>
                    <td>{{ $u->email }}</td>
                    <td>
                        <form method="POST" action="{{ route('admin.users.extension', $u) }}" class="rename">
                            @csrf @method('PATCH')
                            <input type="text" name="extension" value="{{ $u->extension }}" inputmode="numeric" maxlength="5" size="5"
                                   style="width:5.5em" aria-label="Extension for {{ $u->name }}" required>
                            <button class="btn ghost small" type="submit">Save</button>
                        </form>
                    </td>
                    <td>
                        @if ($u->isForwardOnly())
                            <span class="pill">Forward only</span>
                        @elseif ($u->isPhoneOnly())
                            <span class="pill">Desk phones only</span>
                        @elseif ($u->isOwner())
                            <span class="pill">Owner</span>
                        @else
                            @if ($u->isSuspended())<span class="pill off">Switched off by your directory</span>@endif
                            <form method="POST" action="{{ route('admin.users.update', $u) }}" class="row">
                                @csrf @method('PATCH')
                                <select name="role" aria-label="Role for {{ $u->name }}" data-autosubmit>
                                    <option value="user" @selected($u->role === 'user')>User</option>
                                    <option value="admin" @selected($u->role === 'admin')>Admin</option>
                                </select>
                            </form>
                        @endif
                    </td>
                    <td>@unless ($u->canSignIn()) <span class="muted">No sign-in</span> @else {!! $u->hasTwoFactorEnabled() ? '<span class="pill">On</span>' : '<span class="pill off">Off</span>' !!} @endunless</td>
                    <td>
                        @unless ($u->isForwardOnly())
                            <form method="POST" action="{{ route('admin.users.calling', $u) }}" class="row" style="margin-bottom:6px">
                                @csrf @method('PATCH')
                                <select name="internal_only" aria-label="Who {{ $u->name }} can call" data-autosubmit>
                                    <option value="0" @selected(! $u->isInternalOnly())>Can call anyone</option>
                                    <option value="1" @selected($u->isInternalOnly())>Colleagues only</option>
                                </select>
                            </form>
                        @endunless
                        @if ($u->mailbox_id)
                            <form method="POST" action="{{ route('admin.users.voicemail', $u) }}" class="row">
                                @csrf @method('PATCH')
                                <select name="voicemail" aria-label="Voicemail for {{ $u->name }}" data-autosubmit>
                                    <option value="1" @selected($u->hasVoicemail())>Voicemail on</option>
                                    <option value="0" @selected(! $u->hasVoicemail())>Voicemail off</option>
                                </select>
                            </form>
                        @else
                            <span class="small muted">No voicemail box</span>
                        @endif
                    </td>
                    <td>
                        @if ($u->isForwardOnly())
                            <form method="POST" action="{{ route('admin.users.mobile', $u) }}" class="rename" style="margin-bottom:6px">
                                @csrf @method('PATCH')
                                <input type="tel" name="mobile" value="{{ $u->mobile ? '0'.substr($u->mobile, 3) : '' }}" placeholder="No mobile: voicemail"
                                       style="width:11em" aria-label="Mobile for {{ $u->name }}">
                                <button class="btn ghost small" type="submit">Save</button>
                            </form>
                            @if ($u->mobile && ! $u->mobile_verified_at)
                                <form method="POST" action="{{ route('admin.users.confirm-mobile', $u) }}" class="row" style="margin-bottom:6px">
                                    @csrf
                                    <span class="small error">Not confirmed: calls go to voicemail.</span>
                                    <button class="btn ghost small" type="submit">Call to confirm</button>
                                </form>
                            @endif
                        @endif
                        <div class="row">
                            @unless ($u->is(auth()->user()) || ! $u->canSignIn())
                                <form method="POST" action="{{ route('admin.users.invite', $u) }}" data-confirm="Email {{ $u->name }} a link to set a new password?">
                                    @csrf
                                    <button class="btn ghost small" type="submit">Email sign-in link</button>
                                </form>
                            @endunless
                            @unless ($u->isOwner() || $u->is(auth()->user()))
                                @if ($u->isSuspended())
                                    <form method="POST" action="{{ route('admin.users.reactivate', $u) }}" data-confirm="Let {{ $u->name }} sign in again?">
                                        @csrf @method('PATCH')
                                        <button class="btn ghost small" type="submit">Reactivate</button>
                                    </form>
                                @elseif (! $u->canSignIn())
                                    <form method="POST" action="{{ route('admin.users.type', $u) }}" class="row"
                                          data-confirm="Give {{ $u->name }} a sign-in?{{ $u->isForwardOnly() ? " They'll count as one of your plan's users." : '' }} They'll be emailed a link to set a password.">
                                        @csrf @method('PATCH')
                                        <select name="type" aria-label="Role for {{ $u->name }} with a sign-in">
                                            <option value="user">User</option>
                                            <option value="admin">Admin</option>
                                        </select>
                                        <button class="btn ghost small" type="submit">Give a sign-in</button>
                                    </form>
                                @endif
                                @unless ($u->isPhoneOnly())
                                    <form method="POST" action="{{ route('admin.users.type', $u) }}"
                                          data-confirm="Make {{ $u->name }} desk phones only? {{ $u->isForwardOnly() ? "They'll count as one of your plan's users. Add their phone under Phones and apps." : "They'll be signed out everywhere and lose their softphone and passkeys. Their desk phones, ring groups and voicemail stay." }}">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="type" value="phone_only">
                                        <button class="btn ghost small" type="submit">Make desk phones only</button>
                                    </form>
                                @endunless
                                @unless ($u->isForwardOnly())
                                    <form method="POST" action="{{ route('admin.users.type', $u) }}"
                                          data-confirm="Make {{ $u->name }} forward-only? They'll be signed out everywhere, their desk phones and passkeys removed, and they'll leave their ring groups. Calls will ring their confirmed mobile or go to voicemail.">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="type" value="forward_only">
                                        <button class="btn ghost small" type="submit">Make forward-only</button>
                                    </form>
                                @endunless
                                <form method="POST" action="{{ route('admin.users.destroy', $u) }}" data-confirm="Remove {{ $u->name }}? Their own voicemail box and its messages are deleted too.">
                                    @csrf @method('DELETE')
                                    <button class="btn ghost small" type="submit">Remove</button>
                                </form>
                            @endunless
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Add a user</h2>
        @php($full = $seated >= $maxUsers)
        @if ($full && ! $bandOffer)
            <p class="muted">You've reached your plan's limit of {{ $maxUsers }} users.
                @if ($plan === \App\Domain\Licensing\Plan::Free) Upgrade to Plus for more. @endif</p>
        @else
            <form method="POST" action="{{ route('admin.users.store') }}" style="max-width:480px">
                @csrf
                <div class="field">
                    <label for="name">Name</label>
                    <input id="name" type="text" name="name" value="{{ old('name') }}" required>
                    @error('name')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required>
                    @error('email')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="field" style="max-width:200px">
                    <label for="extension">Extension</label>
                    <input id="extension" type="text" name="extension" value="{{ old('extension') }}" inputmode="numeric" maxlength="5" placeholder="{{ \App\Models\User::nextExtension() }}">
                    @error('extension')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="role">Role</label>
                    <select id="role" name="role">
                        <option value="user" @selected(old('role') === 'user')>User</option>
                        <option value="admin" @selected(old('role') === 'admin')>Admin</option>
                        <option value="phone_only" @selected(old('role') === 'phone_only')>Desk phones only (no sign-in)</option>
                    </select>
                    <div class="small muted">Desk phones only is for a reception, lobby or hall phone: no sign-in or softphone, and no email is sent. Their calls ring the desk phones you add for them.</div>
                </div>
                <label class="check">
                    <input type="checkbox" name="internal_only" value="1" @checked(old('internal_only'))>
                    <span>Colleagues only: can call extensions and 999, but no outside numbers.</span>
                </label>
                @if ($full)
                    <label class="check">
                        <input type="checkbox" name="upgrade" value="1" required>
                        <span>Move up to {{ strtolower($bandOffer['label']) }} users: {{ $plan->label() }} at £{{ $bandOffer['monthly'] }} a month.
                            Your card is charged the difference for the rest of this billing period now.</span>
                    </label>
                @endif
                <button class="btn" type="submit">{{ $full ? 'Upgrade and add user' : 'Add user' }}</button>
            </form>
        @endif
    </div>

    <div class="card">
        <h2>Add a forward-only contact</h2>
        <p class="muted">Free, with no limit. For people who don't need the phone system day to day: they have an extension but no sign-in,
            softphone or desk phone, so there's nothing to log in to or lose. Their calls ring their mobile. If they have no mobile,
            or don't answer, callers leave a voicemail that's emailed to them with the audio attached.</p>
        <form method="POST" action="{{ route('admin.users.store') }}" style="max-width:480px">
            @csrf
            <input type="hidden" name="role" value="forward_only">
            <div class="field">
                <label for="fo-name">Name</label>
                <input id="fo-name" type="text" name="name" value="{{ old('role') === 'forward_only' ? old('name') : '' }}" required>
            </div>
            <div class="field">
                <label for="fo-email">Email for voicemail</label>
                <input id="fo-email" type="email" name="email" value="{{ old('role') === 'forward_only' ? old('email') : '' }}" required>
            </div>
            <div class="field">
                <label for="fo-mobile">Mobile (optional)</label>
                <input id="fo-mobile" type="tel" name="mobile" value="{{ old('role') === 'forward_only' ? old('mobile') : '' }}" placeholder="07700 900123">
                @error('mobile')<div class="error">{{ $message }}</div>@enderror
                <div class="small muted">We call it once and they press 1 to confirm it's theirs. Until then, calls go to voicemail.</div>
            </div>
            <div class="field" style="max-width:200px">
                <label for="fo-extension">Extension</label>
                <input id="fo-extension" type="text" name="extension" inputmode="numeric" maxlength="5" placeholder="{{ \App\Models\User::nextExtension() }}">
            </div>
            <button class="btn" type="submit">Add contact</button>
        </form>
    </div>
@endsection
