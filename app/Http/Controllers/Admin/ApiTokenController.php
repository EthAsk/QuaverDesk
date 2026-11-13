<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiCall;
use App\Models\ApiToken;
use App\Models\Setting;
use App\Models\User;
use App\Support\ApiCallRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApiTokenController extends Controller
{
    public function index(): View
    {
        return view('admin.api', [
            'tokens' => ApiToken::query()->orderBy('name')->get(),
            'users' => User::query()->seated()->orderBy('name')->get(['id', 'name']),
            'recent' => ApiCall::query()->with('user:id,name')->latest('id')->limit(20)->get(),
            'paused' => (bool) Setting::get('api.calls_paused', false),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        [, $plain] = ApiToken::make($data['name']);

        return back()->with('api_token', $plain)->with('status', 'Token made. Copy it now: it is not shown again.');
    }

    /** A key that can place calls, for chosen people, with its own limits. Shown once, like the others. */
    public function storeCalling(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'users' => ['required', 'array', 'min:1'],
            'users.*' => ['integer', \App\Tenancy\TenantRule::exists('users')->where('forward_only', 0)],
            'countries' => ['nullable', 'string', 'max:200'],
            'per_minute' => ['required', 'integer', 'min:1', 'max:60'],
            'per_hour' => ['required', 'integer', 'min:1', 'max:600'],
            'daily_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ], ['users.required' => 'Pick the people this token may place calls for.']);

        $prefixes = collect(preg_split('/[\s,]+/', (string) ($data['countries'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($p) => '+'.ltrim($p, '+'))->filter(fn ($p) => preg_match('/^\+[1-9]\d{0,3}$/', $p) && $p !== '+44')->unique()->values()->all();
        [$token, $plain] = ApiToken::make($data['name'], 'calls', [
            'allowed_user_ids' => array_map('intval', $data['users']), 'allowed_prefixes' => $prefixes,
            'per_minute' => $data['per_minute'], 'per_hour' => $data['per_hour'], 'daily_minutes' => $data['daily_minutes'],
        ]);
        app(ApiCallRules::class)->alert("a token that can place calls ({$token->name}) was made");

        return back()->with('api_token', $plain)->with('status', 'Calling token made. Copy it now: it is not shown again.');
    }

    /** Stops every calling token placing calls until it is switched back on. */
    public function pause(Request $request): RedirectResponse
    {
        Setting::put('api.calls_paused', $request->boolean('paused'));

        return back()->with('status', $request->boolean('paused') ? 'Placing calls over the API is paused.' : 'Placing calls over the API is on again.');
    }

    public function destroy(ApiToken $apiToken): RedirectResponse
    {
        $apiToken->delete();

        return back()->with('status', 'Token removed. Anything using it stops working now.');
    }
}
