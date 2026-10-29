<?php

namespace App\Console\Commands;

use App\Jobs\SyncVoiceServer;
use App\Models\OperatorAudit;
use App\Models\Tenant;
use App\Tenancy\Tenancy;
use App\Tenancy\TenantData;
use Illuminate\Console\Command;

/**
 * Hosted service only: permanently deletes a company and everything it owns.
 * Two steps on purpose: the company must be suspended first, and an export is
 * made unless the operator explicitly skips it.
 */
class TenantDelete extends Command
{
    protected $signature = 'tenant:delete {slug} {--skip-export : Don\'t export first (only if you already have a copy)}';

    protected $description = '[Hosted service only] Permanently delete a suspended company and all its data';

    public function handle(Tenancy $tenancy, TenantData $data): int
    {
        $tenant = TenantCommand::find($this, $tenancy, (string) $this->argument('slug'));
        if (! $tenant) {
            return self::FAILURE;
        }
        if ($tenant->isActive()) {
            $this->error("Suspend {$tenant->name} first: php artisan tenant:suspend {$tenant->slug}");

            return self::FAILURE;
        }

        $this->warn("This permanently deletes {$tenant->name}: its people, calls, recordings, voicemail, numbers and settings.");
        if ($this->ask("Type the slug ({$tenant->slug}) to confirm") !== $tenant->slug) {
            $this->error('Not confirmed. Nothing was deleted.');

            return self::FAILURE;
        }

        if (! $this->option('skip-export')) {
            $this->info('Exported first to '.$data->export($tenant));
        }

        $name = $tenant->name;
        OperatorAudit::record('company.deleted', $tenant);
        $data->erase($tenant);
        SyncVoiceServer::dispatch(); // removes its phones and trunks from the voice server

        $this->info("{$name} and all its data have been deleted.");

        return self::SUCCESS;
    }
}
