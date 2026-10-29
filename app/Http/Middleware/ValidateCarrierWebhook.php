<?php

namespace App\Http\Middleware;

use App\Voice\VoiceServer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Voice server webhooks must carry the shared secret. */
class ValidateCarrierWebhook
{
    public function __construct(private readonly VoiceServer $voice)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->voice->verifyWebhook($request)) {
            Log::warning('Rejected an unauthenticated voice webhook.', ['ip' => $request->ip(), 'path' => $request->path()]);

            abort(403);
        }

        return $next($request);
    }
}
