@extends('layouts.app')
@section('title', 'Users')
@section('content')
    <div class="page-head">
        <h1>Users</h1>
        <p class="muted">{{ $users->count() }} of {{ $maxUsers }} users allowed on your plan. Colleagues call each other by extension: 3 to 5 digits, starting with 2 to 8.</p>
        @error('extension', 'extension')<div class="flash bad">{{ $message }}</div>@enderror
    </div>

    @if (session('invited'))
        <div class="flash ok">
            <strong>{{ session('invited')['email'] }}</strong> was added and emailed a link to set their password (valid for {{ \App\Support\Invites::DAYS }} days).
            If the email doesn't arrive, send them this link another way. It won't be shown again:
            <code class="codes" style="margin-top:8px;white-space:normal;word-break:break-all">{{ session('invited')['link'] }}</code>
        </div>
    @endif

    <div class="card">
        <table>
            <thead><tr><th>Name</th><th>Email</th><th>Extension</th><th>Role</th><th>2FA</th><th></th></tr></thead>
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
                        @if ($u->isOwner())
                            <span class="pill">Owner</span>
                        @else
                            <form method="POST" action="{{ route('admin.users.update', $u) }}" class="row">
                                @csrf @method('PATCH')
                                <select name="role" aria-label="Role for {{ $u->name }}" data-autosubmit>
                                    <option value="user" @selected($u->role === 'user')>User</option>
                                    <option value="admin" @selected($u->role === 'admin')>Admin</option>
                                </select>
                            </form>
                        @endif
                    </td>
                    <td>{!! $u->hasTwoFactorEnabled() ? '<span class="pill">On</span>' : '<span class="pill off">Off</span>' !!}</td>
                    <td>
                        <div class="row">
                            @unless ($u->is(auth()->user()))
                                <form method="POST" action="{{ route('admin.users.invite', $u) }}" data-confirm="Email {{ $u->name }} a link to set a new password?">
                                    @csrf
                                    <button class="btn ghost small" type="submit">Email sign-in link</button>
                                </form>
                            @endunless
                            @unless ($u->isOwner() || $u->is(auth()->user()))
                                <form method="POST" action="{{ route('admin.users.destroy', $u) }}" data-confirm="Remove {{ $u->name }}?">
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
        @php($full = $users->count() >= $maxUsers)
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
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
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
@endsection
