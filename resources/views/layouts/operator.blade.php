<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>@yield('title') · Operator · {{ config('app.name') }}</title>
    @include('partials.app-head')
    <meta name="turbo-visit-control" content="reload">
    @vite(['resources/css/app.css'])
</head>
<body>
<div class="shell">
    <nav class="side" aria-label="Operator">
        <div class="brand">{{ config('app.name') }}<span>.</span> <small class="muted">operator</small></div>
        <a href="{{ route('operator.companies') }}" @class(['on' => request()->routeIs('operator.companies*')])>Companies</a>
        @if (Route::has('operator.calls'))
            <a href="{{ route('operator.calls') }}" @class(['on' => request()->routeIs('operator.calls')])>Call logs</a>
            <a href="{{ route('operator.server-log') }}" @class(['on' => request()->routeIs('operator.server-log')])>Server log</a>
            <a href="{{ route('operator.voice-log') }}" @class(['on' => request()->routeIs('operator.voice-log')])>Voice server log</a>
            <a href="{{ route('operator.licences') }}" @class(['on' => request()->routeIs('operator.licences*')])>Licences</a>
            <a href="{{ route('operator.resellers') }}" @class(['on' => request()->routeIs('operator.resellers*')])>Resellers</a>
        @endif
        <a href="{{ route('operator.audit') }}" @class(['on' => request()->routeIs('operator.audit')])>Audit log</a>
        <div class="spacer"></div>
        <form method="POST" action="{{ route('operator.logout') }}">
            @csrf
            <button type="submit">Sign out {{ auth('operator')->user()->name }}</button>
        </form>
    </nav>
    <main>
        @if (session('status'))
            <div class="flash ok" role="status">{{ session('status') }}</div>
        @endif
        @yield('content')
    </main>
</div>
</body>
</html>
