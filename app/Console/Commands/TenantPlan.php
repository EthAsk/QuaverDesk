<?php

namespace App\Console\Commands;

use App\Models\OperatorAudit;
use App\Models\Tenant;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/** Hosted service only: shows or changes what a company may use. */
class TenantPlan extends Command
{
    protected $signature = 'tenant:plan {slug}
        {--plan= : free, plus or pro (which features)}
        {--seats= : People}
        {--numbers= : Phone numbers}
        {--minutes= : Minutes of calls to phone numbers a month}
        {--concurrent= : Calls to phone numbers at once}
        {--storage= : Storage for recordings, voicemail and sounds, in MB}';

    protected $description = '[Hosted service only] Show or change a company\'s plan';

    private const OPTIONS = ['plan' => 'plan', 'seats' => 'seats', 'numbers' => 'max_numbers', 'minutes' => 'monthly_minutes',
        'concurrent' => 'concurrent_calls', 'storage' => 'storage_mb'];

    public function handle(Tenancy $tenancy): int
    {
        $tenant = TenantCommand::find($this, $tenancy, (string) $this->argument('slug'));
        if (! $tenant) {
            return self::FAILURE;
        }

        $changes = [];
        foreach (self::OPTIONS as $option => $field) {
            if ($this->option($option) !== null) {
                $changes[$field] = $this->option($option);
            }
        }

        if ($changes) {
            $validator = Validator::make($changes + $tenant->only(Tenant::PLAN_FIELDS), Tenant::planRules());
            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->error($error);
                }

                return 2;
            }
            $tenant->forceFill($changes)->save();
            OperatorAudit::record('company.plan', $tenant, $changes);
        }

        $this->table(['', ''], [
            ['Features', $tenant->plan->label()],
            ['People', $tenant->seats],
            ['Phone numbers', $tenant->max_numbers],
            ['Outside minutes a month', $tenant->monthly_minutes],
            ['Outside calls at once', $tenant->concurrent_calls],
            ['Storage (MB)', $tenant->storage_mb],
        ]);

        return self::SUCCESS;
    }
}
