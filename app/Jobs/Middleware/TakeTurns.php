<?php

namespace App\Jobs\Middleware;

use App\Tenancy\Tenancy;
use Closure;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Fair use of the queue on the hosted service: each company's slow jobs run at
 * most tenancy.jobs_per_minute times a minute. Over that, a job goes to the
 * back of the queue, so one company's backlog can't delay everyone else's.
 * Jobs using this must allow being put back (retryUntil, maxExceptions).
 */
class TakeTurns
{
    public function handle(object $job, Closure $next): mixed
    {
        $tenancy = app(Tenancy::class);
        if (! $tenancy->enabled() || ($id = $tenancy->id()) === null) {
            return $next($job);
        }

        $key = 'jobs:tenant:'.$id;
        if (RateLimiter::tooManyAttempts($key, max(1, (int) config('tenancy.jobs_per_minute')))) {
            $job->release(RateLimiter::availableIn($key) + random_int(1, 10));

            return null;
        }
        RateLimiter::hit($key, 60);

        return $next($job);
    }
}
