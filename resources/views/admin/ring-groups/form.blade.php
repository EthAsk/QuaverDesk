@extends('layouts.app')
@section('title', $group->exists ? $group->name : 'New ring group')
@section('content')
    <div class="page-head">
        <h1>{{ $group->exists ? $group->name : 'New ring group' }}</h1>
        <p class="muted"><a href="{{ route('admin.ring-groups') }}">All ring groups</a></p>
    </div>

    <form method="POST" action="{{ $group->exists ? route('admin.ring-groups.update', $group) : route('admin.ring-groups.store') }}">
        @csrf
        @if ($group->exists) @method('PUT') @endif

        <div class="card">
            <div class="field">
                <label for="name">Name</label>
                <input id="name" type="text" name="name" value="{{ old('name', $group->name) }}" placeholder="Sales" required>
                @error('name')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="extension">Extension <span class="muted">(optional)</span></label>
                <input id="extension" type="text" inputmode="numeric" name="extension" value="{{ old('extension', $group->extension) }}" placeholder="500" maxlength="5">
                <div class="small muted">A short number phones can dial, or transfer a call to, to ring this group.</div>
                @error('extension')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="grid">
                <div class="field">
                    <label for="strategy">How to ring</label>
                    <select id="strategy" name="strategy">
                        <option value="simultaneous" @selected(old('strategy', $group->strategy) === 'simultaneous')>Everyone at once</option>
                        <option value="in_order" @selected(old('strategy', $group->strategy) === 'in_order')>One after another, in order</option>
                    </select>
                </div>
                <div class="field">
                    <label for="ring_seconds">Ring for (seconds{{ ' each' }})</label>
                    <input id="ring_seconds" type="text" inputmode="numeric" name="ring_seconds" value="{{ old('ring_seconds', $group->ring_seconds) }}" required>
                    @error('ring_seconds')<div class="error">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Members</h2>
            <p class="small muted">Tick who should ring. For "in order", lower numbers ring first.</p>
            <table>
                <thead><tr><th>Ring</th><th>Person</th><th>Order</th></tr></thead>
                <tbody>
                @foreach ($users as $u)
                    @php($in = in_array($u->id, old('members', array_keys($positions))))
                    <tr>
                        <td><input type="checkbox" name="members[]" value="{{ $u->id }}" @checked($in) aria-label="Ring {{ $u->name }}"></td>
                        <td>{{ $u->name }} <span class="muted small">{{ $u->carrierIdentity() }}</span></td>
                        <td style="width:110px"><input type="text" inputmode="numeric" name="positions[{{ $u->id }}]" value="{{ old("positions.{$u->id}", $positions[$u->id] ?? $loop->index) }}" aria-label="Order for {{ $u->name }}"></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @error('members')<div class="error">{{ $message }}</div>@enderror
        </div>

        <div class="card">
            <div class="field">
                <label for="fallback">If nobody answers</label>
                @include('partials.destination-select', ['name' => 'fallback', 'options' => $options, 'selected' => old('fallback', $group->fallback), 'empty' => 'Play "unavailable" message and hang up'])
                @error('fallback')<div class="error">{{ $message }}</div>@enderror
            </div>
        </div>

        <button class="btn" type="submit">Save ring group</button>
    </form>
@endsection
