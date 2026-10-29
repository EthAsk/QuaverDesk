@extends('layouts.app')
@section('title', $number->e164)
@section('content')
    <div class="page-head">
        <h1>{{ $number->e164 }}</h1>
        <p class="muted"><a href="{{ route('admin.numbers') }}">All numbers</a></p>
    </div>

    <form method="POST" action="{{ route('admin.numbers.update', $number) }}">
        @csrf @method('PUT')

        <div class="card">
            <h2>Calls go to</h2>
            <div class="field">
                <label for="label">Label</label>
                <input id="label" type="text" name="label" value="{{ old('label', $number->label) }}">
            </div>
            <div class="field">
                <label for="destination">When open</label>
                @include('partials.destination-select', ['name' => 'destination', 'options' => $options, 'selected' => old('destination', $number->destination)])
                @error('destination')<div class="error">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="card">
            <h2>Opening hours</h2>
            <label class="check">
                <input type="hidden" name="use_hours" value="0">
                <input type="checkbox" name="use_hours" value="1" @checked(old('use_hours', (bool) $number->hours))>
                <span>Use opening hours (UK time). Leave unticked to be open all the time.</span>
            </label>
            @error('hours')<div class="error">{{ $message }}</div>@enderror

            <table style="max-width:520px">
                <thead><tr><th>Day</th><th>Opens</th><th>Closes</th></tr></thead>
                <tbody>
                @foreach ($days as $key => $day)
                    @php($slot = $hours['days'][$key][0] ?? [null, null])
                    <tr>
                        <td>{{ $day }}</td>
                        <td><input type="time" name="hours[{{ $key }}][open]" value="{{ old("hours.$key.open", $slot[0]) }}" aria-label="{{ $day }} opens"></td>
                        <td><input type="time" name="hours[{{ $key }}][close]" value="{{ old("hours.$key.close", $slot[1]) }}" aria-label="{{ $day }} closes"></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="small muted">Leave both times empty for a closed day.</p>

            <div class="field">
                <label for="closed_dates">Closed dates (bank holidays etc.)</label>
                <textarea id="closed_dates" name="closed_dates" rows="3" placeholder="2026-12-25&#10;2026-12-26">{{ old('closed_dates', implode("\n", $hours['closed_dates'] ?? [])) }}</textarea>
            </div>

            <div class="field">
                <label for="closed_destination">When closed, calls go to</label>
                @include('partials.destination-select', ['name' => 'closed_destination', 'options' => $options, 'selected' => old('closed_destination', $number->closed_destination), 'empty' => 'Choose…'])
                @error('closed_destination')<div class="error">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="card">
            <h2>Call recording</h2>
            @feature('recording')
                <label class="check">
                    <input type="hidden" name="record_calls" value="0">
                    <input type="checkbox" name="record_calls" value="1" @checked(old('record_calls', $number->record_calls))>
                    <span>Record answered calls to this number. Callers first hear the notice set under
                        <a href="{{ route('admin.recording-settings') }}">Recording settings</a>.</span>
                </label>
            @else
                <p class="muted">Call recording is available on the Plus and Pro plans.</p>
            @endfeature
        </div>

        <button class="btn" type="submit">Save routing</button>
    </form>
@endsection
