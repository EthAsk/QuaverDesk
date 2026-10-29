<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Phone · {{ config('app.name') }}</title>
    @include('partials.app-head')
    @vite(['resources/css/app.css', 'resources/js/phone.js'])
</head>
<body class="phone-app">
<div id="phone" class="phone-window" data-token-url="{{ route('softphone.token') }}" data-park-url="{{ route('softphone.park') }}">
    <header class="phone-top">
        <div class="phone-status"><span class="status-dot" id="status-dot" data-state="connecting"></span><span id="state" aria-live="polite">Connecting…</span></div>
        @if (request()->has('popout'))
            {{-- Popped out of the app: closing hands the phone back to the dock there. --}}
            <button type="button" class="btn ghost small" id="dock-back" title="Close this window and put the phone back in {{ config('app.name') }}">Back to {{ config('app.name') }}</button>
        @else
            <a class="btn ghost small" href="{{ route('dashboard') }}" target="_blank" rel="noopener" title="Open {{ config('app.name') }}">{{ config('app.name') }} ↗</a>
        @endif
    </header>
    @if ($companySuspended ?? false)
        <div class="flash bad" role="alert">This account is suspended. Only emergency calls (999 and 112) can be made.</div>
    @endif
    @include('partials.phone')
</div>
</body>
</html>
