<?php

namespace App\Http\Controllers\Admin;

use App\Jobs\WarmPrompts;
use App\Domain\Licensing\Plan;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\LicenceManager;
use App\Support\RecordingPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RecordingSettingsController extends Controller
{
    public function show(RecordingPolicy $policy, LicenceManager $licences): View
    {
        return view('admin.recording-settings', [
            'outbound' => (bool) Setting::get('recording.outbound', false),
            'disclosure' => $policy->disclosure(),
            'retention' => $policy->retentionDays(),
            'canChooseRetention' => $licences->current()->plan === Plan::Pro,
            'autoAi' => $policy->autoAi(),
        ]);
    }

    public function update(Request $request, LicenceManager $licences): RedirectResponse
    {
        $data = $request->validate([
            'outbound' => ['nullable', 'boolean'],
            'disclosure' => ['required', 'string', 'min:20', 'max:300'],
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'auto_ai' => ['nullable', 'boolean'],
        ]);

        Setting::put('recording.outbound', $request->boolean('outbound'));
        Setting::put('recording.disclosure', trim($data['disclosure']));
        WarmPrompts::dispatch([trim($data['disclosure'])]);
        Setting::put('recording.auto_ai', $request->boolean('auto_ai'));

        if ($licences->current()->plan === Plan::Pro && isset($data['retention_days'])) {
            Setting::put('recording.retention_days', (int) $data['retention_days']);
        }

        return back()->with('status', 'Recording settings saved. New retention periods apply to new recordings.');
    }
}
