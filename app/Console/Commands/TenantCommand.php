<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;

/** Shared checks for the operator's company commands. */
final class TenantCommand
{
    public static function find(Command $command, Tenancy $tenancy, string $slug): ?Tenant
    {
        if (! $tenancy->enabled()) {
            $command->error('This install has one company. These commands are for the hosted service (QUAVERDESK_MULTI_TENANT).');

            return null;
        }

        $tenant = Tenant::query()->firstWhere('slug', strtolower($slug));
        if (! $tenant) {
            $command->error("No company with the slug \"{$slug}\".");
        }

        return $tenant;
    }
}
