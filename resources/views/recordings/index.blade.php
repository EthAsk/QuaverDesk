@extends('layouts.app')
@section('title', 'Voicemail & recordings')
@section('content')
    <div class="page-head">
        <h1>Voicemail &amp; recordings</h1>
        <p class="muted">
            <a href="{{ route('recordings') }}" @class(['muted' => $kind])>All</a> ·
            <a href="{{ route('recordings', ['kind' => 'voicemail']) }}" @class(['muted' => $kind !== 'voicemail'])>Voicemail</a> ·
            <a href="{{ route('recordings', ['kind' => 'call']) }}" @class(['muted' => $kind !== 'call'])>Call recordings</a>
        </p>
    </div>

    <div class="card">
        @if ($recordings->isEmpty())
            <p class="muted">Nothing here yet.</p>
        @else
            <table>
                <thead><tr><th>When</th><th>Type</th><th>From</th><th>Length</th><th>Summary</th></tr></thead>
                <tbody>
                @foreach ($recordings as $r)
                    <tr>
                        <td><a href="{{ route('recordings.show', $r) }}">{{ $r->created_at->timezone('Europe/London')->format('j M, H:i') }}</a></td>
                        <td>{{ $r->kind === 'voicemail' ? 'Voicemail · '.($r->mailbox?->name ?? 'deleted box') : 'Call · '.($r->call?->user?->name ?? '—') }}</td>
                        <td>{{ $r->from ?? '—' }}</td>
                        <td>{{ $r->duration_seconds ? gmdate('i:s', $r->duration_seconds) : '—' }}</td>
                        <td class="small">
                            @if ($r->summary)
                                {{ \Illuminate\Support\Str::limit($r->summary, 90) }}
                            @elseif ($r->ai_status === 'queued')
                                <span class="pill warn">Working…</span>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div style="margin-top:12px">{{ $recordings->links('pagination::simple-default') }}</div>
        @endif
    </div>
@endsection
