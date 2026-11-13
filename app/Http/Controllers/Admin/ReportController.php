<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Licensing\Feature;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\LicenceManager;
use App\Support\Reports;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function show(Request $request, LicenceManager $licences): View
    {
        $report = $this->report($request);
        $detailed = $licences->allows(Feature::QueueReports);

        return view('admin.reports', [
            'from' => $report->start->format('Y-m-d'), 'to' => $report->end->format('Y-m-d'),
            'overview' => $report->overview(),
            'queues' => $detailed ? $report->queues() : null,
            'agents' => $detailed ? $report->agents() : null,
            'serviceLevel' => Reports::SERVICE_LEVEL_SECONDS,
            'schedule' => Setting::get('reports.schedule', 'off'),
        ]);
    }

    public function schedule(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate(['schedule' => ['required', 'in:off,weekly,monthly']]);
        Setting::put('reports.schedule', $data['schedule']);

        return back()->with('status', $data['schedule'] === 'off' ? 'Emailed reports are off.' : "Admins will get the {$data['schedule']} report by email.");
    }

    /** Every call in the range as a spreadsheet. */
    public function calls(Request $request): StreamedResponse
    {
        $report = $this->report($request);
        $direction = $request->query('direction');
        $query = $report->calls()->with('user:id,name')->orderBy('id')
            ->when(in_array($direction, ['inbound', 'outbound', 'internal'], true), fn ($q) => $q->where('direction', $direction));

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Started (UK time)', 'Direction', 'From', 'To', 'Caller name', 'Person', 'Status', 'Duration (seconds)']);
            foreach ($query->cursor() as $call) {
                fputcsv($out, array_map([Reports::class, 'cell'], [
                    CarbonImmutable::parse($call->created_at)->timezone(Reports::TZ)->format('Y-m-d H:i:s'), $call->direction, $call->from, $call->to,
                    $call->caller_name, $call->user?->name, $call->status, $call->duration_seconds,
                ]));
            }
            fclose($out);
        }, 'calls-'.$report->start->format('Ymd').'-'.$report->end->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    private function report(Request $request): Reports
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $to = CarbonImmutable::parse($data['to'] ?? 'today', Reports::TZ);
        $from = CarbonImmutable::parse($data['from'] ?? $to->subDays(6), Reports::TZ);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) >= Reports::MAX_DAYS) {
            $from = $to->subDays(Reports::MAX_DAYS - 1);
        }

        return new Reports($from, $to);
    }
}
