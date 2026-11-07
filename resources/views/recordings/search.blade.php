@extends('layouts.app')
@section('title', 'Search calls')
@section('content')
    <div class="page-head">
        <h1>Search calls</h1>
        <p class="muted">Searches what was said in the calls and voicemails you can see, using their transcripts and summaries. Recordings past their retention period aren't included.</p>
    </div>

    <div class="card">
        <form method="get" action="{{ route('recordings.search') }}" class="row" style="align-items:flex-end;gap:8px;flex-wrap:wrap">
            <div class="field" style="flex:1;min-width:220px">
                <label for="q">Words, a phone number, or a question</label>
                <input id="q" name="q" type="search" value="{{ $q }}" maxlength="500" placeholder="e.g. late delivery, or: who asked for a quote this month?" autofocus>
            </div>
            <div class="field" style="max-width:170px">
                <label for="kind">In</label>
                <select id="kind" name="kind">
                    <option value="" @selected(! $kind)>Calls and voicemail</option>
                    <option value="call" @selected($kind === 'call')>Call recordings</option>
                    <option value="voicemail" @selected($kind === 'voicemail')>Voicemail</option>
                </select>
            </div>
            <button type="submit" class="btn">Search</button>
            @if ($q !== '' && $askReady)
                <button type="submit" form="ask" class="btn ghost">Ask AI</button>
            @endif
        </form>
        @if ($q !== '' && $askReady)
            {{-- A normal page load: the answer is shown on the response, not stored anywhere. --}}
            <form id="ask" method="post" action="{{ route('recordings.ask') }}" data-turbo="false">
                @csrf
                <input type="hidden" name="q" value="{{ $q }}">
                <input type="hidden" name="kind" value="{{ $kind }}">
            </form>
            <p class="small muted" style="margin-top:8px">Ask AI sends the best-matching calls you can see (summaries and transcript excerpts) to your AI provider with your question.</p>
        @elseif ($q !== '')
            <p class="small muted" style="margin-top:8px">Connect an AI provider for summaries (Admin → AI provider) to ask questions about your calls.</p>
        @endif
    </div>

    @isset($askError)
        @if ($askError)
            <div class="flash warn" role="alert">{{ $askError }}</div>
        @endif
    @endisset

    @if (! empty($answer))
        <div class="card">
            <h2>Answer</h2>
            <p>{!! $answer !!}</p>
            <p class="small muted">From {{ $sources->count() }} {{ \Illuminate\Support\Str::plural('call', $sources->count()) }} you can see. AI answers can be wrong: check the calls cited.</p>
        </div>
    @endif

    @if ($q !== '')
        <div class="card">
            @if ($results === [])
                <p class="muted">Nothing matched. Try other words, or fewer of them.</p>
            @else
                <table>
                    <thead><tr><th>When</th><th>Type</th><th>From</th><th>What was said</th></tr></thead>
                    <tbody>
                    @foreach ($results as $hit)
                        @php($r = $hit['recording'])
                        <tr>
                            <td><a href="{{ route('recordings.show', $r) }}">{{ $r->created_at->timezone('Europe/London')->format('j M Y, H:i') }}</a></td>
                            <td>{{ $r->kind === 'voicemail' ? 'Voicemail · '.($r->mailbox?->name ?? 'deleted box') : 'Call · '.($r->call?->user?->name ?? '—') }}</td>
                            <td>{{ $r->from ?? '—' }}</td>
                            <td class="small">{!! $hit['snippet'] !!}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @if (count($results) >= \App\Ai\CallSearch::RESULTS)
                    <p class="small muted">Showing the best {{ \App\Ai\CallSearch::RESULTS }}. Add words to narrow it down.</p>
                @endif
            @endif
        </div>
    @endif
@endsection
