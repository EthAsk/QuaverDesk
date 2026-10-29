<?php

use App\Http\Middleware\EnsureAcknowledged;
use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\EnsureHosted;
use App\Http\Middleware\EnsurePasskeysLicensed;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureWithinPlan;
use App\Http\Middleware\IdentifyCallTenant;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\RequireTwoFactorForAdmins;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SupportVisit;
use App\Http\Middleware\ValidateCarrierWebhook;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Webauthn\Exception\WebauthnException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(SecurityHeaders::class);
        // Which company a request is for, before routing, so before any
        // controller, session or sign-in is touched.
        $middleware->prepend([IdentifyTenant::class, IdentifyCallTenant::class]);
        $middleware->web(append: [EnsurePasskeysLicensed::class, SupportVisit::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('operator', 'operator/*') ? route('operator.login') : route('login'));

        // Trusted proxies are applied in AppServiceProvider from config, so they
        // still work after `php artisan config:cache` (env() is empty then).

        // Voice server webhooks are server-to-server and carry a shared secret,
        // so they are exempt from CSRF and protected by that secret instead.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        // Keep answering calls while `php artisan down` is on during a deploy.
        $middleware->preventRequestsDuringMaintenance(except: ['webhooks/voice/*', 'voice/prompts/*', 'voice/sounds/*']);

        $middleware->alias([
            'role' => EnsureRole::class,
            'feature' => EnsureFeature::class,
            'within-plan' => EnsureWithinPlan::class,
            'acknowledged' => EnsureAcknowledged::class,
            'admin2fa' => RequireTwoFactorForAdmins::class,
            'carrier.signed' => ValidateCarrierWebhook::class,
            'hosted' => EnsureHosted::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A passkey that fails a WebAuthn check (wrong site, bad signature) is a
        // refused sign-in, not a server error.
        $exceptions->render(fn (WebauthnException $e) => response()->json([
            'message' => 'That passkey could not be verified. Try again or use your password.',
        ], 422));
    })->create();
