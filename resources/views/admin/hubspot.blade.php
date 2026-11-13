@extends('layouts.app')
@section('title', 'HubSpot')
@section('content')
    <div class="page-head">
        <h1>HubSpot CRM</h1>
        <p class="muted">Log calls against the HubSpot contact with that phone number, with the AI summary and a link to the recording, and call contacts from HubSpot with one click.</p>
    </div>

    @error('test')<div class="flash bad">{{ $message }}</div>@enderror

    <form method="POST" action="{{ route('admin.hubspot.update') }}" class="card">
        @csrf @method('PUT')
        <h2>Connect</h2>
        <ol class="small">
            <li>In HubSpot: Settings → Integrations → Private Apps → Create a private app.</li>
            <li>Under Scopes add <code>crm.objects.contacts.read</code>, <code>crm.objects.contacts.write</code> (only if you want contacts made), <code>crm.objects.calls.read</code> and <code>crm.objects.calls.write</code> (older accounts: <code>crm.objects.contacts</code> and <code>timeline</code>).</li>
            <li>Create the app and paste its access token here.</li>
        </ol>
        <div class="field">
            <label for="token">Access token {!! $hasToken ? '<span class="pill">Saved</span>' : '' !!}</label>
            <input id="token" type="password" name="token" autocomplete="off" placeholder="{{ $hasToken ? 'Leave blank to keep the saved token' : 'pat-…' }}">
            @error('token')<div class="error">{{ $message }}</div>@enderror
        </div>

        <h2>What to log</h2>
        <label class="check"><input type="checkbox" name="log_calls" value="1" @checked(old('log_calls', $options['log_calls']))> Log incoming and outgoing calls (not calls between your own people)</label>
        <label class="check"><input type="checkbox" name="include_summary" value="1" @checked(old('include_summary', $options['include_summary']))> Add the AI summary, action items and sentiment</label>
        <label class="check"><input type="checkbox" name="include_recording_link" value="1" @checked(old('include_recording_link', $options['include_recording_link']))> Add a link to the recording or voicemail (people need to sign in here to open it)</label>
        <label class="check"><input type="checkbox" name="create_contacts" value="1" @checked(old('create_contacts', $options['create_contacts']))> Make a contact when the number isn't in HubSpot (otherwise those calls aren't logged)</label>
        <label class="check"><input type="checkbox" name="lookup_names" value="1" @checked(old('lookup_names', $options['lookup_names']))> Show who's calling: phones and call history show the HubSpot contact's name and company for incoming calls</label>
        <button class="btn" type="submit">Save</button>
    </form>

    <div class="row">
        <form method="POST" action="{{ route('admin.hubspot.test') }}">@csrf <button class="btn ghost" type="submit" @disabled(! $hasToken)>Test the connection</button></form>
        @if ($hasToken)
            <form method="POST" action="{{ route('admin.hubspot.forget') }}">@csrf @method('DELETE') <button class="btn ghost" type="submit">Remove token</button></form>
        @endif
    </div>

    @if ($last)
        <div class="card"><p class="small">Last HubSpot update: {{ \Illuminate\Support\Carbon::parse($last['at'])->format('j M H:i') }}, {{ $last['ok'] ? 'worked' : 'failed: '.$last['error'] }}.</p></div>
    @endif

    <div class="card">
        <h2>Click to call</h2>
        <p class="small">HubSpot shows phone numbers as <code>tel:</code> links. Each person can make this phone open them: sign in, then on the Phone page choose <em>Use for phone links</em>. Clicking a number in HubSpot then opens the phone with it filled in, ready to dial.</p>
        <div class="field"><div class="row"><span class="muted small">Do it for this browser now.</span><button type="button" class="btn ghost" data-tel-handler>Use for phone links</button></div></div>
    </div>
@endsection
