<?php

namespace App\Console\Commands;

use App\Models\OperatorAudit;
use App\Models\Tenant;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;

/** Hosted service only: stops (or restarts) a company's sign-ins and calls, keeping its data. */
class TenantSuspend extends Command
{
    protected $signature = 'tenant:suspend {slug} {--resume : Let them sign in and make calls again}';

    protected $description = '[Hosted service only] Suspend or resume a company';

    public function handle(Tenancy $tenancy): int
    {
        $tenant = TenantCommand::find($this, $tenancy, (string) $this->argument('slug'));
        if (! $tenant) {
            return self::FAILURE;
        }

        $resume = (bool) $this->option('resume');
        $tenant->forceFill(['status' => $resume ? Tenant::STATUS_ACTIVE : Tenant::STATUS_SUSPENDED])->save();
        OperatorAudit::record($resume ? 'company.resumed' : 'company.suspended', $tenant);
        $this->info($resume
            ? "{$tenant->name} is active again."
            : "{$tenant->name} is suspended: nobody can sign in, and its calls are refused except 999 and 112. Its data is kept.");

        return self::SUCCESS;
    }
}
