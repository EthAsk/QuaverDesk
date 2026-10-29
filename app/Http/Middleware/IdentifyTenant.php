<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the hosted service, works out which company a request is for from its web
 * address. Unknown addresses get a 404 and suspended companies a 403, before
 * sessions or sign-in are touched. Self-hosted installs skip this entirely.
 */
class IdentifyTenant
{
    /**
     * Routes that aren't reached on a company's own address: the voice server
     * (its webhooks find the company from the call, see IdentifyCallTenant; its
     * prompts and sounds are files under unguessable names).
     */
    private const INSTALL_WIDE = ['webhooks/*', 'voice/*', 'up'];

    /** The phone and what it needs to sign in to the voice server. */
    private const EMERGENCY_PHONE = ['phone', 'softphone/token'];

    public function __construct(private readonly Tenancy $tenancy)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tenancy->enabled()) {
            return $next($request);
        }

        // The operator console exists only on its own address, and nothing else does there.
        $host = strtolower($request->getHost());
        $operatorHost = (string) config('tenancy.operator_host');
        $isConsole = $request->is('operator', 'operator/*');
        if ($operatorHost !== '' && $host === $operatorHost) {
            abort_unless($isConsole, 404);

            return $next($request);
        }
        abort_if($isConsole, 404);

        if ($request->is(...self::INSTALL_WIDE)) {
            return $next($request);
        }

        $tenant = $this->find($host);
        abort_if(! $tenant, 404);
        // While suspended, only the phone stays open (for people already signed in), so 999 still works.
        $phoneOnly = ! $tenant->isActive() && $request->is(...self::EMERGENCY_PHONE);
        abort_if(! $tenant->isActive() && ! $phoneOnly, 403, 'This account is suspended. Contact QuaverDesk support.');
        View::share('companySuspended', ! $tenant->isActive());

        $this->tenancy->set($tenant);
        // Each company's address is its own passkey site: a passkey made on one is never offered on another.
        config(['passkeys.relying_party_id' => $host, 'passkeys.allowed_origins' => [$tenant->url()]]);

        return $next($request);
    }

    private function find(string $host): ?Tenant
    {
        $base = (string) config('tenancy.base_domain');
        if ($base !== '' && str_ends_with($host, '.'.$base)) {
            $slug = substr($host, 0, -strlen('.'.$base));

            return preg_match(Tenant::SLUG, $slug) ? Tenant::query()->firstWhere('slug', $slug) : null;
        }

        return Tenant::query()->firstWhere('domain', $host);
    }
}
