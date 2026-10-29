<?php

namespace App\Console\Commands;

use App\Support\LicenceRenewal;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;

class LicenceRefresh extends Command
{
    protected $signature = 'licence:refresh';

    protected $description = 'Fetch a renewed licence key from the licence server, if one is configured';

    public function handle(LicenceRenewal $renewal, Tenancy $tenancy): int
    {
        if ($tenancy->enabled()) {
            $this->info('The hosted service uses each company\'s plan, not licence keys.');

            return self::SUCCESS;
        }
        $tenancy->each(fn () => $this->info($renewal->check()));

        return self::SUCCESS;
    }
}
