@extends('layouts.app')
@section('title', 'System')
@section('content')
    <div class="page-head">
        <h1>System</h1>
        <p class="muted">The state of this server: updates, health checks and backups.</p>
    </div>

    <div class="card">
        <h2>Version</h2>
        <p>You're running QuaverDesk <strong>{{ $version }}</strong>.
            @if ($updateAvailable)
                <span class="pill warn">{{ $updateAvailable['version'] }} is available</span>
            @elseif ($latest)
                <span class="pill">Up to date</span>
            @endif
        </p>
        @if ($updateAvailable)
            <p class="small">Read <a href="{{ $updateAvailable['url'] }}" rel="noopener noreferrer" target="_blank">what changed</a> (anything to do by hand is listed there), then on the server run <code>sudo quaverdesk-deploy</code>. It takes a backup first and checks everything is healthy afterwards. Updating is done on the server, never from this page.</p>
        @endif
        @if ($updateChecks)
            <form method="POST" action="{{ route('admin.system.check-updates') }}" class="row">
                @csrf <button class="btn ghost small" type="submit">Check for updates now</button>
                <span class="small muted">{{ $latest && isset($latest['checked_at']) ? 'Last checked '.\Illuminate\Support\Carbon::parse($latest['checked_at'])->diffForHumans().'. ' : '' }}Once a day this asks GitHub for the latest release; nothing about you is sent. Turn it off with <code>QUAVERDESK_UPDATE_CHECK=false</code> in <code>.env</code>.</span>
            </form>
        @else
            <p class="small muted">Update checks are turned off.</p>
        @endif
    </div>

    <div class="card">
        <h2>Health</h2>
        <table class="small">
            @foreach ($health as $h)
                <tr>
                    <td><span @class(['pill', 'warn' => $h['status'] === 'warn', 'off' => $h['status'] === 'fail'])>{{ ['ok' => 'OK', 'warn' => 'Check', 'fail' => 'Problem'][$h['status']] }}</span></td>
                    <td><strong>{{ $h['name'] }}</strong></td>
                    <td>{{ $h['detail'] }}</td>
                </tr>
            @endforeach
        </table>
        <p class="small muted">The same checks run after every <code>quaverdesk-deploy</code>, and from the server with <code>php artisan quaverdesk:health</code>. Reload this page to run them again.</p>
    </div>

    <div class="card">
        <h2>Backups</h2>
        @if (! $backupsReady)
            <p class="small">Backups aren't set up on this server. Without them a failed disk loses everything, recordings included. Set them up in <code>docs/DEPLOYMENT.md</code>, step A8.</p>
        @else
            @if ($backup)
                <p class="small">
                    <span @class(['pill', 'off' => ! $backup['ok']])>{{ $backup['ok'] ? 'Last backup worked' : 'Last backup failed' }}</span>
                    {{ \Illuminate\Support\Carbon::parse($backup['at'])->timezone('Europe/London')->format('j M Y H:i') }}
                    @if ($backup['ok'] && $backup['size']) · {{ number_format($backup['size'] / 1048576, 1) }} MB @endif
                    @unless ($backup['ok']) · {{ $backup['message'] }} @endunless
                </p>
            @else
                <p class="small">No backup has been made yet.</p>
            @endif
            @if ($backupError)<div class="flash bad">{{ $backupError }}</div>@endif
            <form method="POST" action="{{ route('admin.system.backup') }}" class="row">
                @csrf
                <button class="btn" type="submit" @disabled($backupRunning)>{{ $backupRunning ? 'Backing up…' : 'Back up now' }}</button>
                <span class="small muted">A backup runs every night too. It holds the database, your settings and key, and the encrypted recordings.</span>
            </form>
            <p class="small muted"><strong>Keep a copy somewhere else.</strong> A backup on the same disk isn't a backup. Download them here or copy <code>/var/backups/quaverdesk</code> off the server. They're encrypted so only your backup private key (kept off the server) can open them. <strong>Restoring is done on the server</strong>, never from this page: see <code>docs/DEPLOYMENT.md</code>.</p>
            @if ($backupFiles)
                <table class="small">
                    @foreach ($backupFiles as $f)
                        <tr>
                            <td>{{ \Illuminate\Support\Carbon::createFromTimestamp($f['at'])->timezone('Europe/London')->format('j M Y H:i') }}</td>
                            <td>{{ number_format($f['size'] / 1048576, 1) }} MB</td>
                            <td><a href="{{ route('admin.system.backup.download', $f['name']) }}" data-turbo="false">Download</a></td>
                        </tr>
                    @endforeach
                </table>
            @endif
        @endif
    </div>
@endsection
