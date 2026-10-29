@extends('layouts.app')
@section('title', 'Call history')
@section('content')
    <div class="page-head">
        <h1>Call history</h1>
        <p class="muted">{{ auth()->user()->isAdmin() ? 'All calls on this phone system.' : 'Your calls.' }}</p>
    </div>

    <div class="card">
        @if ($calls->isEmpty())
            <p class="muted">No calls yet.</p>
        @else
            <table>
                <thead>
                <tr><th>When</th><th>Who</th><th>From</th><th>To</th><th>Type</th><th>Status</th><th>Length</th><th><span class="sr-only">Call back</span></th></tr>
                </thead>
                <tbody>
                @foreach ($calls as $call)
                    <tr>
                        <td>{{ $call->created_at->format('j M, H:i') }}</td>
                        <td>{{ $call->user?->name ?? '—' }}</td>
                        <td>{{ $call->from ?? '—' }}</td>
                        <td>{{ $call->to }}</td>
                        <td>{{ ucfirst($call->direction) }}</td>
                        <td><span @class(['pill', 'warn' => in_array($call->status, ['rejected', 'failed', 'busy', 'no-answer'])])>{{ $call->status }}</span></td>
                        <td>{{ $call->duration_seconds !== null ? gmdate('i:s', $call->duration_seconds) : '—' }}</td>
                        @php($other = ($call->direction === 'inbound' || $call->to === auth()->user()->carrierIdentity()) ? $call->from : $call->to)
                        <td>@if ($other && ! in_array($other, ['999', '112'], true))<button type="button" class="btn ghost small" data-dial="{{ $other }}">Call</button>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div style="margin-top:12px">{{ $calls->links('pagination::simple-default') }}</div>
        @endif
    </div>
@endsection
