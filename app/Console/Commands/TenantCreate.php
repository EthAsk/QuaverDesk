<?php

namespace App\Console\Commands;

use App\Models\OperatorAudit;
use App\Models\Tenant;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/** Hosted service only: adds a company. Its owner is then created with user:create-owner --tenant. */
class TenantCreate extends Command
{
    protected $signature = 'tenant:create {slug : Subdomain, e.g. quaver for quaver.quaverdesk.app} {name : Company name} {--domain= : The company\'s own web address, if any}';

    protected $description = '[Hosted service only] Add a company';

    public function handle(Tenancy $tenancy): int
    {
        if (! $tenancy->enabled()) {
            $this->error('This install has one company. Companies can only be added when QUAVERDESK_MULTI_TENANT is on.');

            return self::FAILURE;
        }

        $data = [
            'slug' => strtolower((string) $this->argument('slug')),
            'name' => (string) $this->argument('name'),
            'domain' => $this->option('domain') ? strtolower((string) $this->option('domain')) : null,
        ];
        $validator = Validator::make($data, Tenant::rules(), Tenant::RULE_MESSAGES);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::INVALID;
        }

        $tenant = Tenant::query()->create($data);
        OperatorAudit::record('company.created', $tenant);
        $base = config('tenancy.base_domain') ?: 'TENANCY_BASE_DOMAIN';
        $this->info("Added {$tenant->name} at {$tenant->slug}.{$base}".($tenant->domain ? " and {$tenant->domain}" : '').'.');
        $this->line("Next: php artisan user:create-owner --tenant={$tenant->slug}");

        return self::SUCCESS;
    }
}
