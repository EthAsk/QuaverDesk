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
        @include('partials.side-nav')

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
        @if (auth()->user()->isAdmin() && ($update = app(\App\Support\UpdateCheck::class)->showBanner()))
            <div class="flash warn" role="status">
                <strong>QuaverDesk {{ $update['version'] }} is available</strong> (you're on {{ config('quaverdesk.version') }}).
                <a href="{{ route('admin.system') }}">See what to do</a>
                <form method="POST" action="{{ route('admin.system.dismiss-update') }}" style="display:inline">
                    @csrf <button class="btn ghost small" type="submit">Dismiss</button>
                </form>
            </div>
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
            <div class="flash warn" role="status">{{ session('upgrade') }} is available on {{ session('upgrade_plans', 'the Plus and Pro plans') }}.</div>
        @endif
        @if (session('limit'))
            <div class="flash warn" role="status">{{ session('limit') }}</div>
        @endif

        @yield('content')

        <footer class="legal">
            Source-available software provided "as is", without warranty, under the Elastic License 2.0.
            It is not a substitute for a landline or mobile for emergency calls.
            @poweredby <span class="powered">Powered by QuaverDesk.</span> @endpoweredby
            @if ($providedBy ?? null)
                <span class="powered">Provided by {{ $providedBy['name'] }}.@unless ($providedBy['branded']) Powered by QuaverDesk.@endunless</span>
            @endif
            @unless ($providedBy['branded'] ?? false)<span class="version">QuaverDesk {{ config('quaverdesk.version') }}</span>@endunless
        </footer>
    </main>
</div>
@if ($phoneDock)
    @include('partials.phone-dock')
@endif
@stack('scripts')
</body>
</html>
