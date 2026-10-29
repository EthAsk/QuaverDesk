@extends('layouts.app')
@section('title', 'Plan')
@section('content')
    @php
        $usedMinutes = intdiv($quotas->outsideSecondsThisMonth(), 60);
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
                <tr><td>Minutes of calls to phone numbers this month</td><td>{{ number_format($usedMinutes) }}</td><td>{{ number_format($tenant->monthly_minutes) }}</td></tr>
                <tr><td>Calls to phone numbers at once</td><td></td><td>{{ $tenant->concurrent_calls }}</td></tr>
                <tr><td>Storage for recordings, voicemail and sounds</td><td>{{ $usedMb }} MB</td><td>{{ number_format($tenant->storage_mb) }} MB</td></tr>
            </tbody>
        </table>
        <p class="small muted">Minutes reset on the 1st of each month (UK time). Calls between colleagues and emergency calls are never counted or limited.
            When storage is full, new calls aren't recorded (voicemail still is) until older recordings expire or are deleted.</p>
    </div>
@endsection
