<?php

namespace App\Http\Middleware;

use App\Domain\Licensing\Feature;
use App\Models\ApiToken;
use App\Support\LicenceManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The API needs a company's token, and a plan that includes it. */
class ApiAuth
{
    public function __construct(private readonly LicenceManager $licences)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->licences->allows(Feature::Api) ? ApiToken::find_($request->bearerToken()) : null;
        if ($token === null) {
            return response()->json(['error' => 'unauthorised', 'message' => 'Send your API token as "Authorization: Bearer …". The API is on the Plus and Pro plans.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        // Noting use at most once a minute keeps reads cheap.
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
