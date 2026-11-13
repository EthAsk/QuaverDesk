<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Sso\ScimToken;
use App\Sso\SsoSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SsoSettingsController extends Controller
{
    public function show(SsoSettings $settings, ScimToken $scim): View
    {
        return view('admin.sso', [
            'scimActive' => $scim->exists(),
            'scimSince' => $scim->createdAt(),
            'microsoft' => $settings->get('microsoft'),
            'google' => $settings->get('google'),
        ]);
    }

    public function update(Request $request, string $provider, SsoSettings $settings): RedirectResponse
    {
        abort_unless(isset(SsoSettings::PROVIDERS[$provider]), 404);

        $data = $request->validate([
            'client_id' => ['nullable', 'string', 'max:200'],
            'client_secret' => ['nullable', 'string', 'max:500'],
            'restrict' => ['nullable', 'string', 'max:255', $provider === 'microsoft'
                ? 'regex:/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/'
                : 'regex:/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/'],
        ], ['restrict.regex' => $provider === 'microsoft' ? 'That is not a directory (tenant) id: it looks like 11111111-2222-3333-4444-555555555555.' : 'Enter the Workspace domain, such as example.co.uk.']);

        $settings->save($provider, $request->boolean('enabled'), trim((string) ($data['client_id'] ?? '')), $data['client_secret'] ?? null, trim((string) ($data['restrict'] ?? '')));

        $note = $settings->ready($provider) ? 'Saved. People can now sign in with '.SsoSettings::PROVIDERS[$provider].'.' : 'Saved, but it is not switched on or not complete yet, so it is not offered at sign-in.';

        return back()->with('status', $note);
    }

    /** Makes (or replaces) the token the directory uses to push people here; shown once. */
    public function makeToken(ScimToken $scim): RedirectResponse
    {
        return back()->with('scim_token', $scim->make())->with('status', 'Sync token made. Copy it now: it is not shown again. Any earlier token has stopped working.');
    }

    public function revokeToken(ScimToken $scim): RedirectResponse
    {
        $scim->revoke();

        return back()->with('status', 'Directory sync is off: the token no longer works.');
    }
}
