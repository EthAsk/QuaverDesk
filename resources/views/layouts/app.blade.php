<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    @include('partials.app-head')
    {{-- Turbo swaps pages in place so the docked phone (and any call) carries on. --}}
    <meta name="turbo-prefetch" content="false">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body @if (session('phone_autostart')) data-phone-autostart @endif>
<div class="shell">
    <nav class="side" aria-label="Main">
        <div class="brand">{{ config('app.name') }}<span>.</span></div>
        <a href="{{ route('dashboard') }}" @class(['on' => request()->routeIs('dashboard')])>Dashboard</a>
        <a href="{{ route('softphone') }}" @class(['on' => request()->routeIs('softphone')])>Phone</a>
        <a href="{{ route('calls') }}" @class(['on' => request()->routeIs('calls')])>Call history</a>
        <a href="{{ route('recordings') }}" @class(['on' => request()->routeIs('recordings*')])>Voicemail &amp; recordings</a>
        <a href="{{ route('security') }}" @class(['on' => request()->routeIs('security')])>Security</a>

        @if (auth()->user()->isAdmin())
            <div class="group">Admin</div>
            <a href="{{ route('admin.users') }}" @class(['on' => request()->routeIs('admin.users')])>Users</a>
            <a href="{{ route('admin.numbers') }}" @class(['on' => request()->routeIs('admin.numbers*')])>Phone numbers</a>
            <a href="{{ route('admin.ring-groups') }}" @class(['on' => request()->routeIs('admin.ring-groups*')])>Ring groups</a>
            <a href="{{ route('admin.ivr') }}" @class(['on' => request()->routeIs('admin.ivr*')])>IVR menus</a>
            <a href="{{ route('admin.mailboxes') }}" @class(['on' => request()->routeIs('admin.mailboxes*')])>Voicemail boxes</a>
            <a href="{{ route('admin.sounds') }}" @class(['on' => request()->routeIs('admin.sounds')])>Sounds &amp; hold music</a>
            <a href="{{ route('admin.voice') }}" @class(['on' => request()->routeIs('admin.voice')])>Voice</a>
            <a href="{{ route('admin.recording-settings') }}" @class(['on' => request()->routeIs('admin.recording-settings')])>Recording @unlessfeature('recording')<span class="lock">Plus</span>@endfeature</a>
            <a href="{{ route('admin.ai') }}" @class(['on' => request()->routeIs('admin.ai')])>AI provider @unlessfeature('ai_transcripts')<span class="lock">Plus</span>@endfeature</a>
            <a href="{{ route('admin.desk-phones') }}" @class(['on' => request()->routeIs('admin.desk-phones*')])>Phones and apps @unlessfeature('desk_phones')<span class="lock">Plus</span>@endfeature</a>
            <a href="{{ route('admin.trunks') }}" @class(['on' => request()->routeIs('admin.trunks*')])>SIP trunks</a>
            <a href="{{ route('admin.calling-rules') }}" @class(['on' => request()->routeIs('admin.calling-rules')])>Calling rules</a>
            @if (auth()->user()->isOwner())
                <a href="{{ route('admin.licence') }}" @class(['on' => request()->routeIs('admin.licence')])>{{ config('tenancy.enabled') ? 'Plan' : 'Licence' }}</a>
            @endif
            <a href="{{ route('setup.acknowledge') }}" @class(['on' => request()->routeIs('setup.*')])>Setup &amp; terms</a>
        @endif

        <div class="spacer"></div>
        <button type="button" class="phone-pill" data-phone-open>
            <span class="status-dot" data-phone-dot data-state="closed"></span>
            <span>Phone: <span data-phone-text>…</span></span>
        </button>
        {{-- A full page load, so the phone signs out too. --}}
        <form method="POST" action="{{ route('logout') }}" data-turbo="false">
            @csrf
            <button type="submit">Sign out {{ auth()->user()->name }}</button>
        </form>
    </nav>

    <main>
        @php
            $statusText = [
                'two-factor-authentication-enabled' => 'Scan the QR code below to finish turning on two-factor.',
                'two-factor-authentication-confirmed' => 'Two-factor authentication is on.',
                'two-factor-authentication-disabled' => 'Two-factor authentication is off.',
                'recovery-codes-generated' => 'New recovery codes created.',
                'password-updated' => 'Your password has been updated.',
                'passkey-registered' => 'Passkey added. You can now sign in with it.',
                'passkey-deleted' => 'Passkey removed.',
            ][session('status')] ?? session('status');
        @endphp
        @if ($statusText)
            <div class="flash ok" role="status">{{ $statusText }}</div>
        @endif
        @isset($supportVisit)
            <div class="flash warn" role="status">
                <strong>Support visit:</strong> {{ $supportVisit->operator?->name ?? 'QuaverDesk support' }} is signed in as {{ auth()->user()->name }}
                until {{ $supportVisit->ends_at->timezone('Europe/London')->format('H:i') }}. Reason: {{ $supportVisit->reason }}
                <form method="POST" action="{{ route('support.end') }}" data-turbo="false" style="display:inline">
                    @csrf <button class="btn ghost small" type="submit">End support visit</button>
                </form>
            </div>
        @endisset
        @if (session('upgrade'))
            <div class="flash warn" role="status">{{ session('upgrade') }} is available on the Plus and Pro plans.</div>
        @endif
        @if (session('limit'))
            <div class="flash warn" role="status">{{ session('limit') }}</div>
        @endif

        @yield('content')

        <footer class="legal">
            Source-available software provided "as is", without warranty, under the Elastic License 2.0.
            It is not a substitute for a landline or mobile for emergency calls.
        </footer>
    </main>
</div>
@if ($phoneDock)
    @include('partials.phone-dock')
@endif
@stack('scripts')
</body>
</html>
