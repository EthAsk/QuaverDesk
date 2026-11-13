<?php

return [
    // The release this install is running: the VERSION file at the top of the repository, set at each release.
    'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: 'unknown',

    // The operator's own off switch for placing calls over the API, for every company (hosted service).
    'api_calls_enabled' => (bool) env('API_CALLS_ENABLED', true),

    // Self-hosted installs ask GitHub once a day whether a newer release exists, to show an "update available"
    // notice to admins. Only the request itself is sent (no data about you). Set to false to turn it off.
    'update_check' => (bool) env('QUAVERDESK_UPDATE_CHECK', true),
    'update_repo' => (string) env('QUAVERDESK_UPDATE_REPO', 'EthAsk/QuaverDesk'),

    // Backups made by deploy/backup.sh (see docs/DEPLOYMENT.md, step A8), shown on Admin → System.
    'backup' => [
        'command' => (string) env('QUAVERDESK_BACKUP_COMMAND', '/usr/local/sbin/quaverdesk-backup'),
        'dir' => (string) env('QUAVERDESK_BACKUP_DIR', '/var/backups/quaverdesk'),
        'status_file' => (string) env('QUAVERDESK_BACKUP_STATUS', '/var/lib/quaverdesk/backup-status.json'),
    ],

];
