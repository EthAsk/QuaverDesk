<?php

namespace App\Console\Commands;

use App\Models\Recording;
use App\Support\RecordingPolicy;
use App\Support\RecordingStore;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * Deletes recordings, voicemails and their transcripts once their retention
 * period ends. Runs daily. The period is the one in force now, so a change of
 * plan or setting applies to recordings already kept, not just new ones.
 */
class PruneRecordings extends Command
{
    protected $signature = 'recordings:prune';

    protected $description = 'Delete recordings and transcripts past their retention date';

    public function handle(RecordingStore $store, Tenancy $tenancy): int
    {
        $count = 0;

        $tenancy->each(function () use ($store, &$count) {
            $days = app(RecordingPolicy::class)->retentionDays();

            Recording::query()->whereNotNull('expires_at')->chunkById(100, function ($recordings) use ($store, $days, &$count) {
                foreach ($recordings as $recording) {
                    $expires = $recording->created_at->copy()->addDays($days);
                    if ($expires->isPast()) {
                        $store->deleteAudio($recording);
                        $recording->delete();
                        $count++;
                    } elseif (! $recording->expires_at->equalTo($expires)) {
                        $recording->forceFill(['expires_at' => $expires])->save();
                    }
                }
            });
        });

        $this->info("Deleted {$count} expired recording(s).");

        return self::SUCCESS;
    }
}
