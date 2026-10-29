@extends('layouts.app')
@section('title', 'AI provider')
@section('content')
    <div class="page-head">
        <h1>Your AI provider</h1>
        <p class="muted">AI features run on your own AI accounts and are billed to you by that provider. This phone system never runs AI models and never sees your keys after you save them.</p>
    </div>

    @error('test')<div class="flash bad">{{ $message }}</div>@enderror

    <form method="POST" action="{{ route('admin.ai.update') }}">
        @csrf @method('PUT')

        <div class="card">
            <h2>Transcription (speech to text)</h2>
            <p class="small muted">Any service with an OpenAI-compatible <code>/audio/transcriptions</code> endpoint, including self-hosted ones.</p>
            <div class="field">
                <label for="t_base">API base URL</label>
                <input id="t_base" type="text" name="transcription[base_url]" value="{{ old('transcription.base_url', $transcription['base_url']) }}" required>
                @error('transcription.base_url')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="t_model">Model</label>
                <input id="t_model" type="text" name="transcription[model]" value="{{ old('transcription.model', $transcription['model']) }}" required>
                @error('transcription.model')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="t_key">API key {!! $hasTranscriptionKey ? '<span class="pill">Saved</span>' : '' !!}</label>
                <input id="t_key" type="password" name="transcription[api_key]" autocomplete="off" placeholder="{{ $hasTranscriptionKey ? 'Leave blank to keep the saved key' : '' }}">
            </div>
        </div>

        <div class="card">
            <h2>Summaries, action items and sentiment</h2>
            <div class="field">
                <label for="a_provider">Provider</label>
                <select id="a_provider" name="analysis[provider]">
                    <option value="anthropic" @selected(old('analysis.provider', $analysis['provider']) === 'anthropic')>Anthropic (Claude)</option>
                    <option value="openai_compatible" @selected(old('analysis.provider', $analysis['provider']) === 'openai_compatible')>OpenAI-compatible (chat completions)</option>
                </select>
            </div>
            <div class="field">
                <label for="a_base">API base URL (OpenAI-compatible only)</label>
                <input id="a_base" type="text" name="analysis[base_url]" value="{{ old('analysis.base_url', $analysis['base_url']) }}">
                @error('analysis.base_url')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="a_model">Model</label>
                <input id="a_model" type="text" name="analysis[model]" value="{{ old('analysis.model', $analysis['model']) }}" required>
                <div class="small muted">Use a model your account can access. A small, fast model is usually enough for call summaries.</div>
                @error('analysis.model')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="a_key">API key {!! $hasAnalysisKey ? '<span class="pill">Saved</span>' : '' !!}</label>
                <input id="a_key" type="password" name="analysis[api_key]" autocomplete="off" placeholder="{{ $hasAnalysisKey ? 'Leave blank to keep the saved key' : '' }}">
            </div>
        </div>

        <div class="card">
            <p class="small">Call audio, transcripts and summaries go to the providers above under <strong>your</strong> agreement with them. Check their data location, retention and training settings, and whether you need a data processing agreement.</p>
            <button class="btn" type="submit">Save AI provider</button>
        </div>
    </form>

    <div class="row">
        <form method="POST" action="{{ route('admin.ai.test') }}">
            @csrf
            <button class="btn ghost" type="submit" @disabled(! $hasAnalysisKey)>Test summaries with a sample message</button>
        </form>
        @foreach (['transcription' => $hasTranscriptionKey, 'analysis' => $hasAnalysisKey] as $part => $has)
            @if ($has)
                <form method="POST" action="{{ route('admin.ai.forget') }}" data-confirm="Remove the saved {{ $part }} API key?">
                    @csrf @method('DELETE')
                    <input type="hidden" name="part" value="{{ $part }}">
                    <button class="btn ghost" type="submit">Remove {{ $part }} key</button>
                </form>
            @endif
        @endforeach
    </div>
@endsection
