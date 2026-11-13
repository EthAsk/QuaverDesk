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
        // A core feature, or one an installed module added; anything else is a mistake in the routes.
        abort_if(Feature::tryFrom($feature) === null && app(\App\Support\Modules::class)->featureInfo($feature) === null, 500, "Unknown feature: {$feature}");

        if (! $this->licences->allowsKey($feature)) {
            [$label, $plans] = LicenceManager::describe($feature);
            if ($request->expectsJson()) {
                return response()->json(['message' => "{$label} is available on a paid plan."], 402);
            }

            return redirect()->route('dashboard')->with('upgrade', $label)->with('upgrade_plans', $plans);
        }

        return $next($request);
    }
}
