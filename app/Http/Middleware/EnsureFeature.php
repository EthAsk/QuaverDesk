<?php

namespace App\Http\Middleware;

use App\Domain\Licensing\Feature;
use App\Support\LicenceManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('feature:ai_summaries') — server-side gate behind every paid button. */
class EnsureFeature
{
    public function __construct(private readonly LicenceManager $licences)
    {
    }

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $feature = Feature::from($feature);

        if (! $this->licences->allows($feature)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => "{$feature->label()} is available on a paid plan."], 402);
            }

            return redirect()->route('dashboard')->with('upgrade', $feature->label());
        }

        return $next($request);
    }
}
