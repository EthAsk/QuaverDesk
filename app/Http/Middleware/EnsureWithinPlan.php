<?php

namespace App\Http\Middleware;

use App\Support\LicenceManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('within-plan:ring_groups,admin.ring-groups') on the pages
 * that add one. Over the plan's count (Plan::FREE_LIMITS), sends the admin back
 * to the list with an upgrade prompt instead.
 */
class EnsureWithinPlan
{
    private const NAMES = ['phone_numbers' => 'phone number', 'ring_groups' => 'ring group', 'ivr_menus' => 'IVR menu', 'sip_trunks' => 'SIP trunk'];

    public function __construct(private readonly LicenceManager $licences)
    {
    }

    public function handle(Request $request, Closure $next, string $table, string $back): Response
    {
        $plan = $this->licences->current()->plan;
        $limit = $plan->limit($table);
        $class = '\\App\\Models\\'.match ($table) {
            'phone_numbers' => 'PhoneNumber', 'ring_groups' => 'RingGroup', 'ivr_menus' => 'IvrMenu', 'sip_trunks' => 'SipTrunk',
        };

        if ($limit !== null && $class::query()->count() >= $limit) {
            $name = self::NAMES[$table];
            $message = "The {$plan->label()} plan includes {$limit} {$name}. Upgrade to Plus for as many as you need.";
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 402);
            }

            return redirect()->route($back)->with('limit', $message);
        }

        return $next($request);
    }
}
