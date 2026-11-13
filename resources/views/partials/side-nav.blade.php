@php
    $user = auth()->user();
    $owner = $user->isOwner();
    $hosted = (bool) config('tenancy.enabled');

    // [label, route, active pattern, feature that unlocks it, plan badge]
    $adminGroups = [
        'Phone system' => [
            ['Phone numbers', 'admin.numbers', 'admin.numbers*'],
            ['SIP trunks', 'admin.trunks', 'admin.trunks*'],
            ['Calling rules', 'admin.calling-rules', 'admin.calling-rules'],
            ['Ring groups', 'admin.ring-groups', 'admin.ring-groups*'],
            ['IVR menus', 'admin.ivr', 'admin.ivr*'],
            ['Call queues', 'admin.queues', 'admin.queues*', 'call_queues', 'Plus'],
        ],
        'People & devices' => [
            ['Users', 'admin.users', 'admin.users*'],
            ['Phones and apps', 'admin.desk-phones', 'admin.desk-phones*', 'desk_phones', 'Plus'],
        ],
        'Voicemail & sounds' => [
            ['Voicemail boxes', 'admin.mailboxes', 'admin.mailboxes*'],
            ['Sounds & hold music', 'admin.sounds', 'admin.sounds'],
            ['Voice', 'admin.voice', 'admin.voice*'],
        ],
        'AI' => [
            ['AI receptionists', 'admin.receptionists', 'admin.receptionists*', 'ai_receptionist', 'Pro'],
            ['AI provider', 'admin.ai', 'admin.ai', 'ai_transcripts', 'Plus'],
        ],
        'Calls & reports' => [
            ['Call monitoring', 'admin.monitoring', 'admin.monitoring*', 'monitoring', 'Pro'],
            ['Recording', 'admin.recording-settings', 'admin.recording-settings', 'recording', 'Plus'],
            ['Reports', 'admin.reports', 'admin.reports*', 'reports', 'Plus'],
            ['Call logs', 'admin.call-logs', 'admin.call-logs'],
        ],
        'Integrations' => [
            ['Single sign-on', 'admin.sso', 'admin.sso*', 'sso', 'Pro'],
            ['HubSpot', 'admin.hubspot', 'admin.hubspot*', 'hubspot', 'Pro'],
            ['API', 'admin.api', 'admin.api*', 'api', 'Plus'],
            ['Webhooks', 'admin.webhooks', 'admin.webhooks*', 'webhooks', 'Plus'],
        ],
        'Account' => array_values(array_filter([
            $owner && ! $hosted ? ['System', 'admin.system', 'admin.system*'] : null,
            $owner ? [$hosted ? 'Plan' : 'Licence', 'admin.licence', 'admin.licence'] : null,
            ['Setup & terms', 'setup.acknowledge', 'setup.*'],
        ])),
    ];
    // Pages that installed modules add to a group.
    $modules = app(\App\Support\Modules::class);
    foreach ($adminGroups as $title => $items) {
        $adminGroups[$title] = [...$items, ...$modules->adminNavFor($title)];
    }
    $licences = app(\App\Support\LicenceManager::class);
    $locked = fn (?string $feature) => $feature !== null && ! $licences->allowsKey($feature);
@endphp

<a href="{{ route('dashboard') }}" @class(['on' => request()->routeIs('dashboard')])>Home</a>
<a href="{{ route('softphone') }}" @class(['on' => request()->routeIs('softphone')])>Phone</a>
@feature('live_wallboard')
    <a href="{{ route('wallboard') }}" @class(['on' => request()->routeIs('wallboard')])>Wallboard</a>
@endfeature
<a href="{{ route('calls') }}" @class(['on' => request()->routeIs('calls')])>Call history</a>
<a href="{{ route('recordings') }}" @class(['on' => request()->routeIs('recordings*')])>Voicemail &amp; recordings</a>
<a href="{{ route('security') }}" @class(['on' => request()->routeIs('security')])>Security</a>

@if ($user->isAdmin())
    <div class="group">Admin</div>
    <a href="{{ route('admin.overview') }}" @class(['on' => request()->routeIs('admin.overview')])>Overview</a>
    @foreach ($adminGroups as $title => $items)
        @php $open = collect($items)->contains(fn ($i) => request()->routeIs($i[2])); @endphp
        <details class="nav-group" @if ($open) open @endif>
            <summary>{{ $title }}</summary>
            @foreach ($items as $i)
                <a href="{{ route($i[1]) }}" @class(['on' => request()->routeIs($i[2])])>{{ $i[0] }}@if ($locked($i[3] ?? null)) <span class="lock">{{ $i[4] }}</span>@endif</a>
            @endforeach
        </details>
    @endforeach
@endif
