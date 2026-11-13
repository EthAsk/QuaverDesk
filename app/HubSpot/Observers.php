<?php

namespace App\HubSpot;

use App\Domain\Licensing\Feature;
use App\Jobs\SyncHubSpotCall;
use App\Models\Call;
use App\Models\Recording;
use App\Support\LicenceManager;
use App\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** Queues a HubSpot update when a call ends, or when its recording or AI summary is ready. */
final class Observers
{
    public static function register(): void
    {
        Call::updated(function (Call $call) {
            if ($call->wasChanged('duration_seconds') && $call->getOriginal('duration_seconds') === null) {
                self::queue($call, $call->id);
            }
        });

        Recording::updated(function (Recording $recording) {
            $ready = ($recording->wasChanged('status') && $recording->status === 'stored')
                || ($recording->wasChanged('ai_status') && $recording->ai_status === 'done');
            if ($recording->call_id && $ready) {
                self::queue($recording, $recording->call_id);
            }
        });
    }

    private static function queue(Model $source, int $callId): void
    {
        try {
            $tenancy = app(Tenancy::class);
            $send = function () use ($callId) {
                if (app(LicenceManager::class)->allows(Feature::HubSpot) && app(HubSpotSettings::class)->active()) {
                    SyncHubSpotCall::dispatch($callId);
                }
            };
            // The voice server logs calls with every company in reach: act as the call's own company.
            if ($tenancy->enabled() && ($tenancy->isEverywhere() || $tenancy->id() !== (int) $source->tenant_id)) {
                $tenancy->run($source->tenant, $send);
            } else {
                $send();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
