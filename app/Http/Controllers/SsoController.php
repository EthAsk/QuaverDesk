<?php

namespace App\Http\Controllers;

use App\Domain\Licensing\Feature;
use App\Models\User;
use App\Sso\Oidc;
use App\Sso\SsoSettings;
use App\Support\LicenceManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/** Sign in with Microsoft or Google (Pro). Only people already added under Users can sign in this way. */
class SsoController extends Controller
{
    private const MAX_MINUTES = 10;

    public function redirect(Request $request, string $provider, SsoSettings $settings, LicenceManager $licences): RedirectResponse
    {
        $oidc = $this->oidc($provider, $settings, $licences);
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        $begin = $oidc->begin();
        $request->session()->put('sso', ['provider' => $provider, 'state' => $begin['state'], 'nonce' => $begin['nonce'], 'verifier' => $begin['verifier'], 'at' => time()]);

        return redirect()->away($begin['url']);
    }

    public function callback(Request $request, string $provider, SsoSettings $settings, LicenceManager $licences): RedirectResponse
    {
        $oidc = $this->oidc($provider, $settings, $licences);
        $flow = $request->session()->pull('sso');

        if (! is_array($flow) || ($flow['provider'] ?? null) !== $provider || time() - (int) $flow['at'] > self::MAX_MINUTES * 60
            || ! hash_equals((string) $flow['state'], (string) $request->query('state'))) {
            return $this->refuse('That sign-in link has expired. Try again.');
        }
        if ($request->query('error') || ! is_string($request->query('code'))) {
            return $this->refuse('The sign-in was cancelled or refused by '.SsoSettings::PROVIDERS[$provider].'.');
        }

        try {
            $who = $oidc->identify($request->query('code'), $flow['verifier'], $flow['nonce']);
        } catch (Throwable $e) {
            return $this->refuse($e->getMessage());
        }

        // Whoever is already linked to this identity; otherwise the person with this email who hasn't been linked yet.
        $user = User::query()->where('sso_provider', $provider)->where('sso_subject', $who['subject'])->first()
            ?? User::query()->where('email', $who['email'])->whereNull('sso_subject')->first();

        if ($user === null || ! $user->canSignIn()) {
            return $this->refuse("There's no account here for {$who['email']}. Ask an admin to add you under Users first.");
        }
        $user->forceFill(['sso_provider' => $provider, 'sso_subject' => $who['subject']])->save();

        $request->session()->regenerate();

        // Two-factor still applies: the provider doesn't replace it. Fortify's own challenge finishes the sign-in.
        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user);

        return redirect()->intended(route('dashboard'));
    }

    private function oidc(string $provider, SsoSettings $settings, LicenceManager $licences): Oidc
    {
        abort_unless($licences->allows(Feature::Sso) && $settings->ready($provider), 404);

        return new Oidc($provider, $settings->get($provider), route('sso.callback', $provider));
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
