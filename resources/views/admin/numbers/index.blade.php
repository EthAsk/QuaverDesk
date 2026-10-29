@extends('layouts.app')
@section('title', 'Phone numbers')
@section('content')
    <div class="page-head">
        <h1>Phone numbers</h1>
        <p class="muted">Choose where calls to each number go. Numbers come from your SIP trunks.</p>
    </div>

    <div class="card">
        @if ($numbers->isEmpty())
            <p class="muted">No numbers yet. Add one below.</p>
        @else
            <table>
                <thead><tr><th>Number</th><th>Label</th><th>Trunk</th><th>Calls go to</th><th>Hours</th><th></th></tr></thead>
                <tbody>
                @foreach ($numbers as $n)
                    <tr>
                        <td><a href="{{ route('admin.numbers.edit', $n) }}">{{ $n->e164 }}</a></td>
                        <td>{{ $n->label ?? '—' }}</td>
                        <td>{{ $n->trunk?->name ?? '—' }}</td>
                        <td>{{ $catalog->label($n->destination) }}</td>
                        <td>{!! $n->hours ? '<span class="pill">Set</span>' : '<span class="pill off">Always open</span>' !!}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.numbers.destroy', $n) }}" data-confirm="Remove {{ $n->e164 }} from this phone system?">
                                @csrf @method('DELETE')
                                <button class="btn ghost" type="submit">Remove</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="grid">
        <div class="card">
            <h2>Add a number</h2>
            <form method="POST" action="{{ route('admin.numbers.store') }}">
                @csrf
                <div class="field">
                    <label for="e164">Number</label>
                    <input id="e164" type="tel" name="e164" value="{{ old('e164') }}" placeholder="020 7946 0000" required>
                    @error('e164')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="label">Label (optional)</label>
                    <input id="label" type="text" name="label" value="{{ old('label') }}" placeholder="Main line">
                </div>
                <div class="field">
                    <label for="sip_trunk_id">Trunk the number is on</label>
                    <select id="sip_trunk_id" name="sip_trunk_id" required>
                        @foreach ($trunks as $t)
                            <option value="{{ $t->id }}" @selected((string) old('sip_trunk_id') === (string) $t->id)>{{ $t->name }}</option>
                        @endforeach
                    </select>
                    @error('sip_trunk_id')<div class="error">{{ $message }}</div>@enderror
                </div>
                @if ($trunks->isEmpty())
                    <p class="small muted"><a href="{{ route('admin.trunks.create') }}">Connect a SIP trunk</a> first.</p>
                @endif
                <button class="btn" type="submit" @disabled($trunks->isEmpty())>Add number</button>
            </form>
        </div>
    </div>
@endsection
