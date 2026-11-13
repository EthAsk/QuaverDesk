@extends('layouts.app')
@section('title', 'Plan')
@section('content')
    @php
        $usedMinutes = intdiv($quotas->outsideSecondsThisMonth(), 60);
        $charges = \App\Tenancy\CallCharges::enabled() ? \App\Tenancy\CallCharges::usage($tenant->monthly_minutes) : null;
        $usedMb = round($quotas->storageBytes() / 1048576, 1);
    @endphp
    <div class="page-head">
        <h1>Plan</h1>
        <p class="muted">Your plan is set by your QuaverDesk provider. Contact them to change it.</p>
    </div>

    <div class="card">
        <h2>{{ $tenant->plan->label() }}</h2>
        <table>
            <thead><tr><th>Allowance</th><th>Used</th><th>Included</th></tr></thead>
            <tbody>
                <tr><td>People</td><td>{{ $people }}</td><td>{{ $tenant->licence()->maxUsers() }}</td></tr>
                <tr><td>Phone numbers</td><td>{{ $numbers }}</td><td>{{ $tenant->max_numbers }}</td></tr>
                <tr><td>Minutes of UK calls this month</td><td>{{ number_format($usedMinutes) }}</td><td>{{ number_format($tenant->monthly_minutes) }}</td></tr>
                <tr><td>Calls to phone numbers at once</td><td></td><td>{{ $tenant->concurrent_calls }}</td></tr>
                <tr><td>Storage for recordings, voicemail and sounds</td><td>{{ $usedMb }} MB</td><td>{{ number_format($tenant->storage_mb) }} MB</td></tr>
            </tbody>
        </table>
        @if ($charges)
            <h3>Calls charged on top this month</h3>
            <table>
                <tbody>
                @forelse ($charges['extra'] + $charges['other'] as $kind => $line)
                    <tr><td>{{ match ($kind) { 'inbound' => 'Extra incoming UK minutes', 'landline' => 'Extra minutes to UK landlines', 'mobile' => 'Extra minutes to UK mobiles', default => 'Calls to '.$kind.'…' } }}</td>
                        <td>{{ number_format($line['minutes']) }} min at {{ rtrim(rtrim(number_format($line['pence_per_minute'], 2), '0'), '.') }}p</td>
                        <td>£{{ number_format($line['pence'] / 100, 2) }}</td></tr>
                @empty
                    <tr><td class="muted">None so far.</td></tr>
                @endforelse
                </tbody>
            </table>
            <p class="small muted">Your minutes cover UK calls: incoming, and outgoing to UK landlines (01, 02, 03, 080) and mobiles. Past them, UK calls carry on
                (up to {{ number_format(intdiv(\App\Tenancy\CallCharges::overageSeconds($tenant->monthly_minutes), 60)) }} extra minutes) and are charged per minute.
                International calls and other numbers never use your minutes and are charged per minute.
                Only calls on phone lines your provider supplies count: calls through your own phone provider's lines use no minutes and aren't charged.</p>
        @endif
        <p class="small muted">Minutes reset on the 1st of each month (UK time). Calls between colleagues and emergency calls are never counted or limited.
            When storage is full, new calls aren't recorded (voicemail still is) until older recordings expire or are deleted.</p>
    </div>
@endsection
