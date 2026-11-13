<?php

namespace App\Http\Middleware;

use App\Domain\Licensing\Feature;
use App\Sso\ScimToken;
use App\Support\LicenceManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The directory's user sync: needs the company's sync token, and the Pro plan. */
class ScimAuth
{
    public function __construct(private readonly ScimToken $tokens, private readonly LicenceManager $licences)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->licences->allows(Feature::Sso) || ! $this->tokens->valid($request->bearerToken())) {
            return response()->json(['schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'], 'status' => '401', 'detail' => 'Not authorised.'], 401, ['Content-Type' => 'application/scim+json', 'WWW-Authenticate' => 'Bearer']);
        }

        return $next($request);
    }
}
