<?php

namespace App\Http\Middleware;

use App\Domain\Calls\CallScript;
use App\Http\Controllers\Webhooks\VoiceController;
use App\Tenancy\CallTenant;
use App\Tenancy\Tenancy;
use App\Voice\CallRequest;
use App\Voice\VoiceServer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the hosted service, finds which company a voice server webhook is for
 * (see CallTenant). A call that belongs to no company is told the number isn't
 * in service, and nothing about it is stored.
 */
class IdentifyCallTenant
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly CallTenant $calls,
        private readonly VoiceServer $voice,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Unsigned requests are left for ValidateCarrierWebhook to refuse, without looking anything up.
        if (! $this->tenancy->enabled() || ! $request->is('webhooks/voice/*') || ! $this->voice->verifyWebhook($request)) {
            return $next($request);
        }

        $tenant = $this->calls->resolve($request);
        if ($tenant === null) {
            if ((new CallRequest($request))->exiting()) {
                return response('', 204);
            }
            $script = (new CallScript())->say(VoiceController::MSG_NOT_IN_SERVICE)->hangup();

            return response($this->voice->render($script), 200, ['Content-Type' => $this->voice->responseContentType()]);
        }

        return $this->tenancy->run($tenant, fn () => $next($request));
    }
}
