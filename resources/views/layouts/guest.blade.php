<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') · {{ config('app.name') }}</title>
    @include('partials.app-head')
    {{-- Sign-in pages load in full (Turbo hands over to a normal page load). --}}
    <meta name="turbo-visit-control" content="reload">
    @vite(['resources/css/app.css'])
</head>
<body>
<div class="guest">
    <div class="card">
        <div class="brand">{{ config('app.name') }}<span>.</span></div>
        @if (session('status'))
            <div class="flash ok" role="status">{{ session('status') }}</div>
        @endif
        @yield('content')
    </div>
</div>
@stack('scripts')
</body>
</html>
