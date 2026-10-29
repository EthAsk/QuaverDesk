@extends('layouts.app')
@section('title', 'Licence')
@section('content')
    <div class="page-head">
        <h1>Licence</h1>
        <p class="muted">Paste the licence key you received after buying Plus or Pro.</p>
    </div>

    @if ($problem)
        <div class="flash bad">The installed key was rejected ({{ $problem }}). This install is running on the Free plan.</div>
    @endif

    <div class="card">
        <h2>Current plan: {{ $licence->plan->label() }}</h2>
        @unless ($licence->isFree())
            <p>Licensed to <strong>{{ $licence->licensee }}</strong> for {{ $licence->seats }} users.
                {{ $licence->expiresAt ? 'Expires '.$licence->expiresAt->format('j M Y').'.' : 'No expiry.' }}</p>
            @if ($renewalEnabled)
                <form method="POST" action="{{ route('admin.licence.refresh') }}" style="margin-bottom:8px">
                    @csrf
                    <button class="btn ghost" type="submit">Check for a renewed key</button>
                    <span class="small muted">Also checked automatically every day.</span>
                </form>
            @endif
            <form method="POST" action="{{ route('admin.licence.destroy') }}" data-confirm="Remove the licence and return to Free?">
                @csrf @method('DELETE')
                <button class="btn ghost" type="submit">Remove licence</button>
            </form>
        @endunless
    </div>

    <div class="card">
        <h2>{{ $licence->isFree() ? 'Add' : 'Replace' }} licence key</h2>
        <form method="POST" action="{{ route('admin.licence.update') }}">
            @csrf @method('PUT')
            <div class="field">
                <label for="key">Licence key</label>
                <textarea id="key" name="key" rows="4" spellcheck="false" required>{{ old('key') }}</textarea>
                @error('key')<div class="error">{{ $message }}</div>@enderror
            </div>
            <button class="btn" type="submit">Activate</button>
        </form>
        <p class="small muted" style="margin-top:12px">
            Removing, bypassing or tampering with licence checks is not permitted under the Elastic License 2.0.
        </p>
    </div>
@endsection
