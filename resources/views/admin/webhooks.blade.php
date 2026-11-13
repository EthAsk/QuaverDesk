@extends('layouts.app')
@section('title', 'Webhooks')
@section('content')
    <div class="page-head">
        <h1>Webhooks and Zapier</h1>
        <p class="muted">Tell another system when something happens here: a call starts or ends, a voicemail arrives, a recording is saved, or an AI summary is ready. Works with Zapier, Make, n8n and your own software.</p>
    </div>

    <div class="card">
        <h2>Your endpoints</h2>
        @forelse ($endpoints as $endpoint)
            <p>
                <a href="{{ route('admin.webhooks.show', $endpoint) }}"><strong>{{ $endpoint->name }}</strong></a>
                <span class="small muted">{{ parse_url($endpoint->url, PHP_URL_HOST) }}</span>
                @if (! $endpoint->enabled)<span class="pill">Off</span>
                @elseif ($endpoint->last_failure_at && (! $endpoint->last_success_at || $endpoint->last_failure_at->gt($endpoint->last_success_at)))<span class="pill">Failing</span>@endif
            </p>
        @empty
            <p class="muted">None yet.</p>
        @endforelse
    </div>

    <form method="POST" action="{{ route('admin.webhooks.store') }}" class="card">
        @csrf
        <h2>Add an endpoint</h2>
        <div class="field">
            <label for="name">Name</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}" required maxlength="100" placeholder="Zapier: new voicemails">
            @error('name')<div class="error">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label for="url">URL</label>
            <input id="url" type="text" name="url" value="{{ old('url') }}" required placeholder="https://hooks.zapier.com/hooks/catch/…">
            @error('url')<div class="error">{{ $message }}</div>@enderror
        </div>
        @include('admin._webhook-events', ['selected' => old('events', [])])
        <button class="btn" type="submit">Add endpoint</button>
    </form>

    @include('admin._webhook-help')
@endsection
