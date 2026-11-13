<?php

namespace App\Http\Controllers\Admin;

use App\HubSpot\HubSpotClient;
use App\HubSpot\HubSpotError;
use App\HubSpot\HubSpotSettings;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class HubSpotController extends Controller
{
    public function show(HubSpotSettings $settings): View
    {
        $all = $settings->all();

        return view('admin.hubspot', ['options' => $all, 'hasToken' => $all['token'] !== null, 'last' => Setting::get('hubspot_last')]);
    }

    public function update(Request $request, HubSpotSettings $settings): RedirectResponse
    {
        $data = $request->validate(['token' => ['nullable', 'string', 'max:500']]);
        $settings->save([
            'log_calls' => $request->boolean('log_calls'),
            'include_summary' => $request->boolean('include_summary'),
            'include_recording_link' => $request->boolean('include_recording_link'),
            'create_contacts' => $request->boolean('create_contacts'),
            'lookup_names' => $request->boolean('lookup_names'),
        ], $data['token'] ?? null);

        return back()->with('status', 'HubSpot settings saved. The token is stored encrypted and not shown again.');
    }

    public function test(HubSpotSettings $settings): RedirectResponse
    {
        if (($token = $settings->token()) === null) {
            return back()->withErrors(['test' => 'Save a HubSpot token first.']);
        }
        try {
            (new HubSpotClient($token))->check();
        } catch (HubSpotError $e) {
            return back()->withErrors(['test' => $e->getMessage()]);
        } catch (Throwable) {
            return back()->withErrors(['test' => 'Could not reach HubSpot.']);
        }

        return back()->with('status', 'Connected to HubSpot.');
    }

    public function forget(HubSpotSettings $settings): RedirectResponse
    {
        $settings->forgetToken();

        return back()->with('status', 'HubSpot token removed. Calls are no longer logged.');
    }
}
