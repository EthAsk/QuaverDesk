<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Licensing\InvalidLicence;
use App\Http\Controllers\Controller;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Support\LicenceManager;
use App\Support\LicenceRenewal;
use App\Tenancy\Quotas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LicenceController extends Controller
{
    public function show(LicenceManager $licences, Quotas $quotas): View
    {
        if ($licences->hosted()) {
            return view('admin.plan', ['tenant' => $quotas->tenant(), 'quotas' => $quotas, 'people' => User::query()->count(),
                'numbers' => PhoneNumber::query()->count()]);
        }

        return view('admin.licence', [
            'licence' => $licences->current(),
            'problem' => $licences->problem(),
            'renewalEnabled' => app(LicenceRenewal::class)->enabled(),
        ]);
    }

    public function update(Request $request, LicenceManager $licences): RedirectResponse
    {
        abort_if($licences->hosted(), 404);
        $request->validate(['key' => ['required', 'string', 'max:4096']]);

        try {
            $licence = $licences->install($request->string('key')->toString());
        } catch (InvalidLicence $e) {
            return back()->withErrors(['key' => $e->getMessage()]);
        }

        return back()->with('status', "{$licence->plan->label()} plan activated for {$licence->licensee}.");
    }

    public function refresh(LicenceRenewal $renewal, LicenceManager $licences): RedirectResponse
    {
        abort_if($licences->hosted(), 404);

        return back()->with('status', $renewal->check());
    }

    public function destroy(LicenceManager $licences): RedirectResponse
    {
        abort_if($licences->hosted(), 404);

        $licences->remove();

        return back()->with('status', 'Licence removed. This install is now on the Free plan.');
    }
}
