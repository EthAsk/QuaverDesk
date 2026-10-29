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
