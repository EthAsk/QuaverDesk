<?php

namespace App\Console\Commands;

use App\Models\WebhookDelivery;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;

/** Webhook deliveries hold call details, so they're only kept for a week. */
class PruneWebhookDeliveries extends Command
{
    protected $signature = 'webhooks:prune';

    protected $description = 'Delete webhook deliveries older than a week';

    public function handle(Tenancy $tenancy): int
    {
        $tenancy->each(function () {
            WebhookDelivery::query()->where('created_at', '<', now()->subDays(WebhookDelivery::KEEP_DAYS))->delete();
        });

        return self::SUCCESS;
    }
}
