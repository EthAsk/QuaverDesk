@extends('layouts.app')
@section('title', 'Call logs')
@section('content')
    <div class="page-head">
        <h1>Call logs</h1>
        <p class="muted">For working out what went wrong: every call, including calls refused before they rang anyone, with the trunk and why any were refused or failed. The record of calls is <a href="{{ route('calls') }}">Call history</a>.</p>
    </div>

    <div class="card">
        <div class="row" style="margin-bottom:12px">
            <a @class(['btn', 'small', 'ghost' => $problems]) href="{{ route('admin.call-logs') }}">All calls</a>
            <a @class(['btn', 'small', 'ghost' => ! $problems]) href="{{ route('admin.call-logs', ['problems' => 1]) }}">Problems only</a>
        </div>
        @if ($calls->isEmpty())
            <p class="muted">{{ $problems ? 'No refused or failed calls.' : 'No calls yet.' }}</p>
        @else
            <table>
                <thead>
                <tr><th>When</th><th>Direction</th><th>From</th><th>To</th><th>Who</th><th>Trunk</th><th>Status</th><th>Length</th><th>Details</th></tr>
                </thead>
                <tbody>
                @foreach ($calls as $call)
                    <tr>
                        <td style="white-space:nowrap">{{ $call->created_at->format('j M, H:i:s') }}</td>
                        <td>{{ ucfirst($call->direction) }}</td>
                        <td>{{ $call->from ?? '—' }}@if ($call->caller_name)<div class="small muted">{{ $call->caller_name }}</div>@endif</td>
                        <td>{{ $call->to }}@if ($call->api_call_id) <span class="pill">API</span>@endif</td>
                        <td>{{ $call->user?->name ?? '—' }}</td>
                        <td>{{ $call->leg === \App\Models\Call::LEG_ON_NET ? 'Direct (on this service)' : ($call->trunk?->name ?? '—') }}</td>
                        <td><span @class(['pill', 'warn' => in_array($call->status, \App\Models\Call::PROBLEMS, true)])>{{ $call->status }}</span></td>
                        <td>{{ $call->duration_seconds !== null ? gmdate('i:s', $call->duration_seconds) : '—' }}</td>
                        <td class="small">{{ $call->reason ?? '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div style="margin-top:12px">{{ $calls->links('pagination::simple-default') }}</div>
        @endif
    </div>
@endsection
