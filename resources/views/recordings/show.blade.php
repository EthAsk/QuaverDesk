@extends('layouts.app')
@section('title', $recording->kind === 'voicemail' ? 'Voicemail' : 'Call recording')
@section('content')
    <div class="page-head">
        <h1>{{ $recording->kind === 'voicemail' ? 'Voicemail' : 'Call recording' }}</h1>
        <p class="muted">
            <a href="{{ route('recordings') }}">All recordings</a> ·
            {{ $recording->created_at->timezone('Europe/London')->format('D j M Y, H:i') }} ·
            from {{ $recording->from ?? 'unknown' }}
            @if ($recording->duration_seconds) · {{ gmdate('i:s', $recording->duration_seconds) }} @endif
        </p>
    </div>

    @error('ai')<div class="flash bad">{{ $message }}</div>@enderror

    <div class="card">
        <h2>Listen</h2>
        @if ($recording->hasAudio())
            <audio controls preload="none" src="{{ route('recordings.audio', $recording) }}" style="width:100%"></audio>
        @elseif ($recording->status === 'pending')
            <p class="muted">Still being saved. Refresh in a moment.</p>
        @elseif ($recording->status === 'failed')
            <p class="muted">The audio could not be saved: {{ $recording->error }}</p>
        @else
            <p class="muted">The audio has been deleted{{ $recording->mailbox?->delete_after_send ? ' after it was emailed' : '' }}.</p>
        @endif
        @if ($recording->expires_at)
            <p class="small muted">Deleted automatically on {{ $recording->expires_at->format('j M Y') }}.</p>
        @endif
    </div>

    <div class="card">
        <div class="row" style="justify-content:space-between">
            <h2 style="margin:0">AI notes</h2>
            @if ($features['transcript'])
                <form method="POST" action="{{ route('recordings.analyse', $recording) }}">
                    @csrf
                    <button class="btn" type="submit" @disabled($recording->ai_status === 'queued' || ! $aiReady)>
                        {{ $recording->ai_status === 'done' ? 'Run again' : 'Transcribe & summarise' }}
                    </button>
                </form>
            @else
                <button class="btn locked" type="button" disabled title="Available on Plus and Pro">Transcribe &amp; summarise · Plus</button>
            @endif
        </div>

        @if ($features['transcript'] && ! $aiReady)
            <p class="small muted" style="margin-top:8px">Connect your own AI provider to use this. @if (auth()->user()->isAdmin())<a href="{{ route('admin.ai') }}">Set it up</a>@else Ask an admin to set it up.@endif</p>
        @endif

        @if ($recording->ai_status === 'queued')
            <p class="muted" style="margin-top:12px">Working on it — refresh in a minute.</p>
        @elseif ($recording->ai_status === 'failed')
            <p class="error" style="margin-top:12px">{{ $recording->ai_error }}</p>
        @endif

        @if ($recording->summary)
            <h3>Summary</h3>
            <p>{{ $recording->summary }}</p>
        @endif

        @if ($recording->action_items)
            <h3>Action items</h3>
            <ul>@foreach ($recording->action_items as $item)<li>{{ $item }}</li>@endforeach</ul>
        @elseif (! $features['actionItems'] && $recording->summary)
            <p class="small muted">Action items and sentiment are available on Pro.</p>
        @endif

        @if ($recording->sentiment)
            <p>Caller sentiment: <span class="pill">{{ ucfirst($recording->sentiment) }}</span></p>
        @endif

        @if ($recording->transcript)
            <h3>Transcript</h3>
            <p style="white-space:pre-wrap">{{ $recording->transcript }}</p>
            <p class="small muted">Produced automatically by your AI provider. It may contain mistakes.</p>
        @endif
    </div>

    @if (auth()->user()->isAdmin())
        <form method="POST" action="{{ route('recordings.destroy', $recording) }}" data-confirm="Delete this recording and its transcript for good?">
            @csrf @method('DELETE')
            <button class="btn ghost" type="submit">Delete recording and transcript</button>
        </form>
    @endif
@endsection
