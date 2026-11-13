<div class="field">
    <label>Send me</label>
    <div class="small muted">Leave all unticked to receive everything.</div>
    @foreach ($events as $key => $label)
        <label class="check"><input type="checkbox" name="events[]" value="{{ $key }}" @checked(in_array($key, $selected, true))> <code>{{ $key }}</code> {{ $label }}</label>
    @endforeach
    @error('events.*')<div class="error">{{ $message }}</div>@enderror
</div>
