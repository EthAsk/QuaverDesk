@extends('layouts.app')
@section('title', 'SIP trunks')
@section('content')
    <div class="page-head row" style="justify-content:space-between">
        <div>
            <h1>SIP trunks</h1>
            <p class="muted">Your phone lines: any UK SIP provider (or Twilio Elastic SIP Trunking) connects straight to this phone system's own voice server.</p>
        </div>
        <a class="btn" href="{{ route('admin.trunks.create') }}">Connect a trunk</a>
    </div>

    @error('trunk')<div class="flash bad">{{ $message }}</div>@enderror

    <div class="card">
        @if ($trunks->isEmpty())
            <p class="muted">No trunks connected yet. You need one to make or receive calls to phone numbers.</p>
        @else
            <table>
                <thead><tr><th>Name</th><th>Provider</th><th>Status</th><th>Numbers</th><th>Outgoing calls</th></tr></thead>
                <tbody>
                @foreach ($trunks as $t)
                    <tr>
                        <td><a href="{{ route('admin.trunks.show', $t) }}">{{ $t->name }}</a> @if ($t->supplied_by_operator)<span class="pill">Provided by {{ config('app.name') }}</span>@endif</td>
                        <td>{{ $providers[$t->provider]['name'] ?? $t->provider }}</td>
                        <td><span @class(['pill', 'warn' => $t->status !== 'active'])>{{ ucfirst($t->status) }}</span></td>
                        <td>{{ $t->numbers_count }}</td>
                        <td>{!! $t->default_outbound ? '<span class="pill">Default</span>' : '—' !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @if ($trunks->contains('default_outbound', true))
                <form method="POST" action="{{ route('admin.trunks.clear-default') }}" style="margin-top:12px">
                    @csrf @method('DELETE')
                    <button class="btn ghost" type="submit">Stop outgoing calls to phone numbers</button>
                </form>
            @endif
        @endif
    </div>
@endsection
