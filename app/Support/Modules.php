<?php

namespace App\Support;

use App\Domain\Calls\CallScript;
use App\Domain\Licensing\Plan;
use App\Voice\CallRequest;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;

/**
 * Optional modules: folders under modules/ that add a feature without the core
 * knowing about it. Each has a service provider at modules/<Name>/<Name>ServiceProvider.php,
 * registered automatically, which hooks in through the methods below. With no
 * modules installed, everything here is empty and the app runs as normal.
 */
final class Modules
{
    /** @var array<string, array{label: string, plan: Plan}> */
    private array $features = [];

    /** @var array<string, list<array{0: string, 1: string, 2: string, 3?: string, 4?: string}>> */
    private array $adminNav = [];

    /** @var list<string> */
    private array $homeCards = [];

    /** @var list<Closure(): iterable<array{username: string, password: string, variables: array<string, string>, allowed_ips?: ?string}>> */
    private array $sipUsers = [];

    /** @var list<Closure(string): ?int> */
    private array $sipUserTenants = [];

    /** @var list<Closure(CallRequest): ?CallScript> */
    private array $outgoingCalls = [];

    /** @var list<class-string<Model>> */
    private array $tenantModels = [];

    /** Registers each module's service provider. Called once, from AppServiceProvider::register(). */
    public static function discover(Application $app): void
    {
        foreach (glob($app->basePath('modules/*/*ServiceProvider.php')) ?: [] as $file) {
            $name = basename(dirname($file));
            $class = 'Modules\\'.$name.'\\'.basename($file, '.php');
            if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) && class_exists($class)) {
                $app->register($class);
            }
        }
    }

    /** A paid feature the module adds: on for $from and every plan above it. */
    public function feature(string $key, string $label, Plan $from): void
    {
        $this->features[$key] = ['label' => $label, 'plan' => $from];
    }

    /** @return array{label: string, plan: Plan}|null */
    public function featureInfo(string $key): ?array
    {
        return $this->features[$key] ?? null;
    }

    /** @return array<string, array{label: string, plan: Plan}> */
    public function features(): array
    {
        return $this->features;
    }

    /**
     * A page in an admin menu group: [label, route name, active route pattern, feature, plan badge].
     * The last two show a lock badge when the plan doesn't include the feature.
     */
    public function adminNav(string $group, array $item): void
    {
        $this->adminNav[$group][] = $item;
    }

    /** @return list<array> */
    public function adminNavFor(string $group): array
    {
        return $this->adminNav[$group] ?? [];
    }

    /** A view shown on everyone's Home page (it gets its own data from a view composer). */
    public function homeCard(string $view): void
    {
        $this->homeCards[] = $view;
    }

    /** @return list<string> */
    public function homeCards(): array
    {
        return $this->homeCards;
    }

    /** More devices that sign in to the voice server, written alongside people's phones. */
    public function sipUsers(Closure $users): void
    {
        $this->sipUsers[] = $users;
    }

    /** @return iterable<array{username: string, password: string, variables: array<string, string>, allowed_ips?: ?string}> */
    public function allSipUsers(): iterable
    {
        foreach ($this->sipUsers as $users) {
            yield from $users();
        }
    }

    /** Which company a module's SIP username belongs to (null if it isn't one of the module's). */
    public function sipUserTenant(Closure $lookup): void
    {
        $this->sipUserTenants[] = $lookup;
    }

    public function tenantForSipUser(string $username): ?int
    {
        foreach ($this->sipUserTenants as $lookup) {
            if (($tenant = $lookup($username)) !== null) {
                return $tenant;
            }
        }

        return null;
    }

    /**
     * Takes over a call placed by one of the module's devices: return the call's script,
     * or null to leave the call to the normal dial plan.
     */
    public function outgoingCall(Closure $handler): void
    {
        $this->outgoingCalls[] = $handler;
    }

    public function handleOutgoing(CallRequest $call): ?CallScript
    {
        foreach ($this->outgoingCalls as $handler) {
            if (($script = $handler($call)) !== null) {
                return $script;
            }
        }

        return null;
    }

    /**
     * Models a company owns, exported with it and deleted with it. Listed in an
     * order that can be deleted from the top, before the core's own.
     *
     * @param  list<class-string<Model>>  $models
     */
    public function tenantModels(array $models): void
    {
        $this->tenantModels = [...$this->tenantModels, ...$models];
    }

    /** @return list<class-string<Model>> */
    public function allTenantModels(): array
    {
        return $this->tenantModels;
    }
}
