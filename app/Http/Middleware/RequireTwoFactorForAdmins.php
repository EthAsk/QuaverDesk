<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owners and admins must turn on two-factor before using admin pages.
 * An operator's support visit is exempt: the operator signed in with two-factor,
 * and SupportVisit has already ended any visit whose time is up.
 */
class RequireTwoFactorForAdmins
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $visiting = $request->hasSession() && $request->session()->has('support_visit');

        if ($user && $user->isAdmin() && ! $user->hasTwoFactorEnabled() && ! $visiting) {
            return redirect()->route('security')
                ->with('status', 'Turn on two-factor authentication to use admin settings.');
        }

        return $next($request);
    }
}
