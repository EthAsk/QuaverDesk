<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Licensing\Feature;
use App\Domain\Routing\WeeklySchedule;
use App\Domain\Telephony\Dialplan;
use App\Http\Controllers\Controller;
use App\Models\PhoneNumber;
use App\Models\SipTrunk;
use App\Rules\ValidDestination;
use App\Support\DestinationCatalog;
use App\Support\LicenceManager;
use App\Tenancy\Quotas;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class NumberController extends Controller
{
    public const DAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];

    public function index(DestinationCatalog $catalog): View
    {
        return view('admin.numbers.index', [
            'numbers' => PhoneNumber::query()->with('trunk')->orderBy('e164')->get(),
            'trunks' => SipTrunk::query()->where('status', 'active')->orderBy('name')->get(),
            'catalog' => $catalog,
        ]);
    }

    public function store(Request $request, Quotas $quotas): RedirectResponse
    {
        $data = $request->validate([
            'e164' => ['required', 'string', 'max:32'],
            'label' => ['nullable', 'string', 'max:100'],
            'sip_trunk_id' => ['required', 'integer', TenantRule::exists('sip_trunks')->where('status', 'active')],
        ], ['sip_trunk_id.exists' => 'Choose an active SIP trunk.']);

        $e164 = Dialplan::normaliseUk(preg_replace('/[\s().-]/', '', $data['e164']) ?? '');
        if ($e164 === null) {
            throw ValidationException::withMessages(['e164' => 'Enter a valid phone number, e.g. 020 7946 0000.']);
        }
        if (PhoneNumber::query()->where('e164', $e164)->exists()) {
            throw ValidationException::withMessages(['e164' => 'That number is already added.']);
        }
        if ($quotas->numbersFull()) {
            throw ValidationException::withMessages(['e164' => "Your plan includes {$quotas->tenant()->max_numbers} phone numbers. Ask your provider for more."]);
        }

        $number = PhoneNumber::create([
            'e164' => $e164,
            'label' => $data['label'] ?? null,
            'source' => 'trunk',
            'sip_trunk_id' => (int) $data['sip_trunk_id'],
            'destination' => 'hangup',
        ]);

        return redirect()->route('admin.numbers.edit', $number)->with('status', 'Number added. Now choose where its calls go.');
    }

    public function edit(PhoneNumber $number, DestinationCatalog $catalog): View
    {
        return view('admin.numbers.edit', [
            'number' => $number,
            'options' => $catalog->options(),
            'days' => self::DAYS,
            'hours' => $number->hours ?? ['days' => [], 'closed_dates' => []],
        ]);
    }

    public function update(Request $request, PhoneNumber $number): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
            'destination' => ['required', new ValidDestination()],
            'use_hours' => ['nullable', 'boolean'],
            'hours' => ['nullable', 'array'],
            'hours.*.open' => ['nullable', 'date_format:H:i'],
            'hours.*.close' => ['nullable', 'date_format:H:i'],
            'closed_dates' => ['nullable', 'string', 'max:2000'],
            'closed_destination' => ['nullable', 'required_if:use_hours,1', new ValidDestination()],
        ]);

        $hours = null;
        if ($request->boolean('use_hours')) {
            $days = [];
            foreach (array_keys(self::DAYS) as $day) {
                $open = $data['hours'][$day]['open'] ?? null;
                $close = $data['hours'][$day]['close'] ?? null;
                $days[$day] = ($open && $close) ? [[$open, $close]] : [];
            }
            $dates = preg_split('/[\s,]+/', (string) ($data['closed_dates'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);

            try {
                $hours = WeeklySchedule::fromArray([
                    'timezone' => 'Europe/London',
                    'days' => $days,
                    'closed_dates' => $dates,
                ])->toArray();
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['hours' => $e->getMessage().' (closing time must be after opening time)']);
            }
        }

        $number->update([
            'label' => $data['label'] ?? null,
            'destination' => $data['destination'],
            'hours' => $hours,
            'closed_destination' => $hours ? $data['closed_destination'] : null,
            // Only paid plans can switch recording on; the webhook re-checks the licence too.
            'record_calls' => $request->boolean('record_calls') && app(LicenceManager::class)->allows(Feature::Recording),
        ]);

        return redirect()->route('admin.numbers')->with('status', "Routing saved for {$number->e164}.");
    }

    public function destroy(PhoneNumber $number): RedirectResponse
    {
        $number->delete();

        return back()->with('status', "{$number->e164} removed from this phone system. It is still with your phone line provider.");
    }
}
