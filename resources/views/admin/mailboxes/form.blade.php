@extends('layouts.app')
@section('title', $mailbox->exists ? $mailbox->name : 'New voicemail box')
@section('content')
    <div class="page-head">
        <h1>{{ $mailbox->exists ? $mailbox->name : 'New voicemail box' }}</h1>
        <p class="muted"><a href="{{ route('admin.mailboxes') }}">All voicemail boxes</a></p>
    </div>

    <form method="POST" action="{{ $mailbox->exists ? route('admin.mailboxes.update', $mailbox) : route('admin.mailboxes.store') }}">
        @csrf
        @if ($mailbox->exists) @method('PUT') @endif

        <div class="card">
            <div class="field">
                <label for="name">Name</label>
                <input id="name" type="text" name="name" value="{{ old('name', $mailbox->name) }}" placeholder="Main office" required>
                @error('name')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="greeting">Greeting</label>
                <textarea id="greeting" name="greeting" rows="3" required>{{ old('greeting', $mailbox->greeting) }}</textarea>
                <div class="small muted">Read aloud before the tone. If you transcribe voicemail, consider mentioning it here.</div>
                @error('greeting')<div class="error">{{ $message }}</div>@enderror
            </div>
            @include('partials.greeting-sound', ['selected' => $mailbox->greeting_sound_id])
            <div class="field" style="max-width:240px">
                <label for="max_seconds">Longest message (seconds)</label>
                <input id="max_seconds" type="text" inputmode="numeric" name="max_seconds" value="{{ old('max_seconds', $mailbox->max_seconds) }}" required>
                @error('max_seconds')<div class="error">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="card">
            <h2>People</h2>
            <table>
                <thead><tr><th>Person</th><th>Gets the emails</th><th>Their missed calls come here</th></tr></thead>
                <tbody>
                @foreach ($users as $u)
                    <tr>
                        <td>{{ $u->name }} <span class="muted small">{{ $u->email }}</span></td>
                        <td><input type="checkbox" name="members[]" value="{{ $u->id }}" @checked(in_array($u->id, old('members', $memberIds))) aria-label="{{ $u->name }} gets emails"></td>
                        <td>
                            <input type="checkbox" name="owners[]" value="{{ $u->id }}" @checked(in_array($u->id, old('owners', $ownerIds))) aria-label="{{ $u->name }}'s missed calls">
                            @if ($u->mailbox_id && $u->mailbox_id !== $mailbox->id)
                                <span class="small muted">(currently another box)</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div class="field" style="margin-top:16px">
                <label for="extra_emails">Other email addresses (optional)</label>
                <input id="extra_emails" type="text" name="extra_emails" value="{{ old('extra_emails', implode(', ', $mailbox->extra_emails ?? [])) }}" placeholder="office@example.co.uk">
                @error('extra_emails')<div class="error">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="card">
            <h2>Emails</h2>
            <label class="check">
                <input type="hidden" name="attach_audio" value="0">
                <input type="checkbox" name="attach_audio" value="1" @checked(old('attach_audio', $mailbox->attach_audio))>
                <span>Attach the recording (WAV file).</span>
            </label>
            <label class="check">
                <input type="hidden" name="include_transcript" value="0">
                <input type="checkbox" name="include_transcript" value="1" @checked(old('include_transcript', $mailbox->include_transcript))>
                <span>Include the transcript and summary (Plus and Pro, using your own AI provider).</span>
            </label>
            <label class="check">
                <input type="hidden" name="delete_after_send" value="0">
                <input type="checkbox" name="delete_after_send" value="1" @checked(old('delete_after_send', $mailbox->delete_after_send))>
                <span>Delete the audio from this phone system once it has been emailed. The email becomes the only copy of the recording.</span>
            </label>
            <p class="small muted">Email is not end-to-end encrypted. Think about what callers might say before attaching audio or transcripts.</p>
        </div>

        <button class="btn" type="submit">Save voicemail box</button>
    </form>
@endsection
