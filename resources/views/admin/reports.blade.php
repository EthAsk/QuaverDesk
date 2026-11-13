@extends('layouts.app')
@section('title', 'Reports')
@section('content')
    @php
        $s = $overview['summary'];
        $fmt = fn (int $sec) => $sec >= 3600 ? intdiv($sec, 3600).'h '.intdiv($sec % 3600, 60).'m' : intdiv($sec, 60).'m '.($sec % 60).'s';
        $maxDay = max(1, ...array_values(array_map(fn ($d) => $d['inbound'] + $d['outbound'], $overview['days'])));
        $maxHour = max(1, ...array_values($overview['hours']));
    @endphp
    <div class="page-head">
        <h1>Reports</h1>
        <p class="muted">UK time. Calls between your own people are counted separately and left out of the daily and hourly charts.</p>
    </div>

    <form method="GET" class="card row">
        <div class="field"><label for="from">From</label><input id="from" type="date" name="from" value="{{ $from }}"></div>
        <div class="field"><label for="to">To</label><input id="to" type="date" name="to" value="{{ $to }}"></div>
        <button class="btn" type="submit">Show</button>
        <a class="btn ghost" href="{{ route('admin.reports.calls', ['from' => $from, 'to' => $to]) }}">Download calls (CSV)</a>
    </form>

    <div class="card">
        <h2>Summary</h2>
        <table class="small">
            <tr><td>Incoming calls</td><td>{{ $s['inbound'] }}</td><td>Outgoing calls</td><td>{{ $s['outbound'] }}</td><td>Between your people</td><td>{{ $s['internal'] }}</td></tr>
            <tr><td>Missed incoming</td><td>{{ $s['missed'] }}</td><td>Answered share</td><td>{{ $s['answer_rate'] === null ? '—' : $s['answer_rate'].'%' }}</td><td>Average call</td><td>{{ $fmt($s['avg_seconds']) }}</td></tr>
            <tr><td>Talk time</td><td colspan="5">{{ $fmt($s['talk_seconds']) }}</td></tr>
        </table>
    </div>

    <div class="card">
        <h2>By day</h2>
        <table class="small">
            @foreach ($overview['days'] as $day => $d)
                <tr>
                    <td style="white-space:nowrap">{{ \Carbon\CarbonImmutable::parse($day)->format('D j M') }}</td>
                    <td style="width:100%"><div style="background:var(--accent,#0f766e);height:10px;width:{{ round(100 * ($d['inbound'] + $d['outbound']) / $maxDay) }}%;min-width:{{ ($d['inbound'] + $d['outbound']) ? 2 : 0 }}px"></div></td>
                    <td style="white-space:nowrap">{{ $d['inbound'] }} in · {{ $d['outbound'] }} out · {{ $d['missed'] }} missed</td>
                </tr>
            @endforeach
        </table>
    </div>

    <div class="card">
        <h2>Incoming calls by hour</h2>
        <table class="small">
            @foreach ($overview['hours'] as $h => $n)
                <tr>
                    <td>{{ sprintf('%02d:00', $h) }}</td>
                    <td style="width:100%"><div style="background:var(--accent,#0f766e);height:10px;width:{{ round(100 * $n / $maxHour) }}%;min-width:{{ $n ? 2 : 0 }}px"></div></td>
                    <td>{{ $n }}</td>
                </tr>
            @endforeach
        </table>
    </div>

    @if ($queues === null)
        <div class="card"><h2>Queue and agent reports <span class="lock">Pro</span></h2><p class="small muted">Answered, abandoned and overflowed calls, waiting times and service level for each queue, and what each person handled, are on the Pro plan.</p></div>
    @else
        <div class="card">
            <h2>Queues</h2>
            <p class="small muted">Waiting is from joining to being answered or giving up, so it includes the time phones rang. Service level is the share of callers answered within {{ $serviceLevel }} seconds.</p>
            <table class="small">
                <tr><th>Queue</th><th>Offered</th><th>Answered</th><th>Abandoned</th><th>Overflowed</th><th>Average wait</th><th>Longest wait</th><th>Service level</th></tr>
                @forelse ($queues as $q)
                    <tr><td>{{ $q['name'] }}</td><td>{{ $q['offered'] }}</td><td>{{ $q['answered'] }}</td><td>{{ $q['abandoned'] }}</td><td>{{ $q['overflowed'] }}</td><td>{{ $fmt($q['avg_wait']) }}</td><td>{{ $fmt($q['longest_wait']) }}</td><td>{{ $q['service_level'] === null ? '—' : $q['service_level'].'%' }}</td></tr>
                @empty
                    <tr><td colspan="8" class="muted">No queues.</td></tr>
                @endforelse
            </table>
        </div>
        <div class="card">
            <h2>People</h2>
            <table class="small">
                <tr><th>Person</th><th>Answered</th><th>Made</th><th>Missed</th><th>Talk time</th></tr>
                @forelse ($agents as $a)
                    <tr><td>{{ $a['name'] }}</td><td>{{ $a['taken'] }}</td><td>{{ $a['made'] }}</td><td>{{ $a['missed'] }}</td><td>{{ $fmt($a['talk_seconds']) }}</td></tr>
                @empty
                    <tr><td colspan="5" class="muted">No calls in this range.</td></tr>
                @endforelse
            </table>
        </div>
        <form method="POST" action="{{ route('admin.reports.schedule') }}" class="card row">
            @csrf
            <div class="field"><label for="schedule">Email this report to admins</label>
                <select id="schedule" name="schedule">
                    <option value="off" @selected($schedule === 'off')>Don't</option>
                    <option value="weekly" @selected($schedule === 'weekly')>Every Monday (last week)</option>
                    <option value="monthly" @selected($schedule === 'monthly')>On the 1st (last month)</option>
                </select></div>
            <button class="btn ghost" type="submit">Save</button>
        </form>
    @endif
@endsection
