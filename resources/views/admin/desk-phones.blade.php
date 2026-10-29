@extends('layouts.app')
@section('title', 'Phones and apps')
@section('content')
    <div class="page-head">
        <h1>Phones and apps</h1>
        <p class="muted">Desk phones (for example Yealink, Poly, Grandstream) and mobile SIP apps. Each one rings together with its person's softphone.</p>
        <p class="small muted">The app must support TLS and SRTP. On iPhone, choose one with push notifications (for example Groundwire), or it only rings while it is open.</p>
    </div>

    @if (! $service)
        <div class="card" style="max-width:640px">
            <h2>Voice server not configured</h2>
            <p>Phones and apps sign in to this phone system's voice server. Set <code>VOICE_DOMAIN</code> (and the other <code>VOICE_*</code> settings) in <code>.env</code>, as described in docs/VOICE-SERVER.md, then reload this page.</p>
        </div>
    @else
        <div class="card">
            <h2>Phones</h2>
            <p class="small muted">Service address: <code>{{ $service['domain'] }}</code>. To call a colleague from a phone or app, dial * then their number, e.g. <strong>*12</strong> for user-12.</p>

            @if ($phones->isEmpty())
                <p class="muted">No phones yet.</p>
            @else
                <table>
                    <thead><tr><th>Phone</th><th>Person</th><th>Username</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($phones as $p)
                        <tr>
                            <td>{{ $p->label }}</td>
                            <td>{{ $p->user?->name }}</td>
                            <td><code>{{ $p->sip_username }}</code></td>
                            <td class="row">
                                <form method="POST" action="{{ route('admin.desk-phones.reveal', $p) }}">
                                    @csrf
                                    <button class="btn ghost" type="submit">Show settings</button>
                                </form>
                                <form method="POST" action="{{ route('admin.desk-phones.destroy', $p) }}" data-confirm="Remove {{ $p->label }}? It will stop working immediately.">
                                    @csrf @method('DELETE')
                                    <button class="btn ghost" type="submit">Remove</button>
                                </form>
                            </td>
                        </tr>
                        @if ($reveal === $p->id)
                            <tr>
                                <td colspan="4">
                                    <div class="flash warn" style="margin:0">
                                        <strong>Settings for {{ $p->label }}</strong> — enter these on the handset's account page. Keep the password private.
                                        <code class="codes" style="margin-top:8px">SIP server / registrar: {{ $service['domain'] }}
Transport:             TLS (port 5061)
Media encryption:      SRTP (compulsory)
Username:              {{ $p->sip_username }}
Auth ID:               {{ $p->sip_username }}
Password:              {{ $p->sip_password }}</code>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.desk-phones.store') }}" class="card" style="max-width:640px">
            @csrf
            <h2>Add a phone</h2>
            <div class="grid">
                <div class="field">
                    <label for="user_id">Person</label>
                    <select id="user_id" name="user_id">
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}" @selected(old('user_id') == $u->id)>{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="label">Name for the phone</label>
                    <input id="label" type="text" name="label" value="{{ old('label') }}" placeholder="Reception desk" required>
                    @error('label')<div class="error">{{ $message }}</div>@enderror
                </div>
            </div>
            <button class="btn" type="submit">Add phone</button>
        </form>
    @endif
@endsection
