@extends('layouts.app')
@section('title', 'Security')
@push('head')
    {{-- passkeys.js runs once per page load, so arrive here with a full load. --}}
    <meta name="turbo-visit-control" content="reload">
@endpush
@push('scripts')
    @vite(['resources/js/passkeys.js'])
@endpush
@section('content')
    <div class="page-head">
        <h1>Security</h1>
        <p class="muted">Two-factor authentication is required for owners and admins.</p>
    </div>

    <div class="card">
        <h2>Two-factor authentication</h2>

        @if (! $user->two_factor_secret)
            <p>Add a second step at sign-in using an authenticator app (for example Google Authenticator, Microsoft Authenticator or 1Password).</p>
            <form method="POST" action="{{ route('two-factor.enable') }}">
                @csrf
                <button class="btn" type="submit">Turn on two-factor</button>
            </form>

        @elseif (! $user->two_factor_confirmed_at)
            <p>Scan this code with your authenticator app, then enter the 6-digit code it shows.</p>
            <div class="qr">{!! $user->twoFactorQrCodeSvg() !!}</div>
            <form method="POST" action="{{ route('two-factor.confirm') }}" style="margin-top:16px;max-width:280px">
                @csrf
                <div class="field">
                    <label for="code">Code</label>
                    <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required>
                    @error('code', 'confirmTwoFactorAuthentication')<div class="error">{{ $message }}</div>@enderror
                </div>
                <button class="btn" type="submit">Confirm</button>
            </form>

        @else
            <p><span class="pill">On</span> Two-factor is protecting your account.</p>

            @if (session('status') === 'two-factor-authentication-confirmed' || session('status') === 'recovery-codes-generated')
                <p><strong>Save these recovery codes somewhere safe.</strong> Each works once if you lose your phone. They won't be shown again.</p>
                <code class="codes">{{ implode("\n", $user->recoveryCodes()) }}</code>
            @endif

            <div class="row" style="margin-top:16px">
                <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}">
                    @csrf
                    <button class="btn ghost" type="submit">New recovery codes</button>
                </form>
                <form method="POST" action="{{ route('two-factor.disable') }}">
                    @csrf @method('DELETE')
                    <button class="btn ghost" type="submit">Turn off</button>
                </form>
            </div>
        @endif
    </div>

    <div class="card">
        <h2>Passkeys</h2>
        @feature('passkeys')
            <p>Sign in with your fingerprint, face or device PIN instead of a password and code. Passkeys can't be phished or reused on other sites.</p>
        @else
            <p class="muted">Passkey sign-in is available on the Plus and Pro plans.</p>
        @endfeature

        @if ($user->passkeys->isNotEmpty())
            <table style="margin-bottom:16px">
                <thead><tr><th>Name</th><th>Added</th><th>Last used</th><th><span class="sr-only">Remove</span></th></tr></thead>
                <tbody>
                @foreach ($user->passkeys as $passkey)
                    <tr>
                        <td>{{ $passkey->name }}</td>
                        <td>{{ $passkey->created_at->format('j M Y') }}</td>
                        <td>{{ $passkey->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                        <td>
                            <form method="POST" action="{{ route('passkey.destroy', $passkey) }}" data-confirm="Remove the passkey “{{ $passkey->name }}”?">
                                @csrf @method('DELETE')
                                <button class="btn ghost small" type="submit">Remove</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        @feature('passkeys')
            <form id="passkey-add" style="max-width:420px">
                <div class="field">
                    <label for="passkey-name">Name this passkey</label>
                    <input id="passkey-name" type="text" maxlength="60" required placeholder="e.g. Work laptop, iPhone">
                </div>
                <div class="error" id="passkey-error" role="alert" hidden></div>
                <button class="btn" type="submit">Add a passkey</button>
            </form>
            <p class="small muted" id="passkey-unsupported" hidden>This browser can't create passkeys.</p>
        @endfeature
    </div>

    <div class="card">
        <h2>Change password</h2>
        <form method="POST" action="{{ route('user-password.update') }}" style="max-width:420px">
            @csrf @method('PUT')
            <div class="field">
                <label for="current_password">Current password</label>
                <input id="current_password" type="password" name="current_password" required autocomplete="current-password">
                @error('current_password', 'updatePassword')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="password">New password</label>
                <input id="password" type="password" name="password" required autocomplete="new-password">
                @error('password', 'updatePassword')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="password_confirmation">Confirm new password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
            </div>
            <button class="btn" type="submit">Update password</button>
        </form>
    </div>
@endsection
