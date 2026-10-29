<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\URL;

/**
 * Which company the current request, job or command is working for.
 *
 * Self-hosted installs have one company, used automatically. On the hosted
 * service it comes from the web address (IdentifyTenant) or the queued job, and
 * anything that touches company data without one fails rather than seeing
 * every company's data.
 */
class Tenancy
{
    private ?Tenant $tenant = null;

    /** Set while code deliberately works across every company (the voice server config). */
    private bool $everywhere = false;

    public function enabled(): bool
    {
        return (bool) config('tenancy.enabled');
    }

    /** The current company. Throws if there isn't one, so data is never read unscoped by mistake. */
    public function current(): Tenant
    {
        if ($this->tenant) {
            return $this->tenant;
        }
        if ($this->enabled()) {
            throw new NoTenant('No company has been chosen for this request, job or command.');
        }

        return $this->tenant = Tenant::query()->orderBy('id')->first()
            ?? Tenant::query()->forceCreate(['name' => (string) config('app.name'), 'slug' => 'default', 'status' => Tenant::STATUS_ACTIVE]);
    }

    /** The current company's id, or null when none has been chosen on the hosted service. */
    public function id(): ?int
    {
        return $this->enabled() && ! $this->tenant ? null : $this->current()->getKey();
    }

    public function set(?Tenant $tenant): void
    {
        if ($tenant?->getKey() !== $this->tenant?->getKey()) {
            app()->forgetScopedInstances(); // e.g. the licence read for the previous company
        }
        $this->tenant = $tenant;
    }

    /** Run something as one company, then put back whichever was current before. */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->set($tenant);
        try {
            return $callback($tenant);
        } finally {
            $this->set($previous);
        }
    }

    /** Build links (invites, emails) on a company's own web address rather than the current one. */
    public function withUrlsOf(Tenant $tenant, Closure $callback): mixed
    {
        URL::forceRootUrl($tenant->url());
        try {
            return $callback();
        } finally {
            URL::forceRootUrl(null);
        }
    }

    /**
     * Run something once for each active company (scheduled tasks and commands):
     * the one company on a self-hosted install, every one on the hosted service.
     */
    public function each(Closure $callback): void
    {
        if (! $this->enabled()) {
            $callback($this->current());

            return;
        }
        foreach (Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->orderBy('id')->get() as $tenant) {
            $this->run($tenant, $callback);
        }
    }

    /**
     * Run something that must see every company's data at once. Only for
     * install-wide work like writing the voice server's config; never for
     * anything that answers a person.
     */
    public function everywhere(Closure $callback): mixed
    {
        $previous = $this->everywhere;
        $this->everywhere = true;
        try {
            return $callback();
        } finally {
            $this->everywhere = $previous;
        }
    }

    public function isEverywhere(): bool
    {
        return $this->everywhere;
    }
}
