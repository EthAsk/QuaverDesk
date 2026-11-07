@extends('layouts.app')
@section('title', 'AI receptionists')
@section('content')
    <div class="page-head row" style="justify-content:space-between">
        <div>
            <h1>AI receptionists</h1>
            <p class="muted">Answers calls in plain conversation, puts callers through to the right person or team, or takes a message.</p>
        </div>
        <a class="btn" href="{{ route('admin.receptionists.create') }}">New receptionist</a>
    </div>

    @unless ($aiReady)
        <div class="flash warn" role="status">Connect your AI provider for both transcripts and summaries (<a href="{{ route('admin.ai') }}">AI provider</a>). Until then, calls to a receptionist go straight to its fallback.</div>
    @endunless

    <div class="card">
        @if ($receptionists->isEmpty())
            <p class="muted">No receptionists yet.</p>
        @else
            <table>
                <thead><tr><th>Name</th><th>Can put callers through to</th><th>Messages go to</th><th>If it can't help</th><th></th></tr></thead>
                <tbody>
                @foreach ($receptionists as $r)
                    <tr>
                        <td><a href="{{ route('admin.receptionists.edit', $r) }}">{{ $r->name }}</a></td>
                        <td>
                            @forelse ((array) $r->routes as $route)
                                <div class="small"><strong>{{ $route['name'] }}</strong> → {{ $catalog->label($route['to'] ?? null) }}</div>
                            @empty
                                <span class="muted">No one</span>
                            @endforelse
                        </td>
                        <td>{{ $r->mailbox?->name ?? 'No messages' }}</td>
                        <td>{{ $r->fallback ? $catalog->label($r->fallback) : 'Unavailable message' }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.receptionists.destroy', $r) }}" data-confirm="Delete receptionist {{ $r->name }}?">
                                @csrf @method('DELETE')
                                <button class="btn ghost" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
