@extends('layouts.app')
@section('title', 'Sounds')
@section('content')
    <div class="page-head">
        <h1>Sounds</h1>
        <p class="muted">Your own recordings for voicemail greetings, IVR menus and hold music, so callers hear a real person or your music instead of the computer voice. Choose a sound on a <a href="{{ route('admin.mailboxes') }}">voicemail box</a> or <a href="{{ route('admin.ivr') }}">IVR menu</a>.</p>
    </div>

    <div class="card">
        <h2>Add a sound</h2>
        <form method="POST" action="{{ route('admin.sounds.store') }}" enctype="multipart/form-data" data-sound-form>
            @csrf
            <div class="field" style="max-width:420px">
                <label for="sound-name">Name</label>
                <input id="sound-name" type="text" name="name" value="{{ old('name') }}" placeholder="Main greeting" maxlength="100" required>
                @error('name')<div class="error">{{ $message }}</div>@enderror
            </div>

            <div class="sound-ways">
                <div>
                    <label for="sound-file">Upload a file</label>
                    <input id="sound-file" type="file" name="audio" accept="audio/*,.mp3,.wav,.m4a,.ogg,.flac,.webm">
                    <div class="small muted">MP3, WAV, M4A, OGG or FLAC, up to 20 MB and 10 minutes.</div>
                </div>
                <div class="or-col">or</div>
                <div data-recorder>
                    <label>Record one now</label>
                    <div class="row">
                        <button type="button" class="btn ghost" data-record-start>Start recording</button>
                        <button type="button" class="btn danger" data-record-stop hidden>Stop <span data-record-time>0:00</span></button>
                    </div>
                    <audio data-record-preview controls hidden></audio>
                    <div class="small muted" data-record-hint>Uses your computer's microphone. Speak clearly, about a hand's width away.</div>
                </div>
            </div>
            @error('audio')<div class="error">{{ $message }}</div>@enderror
            <div class="error" data-sound-error hidden></div>

            <button class="btn" type="submit" style="margin-top:14px" data-sound-save>Add sound</button>
        </form>
    </div>

    <div class="card">
        <h2>Hold music</h2>
        <form method="POST" action="{{ route('admin.sounds.hold-music') }}" class="row">
            @csrf @method('PUT')
            <label for="hold-music" class="sr-only">Hold music</label>
            <select id="hold-music" name="sound_id" style="max-width:320px">
                <option value="">None (silence)</option>
                @foreach ($sounds as $s)
                    <option value="{{ $s->id }}" @selected($holdMusicId === $s->id)>{{ $s->name }}</option>
                @endforeach
            </select>
            <button class="btn" type="submit">Save</button>
        </form>
        <p class="small muted" style="margin:10px 0 0">Plays to the other person while you have them on hold, and repeats until you take them off hold. Only use music you have the rights to play to callers (royalty-free or licensed for business use).</p>
    </div>

    <div class="card">
        <h2>Your sounds</h2>
        @if ($sounds->isEmpty())
            <p class="muted">None yet. Add one above.</p>
        @else
            <table>
                <thead><tr><th>Name</th><th>Listen</th><th>Used for</th><th></th></tr></thead>
                <tbody>
                @foreach ($sounds as $s)
                    @php
                        $uses = $s->mailboxes->map(fn ($m) => 'Voicemail: '.$m->name)
                            ->merge($s->ivrMenus->map(fn ($m) => 'IVR: '.$m->name));
                        if ($holdMusicId === $s->id) { $uses->prepend('Hold music'); }
                    @endphp
                    <tr>
                        <td>
                            <form method="POST" action="{{ route('admin.sounds.update', $s) }}" class="rename">
                                @csrf @method('PATCH')
                                <label for="sound-{{ $s->id }}" class="sr-only">Name</label>
                                <input id="sound-{{ $s->id }}" type="text" name="name" value="{{ $s->name }}" maxlength="100" required>
                                <button class="btn ghost small" type="submit">Rename</button>
                            </form>
                            <div class="small muted">{{ $s->length() }}</div>
                        </td>
                        <td><audio controls preload="none" src="{{ $s->url() }}"></audio></td>
                        <td class="small">{{ $uses->isEmpty() ? 'Not used yet' : $uses->join(', ') }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.sounds.destroy', $s) }}" data-confirm="Delete {{ $s->name }}?{{ $uses->isEmpty() ? '' : ' Anything using it goes back to reading its text aloud.' }}">
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
