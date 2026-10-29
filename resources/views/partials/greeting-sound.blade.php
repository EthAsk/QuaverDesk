{{-- Play one of the business's own recordings instead of reading the greeting text. Needs $selected. --}}
<div class="field" style="max-width:420px">
    <label for="greeting_sound_id">Play a recording instead</label>
    <select id="greeting_sound_id" name="greeting_sound_id">
        <option value="">No: read the text above aloud</option>
        @foreach ($sounds as $s)
            <option value="{{ $s->id }}" @selected((string) old('greeting_sound_id', $selected) === (string) $s->id)>{{ $s->name }} ({{ $s->length() }})</option>
        @endforeach
    </select>
    <div class="small muted">
        @if ($sounds->isEmpty())
            Record or upload your own under <a href="{{ route('admin.sounds') }}">Sounds</a>.
        @else
            Your own recordings, from <a href="{{ route('admin.sounds') }}">Sounds</a>. The text is still used if the recording is deleted.
        @endif
    </div>
    @error('greeting_sound_id')<div class="error">{{ $message }}</div>@enderror
</div>
