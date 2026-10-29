<?php

namespace App\Http\Controllers;

use App\Models\Acknowledgement;
use App\Support\OperatorTerms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SetupController extends Controller
{
    public function show(): View
    {
        return view('setup.acknowledge', [
            'items' => OperatorTerms::items(),
            'accepted' => Acknowledgement::currentAccepted(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $items = OperatorTerms::items();

        $rules = [];
        foreach (array_keys($items) as $key) {
            $rules["accept.{$key}"] = ['accepted'];
        }
        $request->validate($rules, ['accept.*.accepted' => 'Please confirm every statement.']);

        Acknowledgement::create([
            'user_id' => $request->user()->id,
            'version' => config('voip.terms_version'),
            'items' => ['hash' => OperatorTerms::hash(), 'text' => $items],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            'accepted_at' => now(),
        ]);

        return redirect()->route('dashboard')->with('status', 'Setup complete. Calling is now switched on.');
    }
}
