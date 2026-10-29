@extends('layouts.app')
@section('title', 'Calling rules')
@section('content')
    <div class="page-head">
        <h1>Calling rules</h1>
        <p class="muted">Fraud protection. International and premium-rate calls are blocked unless you allow them here, and every outside call counts towards the limits below. Emergency numbers are never blocked by these rules.</p>
    </div>

    <form method="POST" action="{{ route('admin.calling-rules.update') }}" class="card" style="max-width:620px">
        @csrf @method('PUT')
        <div class="field">
            <label for="caller_id">Caller ID for outgoing calls</label>
            <select id="caller_id" name="caller_id">
                <option value="">Use the number set in .env (VOICE_CALLER_ID)</option>
                @foreach ($numbers as $n)
                    <option value="{{ $n->e164 }}" @selected(old('caller_id', $callerId) === $n->e164)>{{ $n->e164 }}{{ $n->label ? ' — '.$n->label : '' }}</option>
                @endforeach
            </select>
            <div class="small muted">It must be a number you own. When calls go through your own SIP trunk, pick a number on that trunk.</div>
            @error('caller_id')<div class="error">{{ $message }}</div>@enderror
        </div>
        <div class="field">
            <label for="prefixes">Allowed international country codes</label>
            <input id="prefixes" type="text" name="prefixes" value="{{ old('prefixes', implode(', ', $prefixes)) }}" placeholder="+353, +33">
            <div class="small muted">Separate with commas. Leave empty to block all international calls. Calls to the UK (+44) are always allowed.</div>
            @error('prefixes')<div class="error">{{ $message }}</div>@enderror
        </div>
        <label class="check">
            <input type="hidden" name="premium" value="0">
            <input type="checkbox" name="premium" value="1" @checked($premium)>
            <span>Allow calls to UK premium-rate numbers (09…). These can be very expensive and are a common fraud target.</span>
        </label>

        <h2 style="margin-top:20px">Limits</h2>
        <p class="small muted">Stops a stolen password or a hacked phone from running up a bill. Calls over a limit are refused, and admins get an email. Emergency calls and calls between colleagues are never limited.</p>
        <div class="limits">
            @foreach ([
                'calls_per_day' => ['Outside calls per person per day', ''],
                'minutes_per_day' => ['Outside minutes per person per day', ''],
                'max_call_minutes' => ['Longest outside call (minutes)', 'The phone system hangs up at this point.'],
                'concurrent' => ['Outside calls at once (whole system)', ($hosted = app(\App\Tenancy\Quotas::class)->tenant())
                    ? "Your plan allows up to {$hosted->concurrent_calls}, and {$hosted->monthly_minutes} minutes a month." : ''],
            ] as $key => [$label, $hint])
                <div class="field">
                    <label for="limit-{{ $key }}">{{ $label }}</label>
                    <input id="limit-{{ $key }}" type="text" inputmode="numeric" name="limits[{{ $key }}]" value="{{ old('limits.'.$key, $limits[$key]) }}" required>
                    @if ($hint)<div class="small muted">{{ $hint }}</div>@endif
                    @error('limits.'.$key)<div class="error">{{ $message }}</div>@enderror
                </div>
            @endforeach
        </div>

        <button class="btn" type="submit">Save rules</button>
    </form>
@endsection
