@extends('layouts.app')
@section('title', 'API')
@section('content')
    <div class="page-head">
        <h1>API</h1>
        <p class="muted">Let your own software read your phone system: people, numbers and call history. Read-only. It never returns recordings, transcripts, passwords or keys. For being told when something happens, use <a href="{{ route('admin.webhooks') }}">Webhooks</a>.</p>
    </div>

    @if (session('api_token'))
        <div class="flash"><strong>API token:</strong> <code>{{ session('api_token') }}</code><br><span class="small">Keep it secret, like a password. It is not shown again.</span></div>
    @endif

    <div class="card">
        <h2>Tokens</h2>
        @forelse ($tokens as $token)
            <form method="POST" action="{{ route('admin.api.destroy', $token) }}" class="row" data-confirm="Remove {{ $token->name }}? Anything using it stops working now.">
                @csrf @method('DELETE')
                <span><strong>{{ $token->name }}</strong> @if ($token->canCall())<span class="pill">Can place calls</span>@endif <span class="small muted">…{{ $token->last_four }} · {{ $token->last_used_at ? 'used '.$token->last_used_at->diffForHumans() : 'never used' }}@if ($token->canCall()) · {{ $token->per_minute }}/min, {{ $token->per_hour }}/hour, {{ $token->daily_minutes }} min/day @endif</span></span>
                <button class="btn ghost small" type="submit">Remove</button>
            </form>
        @empty
            <p class="muted">None yet.</p>
        @endforelse
    </div>

    <form method="POST" action="{{ route('admin.api.store') }}" class="card">
        @csrf
        <h2>Make a token</h2>
        <div class="field">
            <label for="name">What it's for</label>
            <input id="name" type="text" name="name" required maxlength="100" placeholder="Reporting dashboard">
            @error('name')<div class="error">{{ $message }}</div>@enderror
        </div>
        <button class="btn" type="submit">Make token</button>
    </form>

    @feature('api_calls')
        <form method="POST" action="{{ route('admin.api.calling') }}" class="card">
            @csrf
            <h2>Make a token that can place calls</h2>
            <p class="small muted">Lets your software ring one of the chosen people's phones; when they answer, the number is dialled for them, under the same calling rules and limits as if they'd dialled it. Only UK numbers unless you list other countries. Premium-rate and emergency numbers can never be called this way. Treat the token like a bank card: anyone who has it can make these calls.</p>
            <div class="field"><label for="cname">What it's for</label><input id="cname" type="text" name="name" required maxlength="100" placeholder="CRM click-to-call"></div>
            <div class="field">
                <label>People it may place calls for</label>
                @foreach ($users as $u)<label class="check"><input type="checkbox" name="users[]" value="{{ $u->id }}"> {{ $u->name }}</label>@endforeach
                @error('users')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field"><label for="countries">Other countries (dialling codes, optional)</label><input id="countries" type="text" name="countries" placeholder="+353, +33"></div>
            <div class="row">
                <div class="field"><label for="per_minute">Calls a minute</label><input id="per_minute" type="number" name="per_minute" min="1" max="60" value="10"></div>
                <div class="field"><label for="per_hour">Calls an hour</label><input id="per_hour" type="number" name="per_hour" min="1" max="600" value="60"></div>
                <div class="field"><label for="daily_minutes">Minutes a day</label><input id="daily_minutes" type="number" name="daily_minutes" min="1" max="1440" value="120"></div>
            </div>
            <button class="btn" type="submit">Make calling token</button>
        </form>

        <div class="card">
            <h2>Calls placed this way</h2>
            <form method="POST" action="{{ route('admin.api.pause') }}" class="row">
                @csrf <input type="hidden" name="paused" value="{{ $paused ? 0 : 1 }}">
                <span class="small">{{ $paused ? 'Calling over the API is paused.' : 'Calling over the API is on.' }}</span>
                <button class="btn ghost small" type="submit">{{ $paused ? 'Switch back on' : 'Pause all calling' }}</button>
            </form>
            @forelse ($recent as $r)
                <p class="small">{{ $r->created_at->timezone('Europe/London')->format('j M H:i') }} · {{ $r->token_name }} · for {{ $r->user?->name ?? '?' }} to {{ $r->to }} · <strong>{{ $r->outcome() }}</strong>@if ($r->reason) <span class="muted">({{ $r->reason }})</span>@endif <span class="muted">from {{ $r->ip }}</span></p>
            @empty
                <p class="muted small">None yet.</p>
            @endforelse
        </div>
    @endfeature

    <div class="card">
        <h2>Using it</h2>
        <p class="small">Base address <code>{{ url('/api/v1') }}</code>. Send <code>Authorization: Bearer &lt;token&gt;</code>. Everything is JSON, lists are paged (<code>?page=2&amp;per_page=100</code>, up to 100), and each list has a <code>meta</code> block.</p>
        <table class="small">
            <tr><td><code>GET /me</code></td><td>Your company, plan and how many users are used</td></tr>
            <tr><td><code>GET /users</code>, <code>/users/{id}</code></td><td>People: name, email, extension, role, type, suspended</td></tr>
            <tr><td><code>GET /numbers</code></td><td>Your phone numbers and their trunks</td></tr>
            <tr><td><code>POST /calls</code> (calling tokens)</td><td><code>{"user_id": 12, "to": "+447700900123"}</code> rings that person's phones; when they answer the number is dialled. Send an <code>Idempotency-Key</code> header so a retry can't place two calls. Returns 202 with a request <code>id</code>; <code>GET /call-requests/{id}</code> shows how it went (<code>requested</code>, <code>answered</code>, <code>no_answer</code>, <code>refused</code>).</td></tr>
            <tr><td><code>GET /calls</code>, <code>/calls/{id}</code></td><td>Call history, newest first. Filters: <code>from</code>, <code>to</code> (dates), <code>direction</code>, <code>number</code>, <code>user_id</code></td></tr>
        </table>
        <p class="small muted">Up to 120 requests a minute per token. Example: <code>curl -H "Authorization: Bearer qdapi_…" "{{ url('/api/v1/calls') }}?direction=inbound&amp;from=2026-11-01"</code></p>
    </div>
@endsection
