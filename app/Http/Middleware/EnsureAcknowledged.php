<?php

namespace App\Http\Middleware;

use App\Models\Acknowledgement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Calling stays switched off until an owner or admin has accepted the current
 * operator acknowledgements (emergency calls, recording law, fraud, AI data).
 */
class EnsureAcknowledged
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Acknowledgement::currentAccepted()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'An administrator must complete setup before calls can be made.'], 423);
        }

        return $request->user()?->isAdmin()
            ? redirect()->route('setup.acknowledge')
            : redirect()->route('dashboard')->with('status', 'Calling is off until an administrator completes setup.');
    }
}
