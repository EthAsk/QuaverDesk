@extends('layouts.app')
@section('title', $trunk->name)
@section('content')
    <div class="page-head">
        <h1>{{ $trunk->name }}</h1>
        <p class="muted"><a href="{{ route('admin.trunks') }}">All trunks</a> · {{ $provider }}</p>
    </div>

    @if ($trunk->status === 'error')
        <div class="flash bad">The voice server could not use this trunk: {{ $trunk->last_error }}</div>
    @elseif (! session('status'))
        <div class="flash ok">Trunk saved. Send the details below to {{ $provider }} to finish.</div>
    @endif

    <div class="card">
        <h2>Send this to {{ $provider }}</h2>
        <table>
            <tbody>
            <tr><th>Send our incoming calls to</th><td>
                @if ($address)
                    <code>sip:{{ $address }}</code>
                @else
                    <span class="muted">This server's public address on port 5080 (set VOICE_TRUNK_ADDRESS to show it here).</span>
                @endif
            </td></tr>
            <tr><th>Allowed to send us calls</th><td>{{ collect($trunk->inbound_ips)->map(fn ($i) => $i['ip'].'/'.$i['prefix'])->implode(', ') }}</td></tr>
            <tr><th>We send outgoing calls to</th><td>{{ implode(', ', $trunk->origination_uris) }}</td></tr>
            <tr><th>Authentication</th><td>
                @if ($trunk->auth_username)
                    Username <code>{{ $trunk->auth_username }}</code>{{ $trunk->register ? ', registered' : '' }}
                @else
                    By IP address: ask {{ $provider }} to allow this server's public IP address.
                @endif
            </td></tr>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Next steps</h2>
        <ol>
            <li>Once {{ $provider }} confirms the change, add the trunk's numbers under <a href="{{ route('admin.numbers') }}">Phone numbers</a> and choose where their calls go.</li>
            <li>To make outgoing calls through this trunk, make it the default below, then pick one of its numbers as the caller ID under <a href="{{ route('admin.calling-rules') }}">Calling rules</a>.</li>
            <li>Test an incoming and an outgoing call, and confirm with {{ $provider }} how emergency (999) calls and caller location are handled on this trunk.</li>
        </ol>

        <div class="row">
            @if ($trunk->isActive() && ! $trunk->default_outbound)
                <form method="POST" action="{{ route('admin.trunks.default', $trunk) }}">
                    @csrf
                    <button class="btn" type="submit">Use for outgoing calls</button>
                </form>
            @elseif ($trunk->default_outbound)
                <span class="pill">Used for outgoing calls</span>
            @endif

            <a class="btn ghost" href="{{ route('admin.trunks.edit', $trunk) }}">Edit trunk</a>

            <form method="POST" action="{{ route('admin.trunks.destroy', $trunk) }}" data-confirm="Remove this trunk from this phone system? Calls on it will stop.">
                @csrf @method('DELETE')
                <button class="btn ghost" type="submit">Remove trunk</button>
            </form>
        </div>
    </div>
@endsection
