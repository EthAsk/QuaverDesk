@extends('layouts.app')
@section('title', 'Single sign-on')
@section('content')
    <div class="page-head">
        <h1>Single sign-on</h1>
        <p class="muted">Let people sign in with their Microsoft or Google work account. They must already be added under Users: signing in this way never creates an account, and passwords keep working. Admins still use two-factor, as the provider doesn't replace it.</p>
    </div>

    @foreach ([
        'microsoft' => ['Microsoft Entra ID', $microsoft, 'Directory (tenant) ID', '11111111-2222-3333-4444-555555555555',
            'In the Entra admin centre: App registrations → New registration (single tenant), add a Web redirect URI, then Certificates & secrets → New client secret. The client id and directory id are on the app\'s Overview page.'],
        'google' => ['Google Workspace', $google, 'Workspace domain', 'example.co.uk',
            'In Google Cloud console: APIs & Services → Credentials → Create OAuth client ID (Web application), with the redirect URI below. Only accounts in this Workspace domain can sign in.'],
    ] as $key => [$name, $c, $restrictLabel, $placeholder, $how])
        <form method="POST" action="{{ route('admin.sso.update', $key) }}" class="card">
            @csrf @method('PUT')
            <input type="hidden" name="_provider" value="{{ $key }}">
            <h2>{{ $name }}</h2>
            <p class="small muted">{{ $how }}</p>
            <div class="field">
                <label>Redirect URI</label>
                <code>{{ route('sso.callback', $key) }}</code>
            </div>
            <div class="field">
                <label for="{{ $key }}_id">Client ID</label>
                <input id="{{ $key }}_id" type="text" name="client_id" value="{{ old('client_id', $c['client_id']) }}" autocomplete="off">
            </div>
            <div class="field">
                <label for="{{ $key }}_secret">Client secret {!! $c['client_secret'] ? '<span class="pill">Saved</span>' : '' !!}</label>
                <input id="{{ $key }}_secret" type="password" name="client_secret" autocomplete="off" placeholder="{{ $c['client_secret'] ? 'Leave blank to keep the saved secret' : '' }}">
            </div>
            <div class="field">
                <label for="{{ $key }}_restrict">{{ $restrictLabel }}</label>
                <input id="{{ $key }}_restrict" type="text" name="restrict" value="{{ old('restrict', $c['restrict']) }}" placeholder="{{ $placeholder }}">
                @if (old('_provider') === $key)@error('restrict')<div class="error">{{ $message }}</div>@enderror @endif
            </div>
            <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $c['enabled']))> Offer "Sign in with {{ \App\Sso\SsoSettings::PROVIDERS[$key] }}" on the sign-in page</label>
            <button class="btn" type="submit">Save {{ $name }}</button>
        </form>
    @endforeach

    <div class="card">
        <h2>Directory sync (SCIM)</h2>
        <p class="small muted">Keep people in step with your directory: Microsoft Entra, Okta and other identity providers can add people here when they're given access, update their name and email, and switch them off when they leave. Nothing is ever deleted: someone switched off can't sign in, and an admin can reactivate or remove them under Users. New people are always ordinary users and count towards your plan's users; when it's full the directory is told so. The owner is never changed from the directory.</p>
        <div class="field"><label>Tenant URL</label> <code>{{ url('/scim/v2') }}</code></div>
        @if (session('scim_token'))
            <div class="flash"><strong>Sync token:</strong> <code>{{ session('scim_token') }}</code><br><span class="small">Paste it into your directory as the secret token. It is not shown again.</span></div>
        @endif
        <div class="row">
            <form method="POST" action="{{ route('admin.sso.token') }}" @if ($scimActive) data-confirm="Make a new token? The current one stops working straight away." @endif>
                @csrf <button class="btn" type="submit">{{ $scimActive ? 'Make a new token' : 'Turn on directory sync' }}</button>
            </form>
            @if ($scimActive)
                <form method="POST" action="{{ route('admin.sso.token.revoke') }}">@csrf @method('DELETE') <button class="btn ghost" type="submit">Turn off</button></form>
                <span class="small muted">On since {{ \Illuminate\Support\Carbon::parse($scimSince)->format('j M Y') }}</span>
            @endif
        </div>
    </div>
@endsection
