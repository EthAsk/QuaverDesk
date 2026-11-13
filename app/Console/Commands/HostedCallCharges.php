<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Tenancy\CallCharges;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Hosted service only: what each company's calls on top of its included minutes come to, for invoicing. */
class HostedCallCharges extends Command
{
    protected $signature = 'hosted:call-charges {--month= : YYYY-MM (default: last month)} {--all : Include partners\' clients (billed to the partner already)}';

    protected $description = '[Hosted service only] List calls charged on top of included minutes, per company';

    public function handle(): int
    {
        if (! CallCharges::enabled()) {
            $this->warn('Call charges are off (TENANCY_CALL_CHARGES=false): every call counts towards the included minutes.');

            return self::SUCCESS;
        }
        $month = $this->option('month')
            ? CarbonImmutable::createFromFormat('!Y-m', (string) $this->option('month'), 'Europe/London')
            : CarbonImmutable::now('Europe/London')->subMonthNoOverflow();
        if (! $month) {
            $this->error('Give the month as YYYY-MM.');

            return self::FAILURE;
        }

        $rows = [];
        $total = 0;
        $tenants = Tenant::query()->when(! $this->option('all'), fn ($q) => $q->whereNull('partner_id'))->orderBy('name')->get();
        foreach ($tenants as $tenant) {
            $usage = CallCharges::forCompany($tenant, $month);
            if (! $usage || $usage['total_pence'] === 0) {
                continue;
            }
            foreach ($usage['extra'] + $usage['other'] as $kind => $line) {
                $rows[] = [$tenant->slug, $tenant->partner_name ?? '', $kind, $line['minutes'], $line['pence_per_minute'].'p', '£'.number_format($line['pence'] / 100, 2)];
            }
            $total += $usage['total_pence'];
        }

        $this->info('Calls charged on top, '.$month->format('F Y').':');
        $rows === [] ? $this->line('None.') : $this->table(['Company', 'Partner', 'Calls', 'Minutes', 'Rate', 'Charge'], $rows);
        $this->line('Total: £'.number_format($total / 100, 2));

        return self::SUCCESS;
    }
}
