@extends('layouts.app')
@section('title', $endpoint->name)
@section('content')
    <div class="page-head">
        <h1>{{ $endpoint->name }}</h1>
        <p class="muted"><a href="{{ route('admin.webhooks') }}">&larr; All endpoints</a></p>
    </div>

    @if (session('secret'))
        <div class="flash"><strong>Signing secret:</strong> <code>{{ session('secret') }}</code><br><span class="small">Keep it somewhere safe. It is not shown again.</span></div>
    @endif

    <form method="POST" action="{{ route('admin.webhooks.update', $endpoint) }}" class="card">
        @csrf @method('PUT')
        <div class="field">
            <label for="name">Name</label>
            <input id="name" type="text" name="name" value="{{ old('name', $endpoint->name) }}" required maxlength="100">
            @error('name')<div class="error">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label for="url">URL</label>
            <input id="url" type="text" name="url" value="{{ old('url', $endpoint->url) }}" required>
            @error('url')<div class="error">{{ $message }}</div>@enderror
        </div>
        @include('admin._webhook-events', ['selected' => old('events', $endpoint->events ?? [])])
        <label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $endpoint->enabled))> On</label>
        <label class="check"><input type="checkbox" name="new_secret" value="1"> Make a new signing secret (the old one stops working)</label>
        <button class="btn" type="submit">Save</button>
    </form>

    <div class="row">
        <form method="POST" action="{{ route('admin.webhooks.test', $endpoint) }}">@csrf <button class="btn ghost" type="submit">Send a test event</button></form>
        <form method="POST" action="{{ route('admin.webhooks.destroy', $endpoint) }}" onsubmit="return confirm('Remove this endpoint?')">@csrf @method('DELETE') <button class="btn ghost" type="submit">Remove</button></form>
    </div>

    <div class="card">
        <h2>Recent deliveries</h2>
        @forelse ($deliveries as $d)
            <p class="small">
                {{ $d->created_at->format('j M H:i:s') }} · <code>{{ $d->event }}</code> ·
                <strong>{{ $d->status }}</strong>@if ($d->response_code) (HTTP {{ $d->response_code }})@endif
                @if ($d->attempts > 1) · {{ $d->attempts }} attempts @endif
                @if ($d->error)<span class="muted"> · {{ $d->error }}</span>@endif
            </p>
        @empty
            <p class="muted">Nothing sent yet.</p>
        @endforelse
    </div>

    @include('admin._webhook-help')
@endsection
