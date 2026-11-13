<?php

namespace App\Console\Commands;

use App\Domain\Licensing\Feature;
use App\Mail\ReportEmail;
use App\Models\Setting;
use App\Models\User;
use App\Support\LicenceManager;
use App\Support\Reports;
use Carbon\CarbonImmutable;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/** Emails each company's admins last week's (Mondays) or last month's (the 1st) report, if they asked for it. Pro. */
class SendReports extends Command
{
    protected $signature = 'reports:send';

    protected $description = 'Email the weekly or monthly call report to companies that asked for it';

    public function handle(Tenancy $tenancy): int
    {
        $today = CarbonImmutable::now(Reports::TZ);

        $tenancy->each(function () use ($today) {
            $schedule = Setting::get('reports.schedule', 'off');
            if (! app(LicenceManager::class)->allows(Feature::QueueReports)) {
                return;
            }
            [$period, $from, $to] = match (true) {
                $schedule === 'weekly' && $today->isMonday() => ['weekly', $today->subWeek()->startOfWeek(), $today->subWeek()->endOfWeek()],
                $schedule === 'monthly' && $today->day === 1 => ['monthly', $today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
                default => [null, null, null],
            };
            if ($period === null) {
                return;
            }

            $report = new Reports($from, $to);
            $csv = fopen('php://temp', 'w+');
            fputcsv($csv, ['Started (UK time)', 'Direction', 'From', 'To', 'Caller name', 'Person', 'Status', 'Duration (seconds)']);
            foreach ($report->calls()->with('user:id,name')->orderBy('id')->cursor() as $c) {
                fputcsv($csv, array_map([Reports::class, 'cell'], [CarbonImmutable::parse($c->created_at)->timezone(Reports::TZ)->format('Y-m-d H:i:s'), $c->direction, $c->from, $c->to, $c->caller_name, $c->user?->name, $c->status, $c->duration_seconds]));
            }
            rewind($csv);
            $data = stream_get_contents($csv);
            fclose($csv);

            $label = $from->format('j M').' to '.$to->format('j M Y');
            $mail = new ReportEmail($period, $label, $report->overview()['summary'], $report->queues(), $data, route('admin.reports'));
            foreach (User::query()->whereIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN])->get()->filter->canSignIn() as $admin) {
                Mail::to($admin)->queue(clone $mail);
            }
        });

        return self::SUCCESS;
    }
}
