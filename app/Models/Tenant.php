<?php

namespace App\Models;

use App\Domain\Licensing\Licence;
use App\Domain\Licensing\Plan;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** A company using QuaverDesk. Self-hosted installs have exactly one. */
class Tenant extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    /** Subdomain names a company can't take. */
    public const RESERVED_SLUGS = ['www', 'admin', 'operator', 'api', 'voice', 'mail', 'app', 'status', 'help', 'support'];

    public const SLUG = '/^[a-z0-9](?:[a-z0-9-]{0,30}[a-z0-9])?$/';

    protected $fillable = ['name', 'slug', 'domain'];

    /** What a hosted company may use. Only the operator sets these (never fillable). */
    public const PLAN_FIELDS = ['plan', 'seats', 'max_numbers', 'monthly_minutes', 'concurrent_calls', 'storage_mb'];

    /** The same starting plan as the database's defaults. */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE, 'plan' => 'plus', 'seats' => 5, 'max_numbers' => 2,
        'monthly_minutes' => 1000, 'concurrent_calls' => 4, 'storage_mb' => 1024,
    ];

    protected function casts(): array
    {
        return [
            'plan' => Plan::class,
            'seats' => 'integer',
            'max_numbers' => 'integer',
            'monthly_minutes' => 'integer',
            'concurrent_calls' => 'integer',
            'storage_mb' => 'integer',
        ];
    }

    /** Rules for a company's plan (the console and tenant:plan). @return array<string, mixed> */
    public static function planRules(): array
    {
        return [
            'plan' => ['required', Rule::enum(Plan::class)],
            'seats' => ['required', 'integer', 'min:1', 'max:10000'],
            'max_numbers' => ['required', 'integer', 'min:0', 'max:10000'],
            'monthly_minutes' => ['required', 'integer', 'min:0', 'max:10000000'],
            'concurrent_calls' => ['required', 'integer', 'min:0', 'max:1000'],
            'storage_mb' => ['required', 'integer', 'min:0', 'max:10000000'],
        ];
    }

    /** On the hosted service the company's plan takes the place of a licence key. */
    public function licence(): Licence
    {
        $plan = $this->plan ?? Plan::Free;

        return new Licence('hosted-'.$this->id, $this->name, $plan, $plan->userCap() ?? $this->seats,
            new DateTimeImmutable('@'.($this->created_at?->getTimestamp() ?? 0)), null);
    }

    /** Rules for adding a company (the console and tenant:create). @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'slug' => ['required', 'regex:'.self::SLUG, Rule::notIn(self::RESERVED_SLUGS), 'unique:tenants,slug'],
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['nullable', 'regex:/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', 'unique:tenants,domain'],
        ];
    }

    public const RULE_MESSAGES = ['slug.regex' => 'The address must be 1–32 lowercase letters, digits or hyphens.'];

    /** The company's own web address: its domain, or its subdomain of the service. */
    public function url(): string
    {
        // Same scheme and port as APP_URL (a port only matters on a development machine).
        $app = parse_url((string) config('app.url')) ?: [];
        $port = isset($app['port']) ? ':'.$app['port'] : '';

        return ($app['scheme'] ?? 'https').'://'.($this->domain ?: $this->slug.'.'.config('tenancy.base_domain')).$port;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
