@extends('layouts.app')
@section('title', 'Call monitoring')
@section('content')
    <div class="page-head">
        <h1>Call monitoring</h1>
        <p class="muted">Let chosen admins listen in on live calls from the <a href="{{ route('wallboard') }}">live wallboard</a>: <strong>listen</strong> (they hear both sides), <strong>whisper</strong> (they talk and only your person hears) or <strong>barge</strong> (they join the call). It rings their own phone; they answer it to start. Emergency calls and a person's own calls are never monitored, and every use is logged below.</p>
    </div>

    <div class="card">
        <h2>1. Callers and staff are told</h2>
        @if ($ack)
            <p class="small"><span class="pill">Confirmed</span> by {{ $ack['name'] }} on {{ \Illuminate\Support\Carbon::parse($ack['at'])->timezone('Europe/London')->format('j M Y') }}.</p>
        @elseif (auth()->user()->isOwner())
            <form method="POST" action="{{ route('admin.monitoring.acknowledge') }}">
                @csrf
                <label class="check"><input type="checkbox" name="confirm" value="1"> I confirm the people who may be monitored (staff) and the people they speak to (callers) are told that calls may be monitored, for example in the recording notice, a privacy notice and employment terms, and that monitoring is only for training, quality and safety.</label>
                @error('confirm')<div class="error">{{ $message }}</div>@enderror
                <p class="small muted">UK rules expect people to be told. This page doesn't tell them for you: check your notices, and take advice if unsure.</p>
                <button class="btn" type="submit">Confirm</button>
            </form>
        @else
            <p class="small muted">The owner needs to confirm this first.</p>
        @endif
    </div>

    <form method="POST" action="{{ route('admin.monitoring.supervisors') }}" class="card">
        @csrf
        <h2>2. Who may monitor</h2>
        <p class="small muted">Admins only, because the wallboard is an admin page. Nobody can until the owner chooses.</p>
        @foreach ($admins as $a)
            <label class="check"><input type="checkbox" name="supervisors[]" value="{{ $a->id }}" @checked(in_array($a->id, $supervisors)) @disabled(! auth()->user()->isOwner())> {{ $a->name }}</label>
        @endforeach
        @if (auth()->user()->isOwner())<button class="btn" type="submit">Save</button>@endif
    </form>

    <div class="card">
        <h2>Recent monitoring</h2>
        @forelse ($events as $e)
            <p class="small">{{ $e->created_at->timezone('Europe/London')->format('j M H:i') }} · {{ $e->user?->name ?? 'Someone' }} · {{ $e->mode }} · {{ $e->call ? ($e->call->direction.' '.($e->call->direction === 'inbound' ? $e->call->from : $e->call->to)) : 'a call' }} · <strong>{{ ['started' => 'started', 'emergency' => 'refused: emergency call', 'not_live' => 'call had ended', 'own_call' => 'refused: own call', 'no_phone' => 'no phone to ring', 'failed' => 'failed'][$e->result] ?? $e->result }}</strong></p>
        @empty
            <p class="muted small">None yet.</p>
        @endforelse
    </div>
@endsection
