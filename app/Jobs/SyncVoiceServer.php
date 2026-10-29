<?php

namespace App\Jobs;

use App\Voice\VoiceServer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Pushes phones, trunks and allow-lists to the voice server after they change. */
class SyncVoiceServer implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120, 600];

    public int $uniqueFor = 60;

    public function handle(VoiceServer $voice): void
    {
        $voice->sync();
    }
}
