@extends('layouts.operator')
@section('title', $company->name)
@section('content')
    <div class="page-head">
        <h1>{{ $company->name }}</h1>
        <p class="muted">
            <a href="{{ $company->url() }}" rel="noopener">{{ parse_url($company->url(), PHP_URL_HOST) }}</a>
            · {!! $company->isActive() ? '<span class="pill">Active</span>' : '<span class="pill off">Suspended</span>' !!}
            · added {{ $company->created_at->format('j M Y') }}
        </p>
    </div>

    @if (session('invited'))
        <div class="flash ok">
            <strong>{{ session('invited')['email'] }}</strong> was emailed a link to set their password. If it doesn't arrive, send them this link another way. It won't be shown again:
            <code class="codes" style="margin-top:8px;white-space:normal;word-break:break-all">{{ session('invited')['link'] }}</code>
        </div>
    @endif

    <div class="card">
        <h2>Usage</h2>
        <p>{{ $usage['users'] ?? 0 }} people · {{ $usage['numbers'] ?? 0 }} numbers · {{ number_format($usage['recordings'] ?? 0) }} stored recordings</p>
        <table>
            <thead><tr><th>Minutes</th><th>On our trunks</th><th>On their own trunks</th><th>Total</th></tr></thead>
            <tbody>
            @foreach (['This month' => $usage, 'Last month' => $lastMonth] as $period => $u)
                @php($m = $u['minutes'] ?? ['in' => 0, 'in_ours' => 0, 'out' => 0, 'out_ours' => 0])
                <tr><td>{{ $period }}, inbound</td><td>{{ number_format($m['in_ours']) }}</td><td>{{ number_format($m['in'] - $m['in_ours']) }}</td><td>{{ number_format($m['in']) }}</td></tr>
                <tr><td>{{ $period }}, outbound</td><td>{{ number_format($m['out_ours']) }}</td><td>{{ number_format($m['out'] - $m['out_ours']) }}</td><td>{{ number_format($m['out']) }}</td></tr>
            @endforeach
            </tbody>
        </table>
        <p class="muted small">Inbound counts whole calls. Outbound counts connected calls including ringing time, so it runs slightly high; bill from the carrier's records.</p>
    </div>

    <form method="POST" action="{{ route('operator.companies.plan', $company) }}" class="card">
        @csrf @method('PUT')
        <h2>Plan</h2>
        <p class="muted small">
            This month: {{ number_format(intdiv($quota['outside'], 60)) }} of {{ number_format($company->monthly_minutes) }} minutes of calls to phone numbers ·
            {{ round($quota['storage'] / 1048576, 1) }} of {{ number_format($company->storage_mb) }} MB stored.
            When a company reaches a limit its admins are told; calls between colleagues and emergency calls are never limited.
        </p>
        <div class="limits">
            <div class="field">
                <label for="plan">Features</label>
                <select id="plan" name="plan">
                    @foreach (\App\Domain\Licensing\Plan::cases() as $p)
                        <option value="{{ $p->value }}" @selected(old('plan', $company->plan?->value) === $p->value)>{{ $p->label() }}</option>
                    @endforeach
                </select>
            </div>
            @foreach ([
                'seats' => 'People', 'max_numbers' => 'Phone numbers', 'monthly_minutes' => 'Outside minutes a month',
                'concurrent_calls' => 'Outside calls at once', 'storage_mb' => 'Storage (MB)',
            ] as $field => $label)
                <div class="field">
                    <label for="{{ $field }}">{{ $label }}</label>
                    <input id="{{ $field }}" name="{{ $field }}" type="text" inputmode="numeric" value="{{ old($field, $company->{$field}) }}" required>
                    @error($field)<div class="error">{{ $message }}</div>@enderror
                </div>
            @endforeach
        </div>
        <button class="btn" type="submit">Save plan</button>
    </form>

    <div class="card">
        <h2>Trunks</h2>
        <table>
            <thead><tr><th>Trunk</th><th>Provider</th><th>Status</th><th>Registers</th><th>Whose</th><th></th></tr></thead>
            <tbody>
            @forelse ($trunks as $t)
                <tr>
                    <td>{{ $t->name }}</td><td>{{ $t->provider }}</td><td>{{ ucfirst($t->status) }}</td><td>{{ $t->registers() ? 'Yes' : 'No' }}</td>
                    <td>{!! $t->supplied_by_operator ? '<span class="pill">Ours</span>' : '<span class="pill off">Theirs</span>' !!}</td>
                    <td>
                        <form method="POST" action="{{ route('operator.companies.trunk', [$company, $t->id]) }}">
                            @csrf
                            <input type="hidden" name="supplied" value="{{ $t->supplied_by_operator ? 0 : 1 }}">
                            <button class="btn ghost small" type="submit">{{ $t->supplied_by_operator ? 'Mark as theirs' : 'Mark as ours' }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No trunks.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>People</h2>
        <table>
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>2FA</th></tr></thead>
            <tbody>
            @foreach ($people as $p)
                <tr><td>{{ $p->name }}</td><td>{{ $p->email }}</td><td>{{ ucfirst($p->role) }}</td>
                    <td>{!! $p->hasTwoFactorEnabled() ? '<span class="pill">On</span>' : '<span class="pill off">Off</span>' !!}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if ($company->isActive() && $people->isNotEmpty())
        <form method="POST" action="{{ route('operator.companies.support', $company) }}" class="card">
            @csrf
            <h2>Support visit</h2>
            <p class="muted small">Signs you in as one of their people for up to {{ config('tenancy.support_minutes') }} minutes. Their owners are emailed who, why and when, and it's logged.</p>
            <div class="field">
                <label for="user_id">Sign in as</label>
                <select id="user_id" name="user_id">
                    @foreach ($people as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->role }})</option>@endforeach
                </select>
            </div>
            <div class="field">
                <label for="reason">Why</label>
                <input id="reason" name="reason" value="{{ old('reason') }}" required minlength="10" maxlength="500" placeholder="e.g. Ticket 1234: calls to the sales ring group aren't ringing">
                @error('reason')<div class="error">{{ $message }}</div>@enderror
            </div>
            <button class="btn" type="submit">Start support visit</button>
        </form>
    @endif

    <div class="card">
        <h2>Account</h2>
        <div class="row">
            <form method="POST" action="{{ route('operator.companies.status', $company) }}">
                @csrf
                @if ($company->isActive())
                    <input type="hidden" name="status" value="suspended">
                    <button class="btn ghost" type="submit">Suspend</button>
                @else
                    <input type="hidden" name="status" value="active">
                    <button class="btn" type="submit">Resume</button>
                @endif
            </form>
            <form method="POST" action="{{ route('operator.companies.export', $company) }}">
                @csrf
                <button class="btn ghost" type="submit">Download export</button>
            </form>
        </div>
        <p class="muted small">The export holds their records and call audio (no passwords or keys). Handle it as personal data.</p>

        @unless ($company->isActive())
            <form method="POST" action="{{ route('operator.companies.destroy', $company) }}" style="margin-top:16px">
                @csrf @method('DELETE')
                <h3>Delete permanently</h3>
                <p class="muted small">Removes everything they own. A final export is kept on the server.</p>
                <div class="field">
                    <label for="confirm">Type <code>{{ $company->slug }}</code> to confirm</label>
                    <input id="confirm" name="confirm" autocomplete="off" required style="max-width:14em">
                    @error('confirm')<div class="error">{{ $message }}</div>@enderror
                </div>
                <button class="btn danger" type="submit">Delete {{ $company->name }}</button>
            </form>
        @endunless
    </div>

    <div class="card">
        <h2>Support visits</h2>
        <table>
            <thead><tr><th>When</th><th>Who</th><th>Why</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($visits as $v)
                <tr><td>{{ $v->created_at->timezone('Europe/London')->format('j M H:i') }}</td><td>{{ $v->operator?->name }}</td><td>{{ $v->reason }}</td>
                    <td>{{ $v->started_at ? ($v->ends_at?->isFuture() ? 'In progress' : 'Ended') : 'Not started' }}</td></tr>
            @empty
                <tr><td colspan="4" class="muted">None.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Recent actions</h2>
        @include('operator.partials.audit-table', ['entries' => $audit])
    </div>
@endsection
