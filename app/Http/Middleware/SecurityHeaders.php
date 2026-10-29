<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Microphone is needed by the softphone on our own origin only.
        $response->headers->set('Permissions-Policy', 'microphone=(self), camera=(), geolocation=()');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // The softphone talks SIP over a WebSocket to our own voice server.
        $voice = '';
        if ($ws = config('voip.freeswitch.websocket_url')) {
            $parts = parse_url($ws);
            $voice = isset($parts['scheme'], $parts['host'])
                ? ' '.$parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
                : '';
        }

        if (! app()->environment('local')) {
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data:",
                "connect-src 'self'".$voice,
                "media-src 'self' blob:",
                "frame-ancestors 'none'",
                // A page whose form leads to another site (a payment page) names it in csp.form-action.
                "form-action 'self'".(($also = $request->attributes->get('csp.form-action')) ? ' '.$also : ''),
                "base-uri 'self'",
            ]));
        }

        return $response;
    }
}
