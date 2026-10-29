<?php

namespace App\Console\Commands;

use App\Models\OperatorAudit;
use App\Tenancy\Tenancy;
use App\Tenancy\TenantData;
use Illuminate\Console\Command;

/** Hosted service only: a company's records and audio in one zip (data requests, backups, leaving). */
class TenantExport extends Command
{
    protected $signature = 'tenant:export {slug}';

    protected $description = '[Hosted service only] Export a company\'s data and audio to a zip';

    public function handle(Tenancy $tenancy, TenantData $data): int
    {
        $tenant = TenantCommand::find($this, $tenancy, (string) $this->argument('slug'));
        if (! $tenant) {
            return self::FAILURE;
        }

        $path = $data->export($tenant);
        OperatorAudit::record('company.exported', $tenant);
        $this->info("Exported {$tenant->name} to {$path}");
        $this->warn('It contains personal data and call audio. Send it securely and delete it when done.');

        return self::SUCCESS;
    }
}
