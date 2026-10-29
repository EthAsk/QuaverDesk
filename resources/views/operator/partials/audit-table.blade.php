<table>
    <thead><tr><th>When</th><th>Who</th><th>What</th><th>Company</th><th>Details</th><th>From</th></tr></thead>
    <tbody>
    @forelse ($entries as $e)
        <tr>
            <td>{{ $e->created_at->timezone('Europe/London')->format('j M Y H:i') }}</td>
            <td>{{ $e->operator?->name ?? ($e->operator_id ? 'Removed operator' : 'Command line') }}</td>
            <td><code>{{ $e->action }}</code></td>
            <td>{{ $e->tenant_name }}</td>
            <td class="small">{{ $e->details ? collect($e->details)->map(fn ($v, $k) => $k.': '.(is_scalar($v) ? $v : json_encode($v)))->implode(' · ') : '' }}</td>
            <td class="small">{{ $e->ip_address }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">Nothing yet.</td></tr>
    @endforelse
    </tbody>
</table>
