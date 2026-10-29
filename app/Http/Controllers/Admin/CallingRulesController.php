<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Support\CallLimits;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CallingRulesController extends Controller
{
    public function show(CallLimits $limits): View
    {
        return view('admin.calling-rules', [
            'limits' => $limits->settings(),
            'prefixes' => Setting::get('calling.international_prefixes', []),
            'premium' => Setting::get('calling.premium_allowed', false),
            'callerId' => Setting::get('calling.caller_id'),
            'numbers' => PhoneNumber::query()->orderBy('e164')->get(['e164', 'label']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'prefixes' => ['nullable', 'string', 'max:500'],
            'premium' => ['nullable', 'boolean'],
            'caller_id' => ['nullable', TenantRule::exists('phone_numbers', 'e164')],
            'limits.calls_per_day' => ['required', 'integer', 'min:1', 'max:5000'],
            'limits.minutes_per_day' => ['required', 'integer', 'min:1', 'max:10000'],
            'limits.max_call_minutes' => ['required', 'integer', 'min:1', 'max:480'],
            'limits.concurrent' => ['required', 'integer', 'min:1', 'max:200'],
        ]);

        $prefixes = collect(preg_split('/[\s,]+/', (string) ($data['prefixes'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($p) => '+'.ltrim($p, '+'))
            ->filter(fn ($p) => preg_match('/^\+[1-9]\d{0,3}$/', $p) && $p !== '+44')
            ->unique()->values()->all();

        Setting::put('calling.international_prefixes', $prefixes);
        Setting::put('calling.premium_allowed', (bool) ($data['premium'] ?? false));
        Setting::put('calling.caller_id', $data['caller_id'] ?? null);
        Setting::put('calling.limits', array_map('intval', $data['limits']));

        return back()->with('status', 'Calling rules saved.');
    }
}
