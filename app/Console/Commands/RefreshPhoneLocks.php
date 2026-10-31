<?php

namespace App\Console\Commands;

use App\Jobs\SyncVoiceServer;
use App\Voice\PhoneLocks;
use Illuminate\Console\Command;

/** Desk phones locked to a host name follow it when its address changes (dynamic DNS). */
class RefreshPhoneLocks extends Command
{
    protected $signature = 'phones:refresh-locks';

    protected $description = 'Look up the host names desk phones are locked to, and update the voice server if any moved';

    public function handle(PhoneLocks $locks): int
    {
        if ($locks->refresh()) {
            SyncVoiceServer::dispatch();
            $this->info('A host name has a new address: the voice server is being updated.');
        }

        return self::SUCCESS;
    }
}
