@extends('layouts.app')
@section('title', 'Recording settings')
@section('content')
    <div class="page-head">
        <h1>Recording settings</h1>
        <p class="muted">Turn recording on for incoming calls per number, under Phone numbers. These settings apply to every recording.</p>
    </div>

    <form method="POST" action="{{ route('admin.recording-settings.update') }}" class="card" style="max-width:720px">
        @csrf @method('PUT')

        <div class="field">
            <label for="disclosure">Recording notice</label>
            <textarea id="disclosure" name="disclosure" rows="2" required>{{ old('disclosure', $disclosure) }}</textarea>
            <div class="small muted">Played to callers before a recorded incoming call, and to the person you call before a recorded outgoing call. Make sure it is accurate for how you use recordings.</div>
            @error('disclosure')<div class="error">{{ $message }}</div>@enderror
        </div>

        <label class="check">
            <input type="hidden" name="outbound" value="0">
            <input type="checkbox" name="outbound" value="1" @checked(old('outbound', $outbound))>
            <span>Record outgoing calls to phone numbers. Calls between colleagues and emergency calls are never recorded.</span>
        </label>

        <div class="field" style="max-width:280px;margin-top:12px">
            <label for="retention_days">Keep recordings and transcripts for (days)</label>
            @if ($canChooseRetention)
                <input id="retention_days" type="text" inputmode="numeric" name="retention_days" value="{{ old('retention_days', $retention) }}">
            @else
                <input id="retention_days" type="text" value="{{ $retention }}" disabled>
                <div class="small muted">Fixed at {{ $retention }} days on Plus. Pro lets you choose.</div>
            @endif
            @error('retention_days')<div class="error">{{ $message }}</div>@enderror
        </div>

        <label class="check">
            <input type="hidden" name="auto_ai" value="0">
            <input type="checkbox" name="auto_ai" value="1" @checked(old('auto_ai', $autoAi))>
            <span>Transcribe and summarise new recordings and voicemails automatically (uses your own AI provider and its charges).</span>
        </label>

        <button class="btn" type="submit">Save settings</button>
    </form>
@endsection
