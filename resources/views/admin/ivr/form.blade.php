@extends('layouts.app')
@section('title', $menu->exists ? $menu->name : 'New IVR menu')
@section('content')
    <div class="page-head">
        <h1>{{ $menu->exists ? $menu->name : 'New IVR menu' }}</h1>
        <p class="muted"><a href="{{ route('admin.ivr') }}">All menus</a></p>
    </div>

    <form method="POST" action="{{ $menu->exists ? route('admin.ivr.update', $menu) : route('admin.ivr.store') }}">
        @csrf
        @if ($menu->exists) @method('PUT') @endif

        <div class="card">
            <div class="field">
                <label for="name">Name</label>
                <input id="name" type="text" name="name" value="{{ old('name', $menu->name) }}" placeholder="Main menu" required>
                @error('name')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="greeting">What callers hear</label>
                <textarea id="greeting" name="greeting" rows="3" required>{{ old('greeting', $menu->greeting) }}</textarea>
                <div class="small muted">Read aloud in the voice chosen under <a href="{{ route('admin.voice') }}">Voice</a>. Say every option you set below.</div>
                @error('greeting')<div class="error">{{ $message }}</div>@enderror
            </div>
            @include('partials.greeting-sound', ['selected' => $menu->greeting_sound_id])
            <div class="field" style="max-width:240px">
                <label for="timeout_seconds">Wait for a key press (seconds)</label>
                <input id="timeout_seconds" type="text" inputmode="numeric" name="timeout_seconds" value="{{ old('timeout_seconds', $menu->timeout_seconds) }}" required>
                @error('timeout_seconds')<div class="error">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="card">
            <h2>Keypad options</h2>
            <table>
                <thead><tr><th style="width:80px">Key</th><th>Sends the caller to</th></tr></thead>
                <tbody>
                @foreach (['1','2','3','4','5','6','7','8','9','0'] as $digit)
                    <tr>
                        <td><strong>{{ $digit }}</strong></td>
                        <td>
                            @include('partials.destination-select', [
                                'name' => "options[$digit]",
                                'options' => $options,
                                'selected' => old("options.$digit", $menu->options[$digit] ?? null),
                                'empty' => 'Not used',
                            ])
                            @error("options.$digit")<div class="error">{{ $message }}</div>@enderror
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="card">
            <div class="field">
                <label for="fallback">If the caller presses nothing after 3 tries</label>
                @include('partials.destination-select', ['name' => 'fallback', 'options' => $options, 'selected' => old('fallback', $menu->fallback), 'empty' => 'Play "unavailable" message and hang up'])
                @error('fallback')<div class="error">{{ $message }}</div>@enderror
            </div>
        </div>

        <button class="btn" type="submit">Save menu</button>
    </form>
@endsection
