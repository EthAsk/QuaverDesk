@extends('layouts.app')
@php($trunk ??= null)
@section('title', $trunk ? 'Edit '.$trunk->name : 'Connect a SIP trunk')
@section('content')
    <div class="page-head">
        <h1>{{ $trunk ? 'Edit '.$trunk->name : 'Connect your SIP trunk' }}</h1>
        <p class="muted"><a href="{{ route('admin.trunks') }}">All trunks</a></p>
    </div>

    @unless ($trunk)
    <div class="card">
        <h2>Before you start</h2>
        <p>Ask your provider for:</p>
        <ol>
            <li><strong>The public IP addresses their equipment sends calls from</strong> (sometimes called SBC or signalling IPs).</li>
            <li><strong>The SIP address to send your outgoing calls to</strong> (for example <code>sip:sbc.provider.example</code>).</li>
            <li>If they authenticate by password rather than by your server's IP address: <strong>the SIP username and password</strong>.</li>
        </ol>
        <p class="small muted">Calls run on this phone system's own voice server, so you only pay your provider's call charges. After you save, this page shows exactly what to send them.</p>
    </div>
    @endunless

    <form method="POST" action="{{ $trunk ? route('admin.trunks.update', $trunk) : route('admin.trunks.store') }}" class="card" style="max-width:720px">
        @csrf
        @if ($trunk) @method('PUT') @endif
        <div class="field">
            <label for="provider">Provider</label>
            <select id="provider" name="provider">
                @foreach ($providers as $key => $p)
                    <option value="{{ $key }}" @selected(old('provider', $trunk?->provider) === $key)>{{ $p['name'] }}</option>
                @endforeach
            </select>
            @error('provider')<div class="error">{{ $message }}</div>@enderror
        </div>

        <div class="field">
            <label for="name">Name for this trunk</label>
            <input id="name" type="text" name="name" value="{{ old('name', $trunk?->name) }}" placeholder="Head office lines" required>
            @error('name')<div class="error">{{ $message }}</div>@enderror
        </div>

        <div class="field">
            <label for="inbound_ips">Provider IP addresses (one per line)</label>
            <textarea id="inbound_ips" name="inbound_ips" rows="4" placeholder="203.0.113.10&#10;203.0.113.0/28" required>{{ old('inbound_ips', $trunk ? collect($trunk->inbound_ips)->map(fn ($i) => $i['ip'].'/'.$i['prefix'])->implode("\n") : '') }}</textarea>
            <div class="small muted">Only these addresses will be able to send calls to this phone system.</div>
            @error('inbound_ips')<div class="error">{{ $message }}</div>@enderror
        </div>

        <div class="field">
            <label for="origination_uris">Where to send your outgoing calls (one per line, first is preferred)</label>
            <textarea id="origination_uris" name="origination_uris" rows="3" placeholder="sip:sbc1.provider.example&#10;sip:sbc2.provider.example" required>{{ old('origination_uris', $trunk ? implode("\n", $trunk->origination_uris) : '') }}</textarea>
            @error('origination_uris')<div class="error">{{ $message }}</div>@enderror
        </div>

        <div class="field">
            <label for="auth_username">SIP username (optional)</label>
            <input id="auth_username" type="text" name="auth_username" value="{{ old('auth_username', $trunk?->auth_username) }}" autocomplete="off" style="max-width:360px">
            @error('auth_username')<div class="error">{{ $message }}</div>@enderror
        </div>

        <div class="field">
            <label for="auth_password">SIP password</label>
            <input id="auth_password" type="password" name="auth_password" autocomplete="new-password" style="max-width:360px">
            <div class="small muted">Leave both blank if your provider recognises your server by its IP address. Stored encrypted.@if ($trunk?->auth_password) Leave the password blank to keep the saved one.@endif</div>
            @error('auth_password')<div class="error">{{ $message }}</div>@enderror
        </div>

        <label class="check">
            <input type="checkbox" name="register" value="1" @checked(old('register', $trunk?->register))>
            <span>Register with the provider (needed if they send incoming calls to a registered login rather than to our address).</span>
        </label>
        @if (config('tenancy.enabled'))
            <p class="small muted">We recommend registering with your own SIP username and password. If your provider's addresses are also used by
                another customer of this service, incoming calls are only accepted when they arrive at your trunk's own registration.</p>
        @endif

        <button class="btn" type="submit">{{ $trunk ? 'Save changes' : 'Connect trunk' }}</button>
    </form>
@endsection
