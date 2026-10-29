<?php

namespace App\Console\Commands;

use App\Voice\VoiceServer;
use Illuminate\Console\Command;
use Throwable;

/** Writes phones, trunks and allow-lists for the voice server and reloads it. Run after deploys and VOICE_* changes. */
class VoiceSync extends Command
{
    protected $signature = 'voice:sync';

    protected $description = 'Write the voice server (FreeSWITCH) config this app owns and reload it';

    public function handle(VoiceServer $voice): int
    {
        if (! config('voip.freeswitch.conf_dir') || ! $voice->domain()) {
            $this->error('Set VOICE_DOMAIN and VOICE_CONF_DIR in .env first.');

            return self::FAILURE;
        }

        try {
            $voice->sync();
        } catch (Throwable $e) {
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Voice server config written to '.config('voip.freeswitch.conf_dir').'.');

        return self::SUCCESS;
    }
}
