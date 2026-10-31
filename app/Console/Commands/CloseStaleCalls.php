<?php

namespace App\Console\Commands;

use App\Models\Call;
use App\Support\CallLimits;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * Closes calls whose hang-up never arrived (the voice server restarted, or
 * the message was lost), so call history doesn't show them ringing forever.
 * Runs hourly. Their length is unknown, so they're recorded as 0 seconds.
 */
class CloseStaleCalls extends Command
{
    protected $signature = 'calls:close-stale';

    protected $description = 'Close calls that never reported a hang-up';

    public function handle(Tenancy $tenancy): int
    {
        $count = 0;

        $tenancy->each(function () use (&$count) {
            $oldest = now()->subMinutes(CallLimits::maxCallMinutes() + 5);

            Call::query()->whereNull('duration_seconds')->where('created_at', '<', $oldest)->chunkById(100, function ($calls) use (&$count) {
                foreach ($calls as $call) {
                    $call->forceFill([
                        'status' => in_array($call->status, ['initiated', 'ringing'], true) ? 'ended' : $call->status,
                        'duration_seconds' => 0,
                    ])->save();
                    $count++;
                }
            });
        });

        $this->info("Closed {$count} call(s) with no hang-up.");

        return self::SUCCESS;
    }
}
