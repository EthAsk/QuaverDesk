<!DOCTYPE html>
<html lang="en-GB">
<body style="font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color:#14213d; line-height:1.5; max-width:560px; margin:0 auto; padding:24px">
    <h2 style="margin:0 0 12px">Your {{ $period }} call report</h2>
    <p style="color:#5d6578;margin-top:0">{{ $label }} (UK time)</p>
    <table style="border-collapse:collapse;margin-bottom:16px">
        <tr><td style="padding:4px 16px 4px 0;color:#5d6578">Incoming calls</td><td>{{ $summary['inbound'] }}</td></tr>
        <tr><td style="padding:4px 16px 4px 0;color:#5d6578">Outgoing calls</td><td>{{ $summary['outbound'] }}</td></tr>
        <tr><td style="padding:4px 16px 4px 0;color:#5d6578">Missed incoming</td><td>{{ $summary['missed'] }}</td></tr>
        <tr><td style="padding:4px 16px 4px 0;color:#5d6578">Answered share</td><td>{{ $summary['answer_rate'] === null ? '—' : $summary['answer_rate'].'%' }}</td></tr>
        <tr><td style="padding:4px 16px 4px 0;color:#5d6578">Talk time</td><td>{{ intdiv($summary['talk_seconds'], 3600) }}h {{ intdiv($summary['talk_seconds'] % 3600, 60) }}m</td></tr>
    </table>
    @if ($queues)
        <h3 style="margin:16px 0 8px">Queues</h3>
        <table style="border-collapse:collapse;margin-bottom:16px">
            <tr style="color:#5d6578;text-align:left"><th style="padding:4px 12px 4px 0">Queue</th><th style="padding:4px 12px 4px 0">Offered</th><th style="padding:4px 12px 4px 0">Answered</th><th style="padding:4px 12px 4px 0">Abandoned</th><th>Service level</th></tr>
            @foreach ($queues as $q)
                <tr><td style="padding:4px 12px 4px 0">{{ $q['name'] }}</td><td>{{ $q['offered'] }}</td><td>{{ $q['answered'] }}</td><td>{{ $q['abandoned'] }}</td><td>{{ $q['service_level'] === null ? '—' : $q['service_level'].'%' }}</td></tr>
            @endforeach
        </table>
    @endif
    <p>Every call in the period is attached as <code>calls.csv</code>. The full report is on the <a href="{{ $link }}" style="color:#0f766e">Reports page</a>.</p>
    <p style="color:#5d6578;font-size:13px">You get this because you're an admin. Turn it off on the Reports page.</p>
</body>
</html>
