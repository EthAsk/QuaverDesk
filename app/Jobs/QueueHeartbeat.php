<?php

namespace App\Jobs;

use App\Support\Health;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** Left on the queue every few minutes: if it runs, the worker is alive (see Health). */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::forever(Health::QUEUE, now()->toIso8601String());
    }
}
