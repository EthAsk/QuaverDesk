<?php

namespace App\Console\Commands;

use App\Support\UpdateCheck;
use Illuminate\Console\Command;

class CheckUpdates extends Command
{
    protected $signature = 'quaverdesk:check-updates';

    protected $description = 'Ask whether a newer QuaverDesk release exists (self-hosted installs)';

    public function handle(UpdateCheck $updates): int
    {
        if (! $updates->enabled()) {
            $this->line('Update checks are off.');

            return self::SUCCESS;
        }
        $latest = $updates->refresh();
        $this->line($latest === null ? 'Could not find out.' : 'Latest release: '.$latest['version'].($updates->available() ? ' (newer than this install)' : ' (up to date)'));

        return self::SUCCESS;
    }
}
