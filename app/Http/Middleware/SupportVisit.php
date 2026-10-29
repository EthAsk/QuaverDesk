<?php

namespace App\Http\Middleware;

use App\Models\SupportSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/** Ends an operator's support visit when its time is up, and shows the banner while it lasts. */
class SupportVisit
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->hasSession() ? $request->session()->get('support_visit') : null;
        if (! $id) {
            return $next($request);
        }

        $visit = SupportSession::query()->with('operator')->find($id);
        if (! $visit || ! $visit->ends_at || $visit->ends_at->isPast()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'The support visit has ended.');
        }

        View::share('supportVisit', $visit);

        return $next($request);
    }
}
