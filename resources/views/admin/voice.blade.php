@extends('layouts.app')
@section('title', 'Voice')
@section('content')
    <div class="page-head">
        <h1>Voice</h1>
        <p class="muted">The voice that reads your greetings, menus and messages. Listen to each one, then choose. For the most natural result, <a href="{{ route('admin.sounds') }}">record your own greetings</a>.</p>
    </div>

    <form method="POST" action="{{ route('admin.voice.update') }}" data-voice-form data-preview-url="{{ route('admin.voice.preview') }}">
        @csrf @method('PUT')

        <div class="card">
            <div class="field">
                <label for="voice-sample">Words to try</label>
                <textarea id="voice-sample" rows="2" maxlength="400" data-voice-sample>{{ $sample }}</textarea>
            </div>
            <div class="voice-controls">
                <fieldset class="seg">
                    <legend class="sr-only">How to listen</legend>
                    <label><input type="radio" name="line" value="phone" checked data-voice-line> As callers hear it</label>
                    <label><input type="radio" name="line" value="clear" data-voice-line> Full quality</label>
                </fieldset>
                <div class="speed">
                    <label for="voice-speed">Pace <output data-voice-speed-out>{{ number_format($speed, 2) }}×</output></label>
                    <input id="voice-speed" type="range" name="speed" min="0.7" max="1.3" step="0.05" value="{{ $speed }}" data-voice-speed>
                </div>
            </div>
            <p class="small muted" style="margin:8px 0 0">"As callers hear it" squeezes the sound through a phone line, which is how it reaches most callers. The first play of each voice takes a few seconds.</p>
        </div>

        <div class="card">
            @php $engine = null; @endphp
            @foreach ($voices as $id => $v)
                @if ($v['engine'] !== $engine)
                    @php $engine = $v['engine']; @endphp
                    <h2 @if (! $loop->first) style="margin-top:18px" @endif>
                        {{ $engine }}
                        <span class="small muted" style="font-weight:400">{{ ['Kokoro' => 'most natural', 'Piper' => 'natural, quicker', 'FreeSWITCH' => 'fallback'][$engine] ?? '' }}</span>
                    </h2>
                @endif
                <div class="voice-row">
                    <label class="voice-pick">
                        <input type="radio" name="voice" value="{{ $id }}" @checked($current === $id)>
                        <span><strong>{{ $v['name'] }}</strong> <span class="muted small">{{ $v['about'] }}</span></span>
                    </label>
                    @if ($id !== 'freeswitch')
                        <button type="button" class="btn ghost small" data-voice-play="{{ $id }}">Play</button>
                    @endif
                </div>
            @endforeach
            @error('voice')<div class="error">{{ $message }}</div>@enderror
            <div class="error" data-voice-error hidden></div>
        </div>

        <button class="btn" type="submit">Use this voice</button>
        <span class="small muted" style="margin-left:8px">Saving remakes every greeting and message in the new voice.</span>
    </form>
@endsection
