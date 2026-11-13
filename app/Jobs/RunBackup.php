<?php

namespace App\Jobs;

use App\Support\Backups;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/** "Back up now" from Admin → System: runs the root-owned backup script (allowed by a sudoers rule that names only that script). */
class RunBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function handle(Backups $backups): void
    {
        try {
            $result = Process::timeout(840)->run(['sudo', '-n', (string) config('quaverdesk.backup.command')]);
            if ($result->successful()) {
                Cache::forget(Backups::ERROR);
            } else {
                Log::warning('Backup from the web page failed.', ['output' => substr($result->errorOutput().$result->output(), -500)]);
                Cache::put(Backups::ERROR, 'The backup could not run. Check that the sudoers rule is installed (docs/DEPLOYMENT.md, step A8) and /var/log/quaverdesk-backup.log.', now()->addDay());
            }
        } finally {
            $backups->release();
        }
    }
}
