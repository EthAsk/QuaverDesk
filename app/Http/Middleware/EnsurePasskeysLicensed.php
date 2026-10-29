<?php

namespace App\Http\Middleware;

use App\Domain\Licensing\Feature;
use App\Support\LicenceManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passkeys are a Plus and Pro feature. Fortify registers the passkey routes, so
 * this guards them by name. Removing a passkey stays open on every plan.
 */
class EnsurePasskeysLicensed
{
    private const PAID_ROUTES = [
        'passkey.login-options', 'passkey.login',
        'passkey.confirm-options', 'passkey.confirm',
        'passkey.registration-options', 'passkey.store',
    ];

    public function __construct(private readonly LicenceManager $licences)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs(...self::PAID_ROUTES) && ! $this->licences->allows(Feature::Passkeys)) {
            return response()->json(['message' => 'Passkey sign-in is available on the Plus and Pro plans.'], 403);
        }

        return $next($request);
    }
}
