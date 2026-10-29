@extends('layouts.operator')
@section('title', 'Companies')
@section('content')
    <div class="page-head">
        <h1>Companies</h1>
        <p class="muted">{{ $companies->count() }} on the hosted service. Minutes are outside calls this month; "ours" is calls on trunks we supply.</p>
        <a class="btn" href="{{ route('operator.companies.create') }}">Add a company</a>
    </div>
    <div class="card">
        <table>
            <thead><tr><th>Company</th><th>Address</th><th>Status</th><th>People</th><th>Numbers</th><th>Inbound min</th><th>Outbound min</th><th>Recordings</th></tr></thead>
            <tbody>
            @forelse ($companies as $c)
                <tr>
                    <td><a href="{{ route('operator.companies.show', $c) }}">{{ $c->name }}</a></td>
                    <td><code>{{ parse_url($c->url(), PHP_URL_HOST) }}</code></td>
                    <td>{!! $c->isActive() ? '<span class="pill">Active</span>' : '<span class="pill off">Suspended</span>' !!}</td>
                    <td>{{ $usage[$c->id]['users'] ?? 0 }}</td>
                    <td>{{ $usage[$c->id]['numbers'] ?? 0 }}</td>
                    @php($m = $usage[$c->id]['minutes'] ?? ['in' => 0, 'in_ours' => 0, 'out' => 0, 'out_ours' => 0])
                    <td>{{ number_format($m['in']) }} @if ($m['in_ours'])<span class="muted small">({{ number_format($m['in_ours']) }} ours)</span>@endif</td>
                    <td>{{ number_format($m['out']) }} @if ($m['out_ours'])<span class="muted small">({{ number_format($m['out_ours']) }} ours)</span>@endif</td>
                    <td>{{ number_format($usage[$c->id]['recordings'] ?? 0) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">No companies yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
