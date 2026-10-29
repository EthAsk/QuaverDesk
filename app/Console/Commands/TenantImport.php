<?php

namespace App\Console\Commands;

use App\Jobs\SyncVoiceServer;
use App\Models\OperatorAudit;
use App\Models\Tenant;
use App\Tenancy\Tenancy;
use App\Tenancy\TenantRestore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/** Hosted service only: restores one company from its export, leaving every other company as it is. */
class TenantImport extends Command
{
    protected $signature = 'tenant:import {file : A zip made by tenant:export or the operator console}
        {slug : The address name for the restored company}
        {--name= : Company name (default: the name in the export)}';

    protected $description = '[Hosted service only] Restore a company from its export';

    public function handle(Tenancy $tenancy, TenantRestore $restore): int
    {
        if (! $tenancy->enabled()) {
            $this->error('This install has one company. These commands are for the hosted service (QUAVERDESK_MULTI_TENANT).');

            return self::FAILURE;
        }

        $slug = strtolower((string) $this->argument('slug'));
        $validator = Validator::make(['slug' => $slug, 'name' => $this->option('name') ?? 'from export'], Tenant::rules(), Tenant::RULE_MESSAGES);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return 2;
        }

        try {
            ['tenant' => $tenant, 'counts' => $counts] = $restore->restore((string) $this->argument('file'), $slug, $this->option('name'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        SyncVoiceServer::dispatch(); // its phones and trunks
        OperatorAudit::record('company.restored', $tenant, ['file' => basename((string) $this->argument('file'))] + $counts);
        $this->info("Restored {$tenant->name} at {$tenant->url()}, suspended until you check it: tenant:suspend {$tenant->slug} --resume");
        $this->table(['Records', 'Count'], collect($counts)->map(fn ($n, $table) => [$table, $n])->values()->all());
        $this->warn('Exports hold no secrets, so: everyone sets a new password with "Forgot password" and turns two-factor on again;');
        $this->warn('trunks that sign in with a password must be added again; desk phones and apps need their new settings from the Phones and apps page;');
        $this->warn('an AI provider key must be entered again; check the plan with tenant:plan.');

        return self::SUCCESS;
    }
}
