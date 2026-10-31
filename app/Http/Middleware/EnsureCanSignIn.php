<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forward-only contacts never sign in, whichever way they got a session
 * (a passkey, an old invite link, a password reset). Login refuses them too;
 * this is the backstop.
 */
class EnsureCanSignIn
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        if ($user instanceof User && $user->isForwardOnly()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'This account only forwards calls. It has no sign-in.']);
        }

        return $next($request);
    }
}
