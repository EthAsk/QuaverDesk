<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Pages that only exist on the hosted service (the operator console, support visits). */
class EnsureHosted
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('tenancy.enabled'), 404);

        return $next($request);
    }
}
