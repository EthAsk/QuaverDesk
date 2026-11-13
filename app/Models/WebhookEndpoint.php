<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Somewhere a company wants to hear about calls (Zapier, Make, n8n, their own system). */
class WebhookEndpoint extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'url', 'events', 'enabled'];

    /** The signing secret is shown once, when it's made, and never exported. */
    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'enabled' => 'boolean',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function wants(string $event): bool
    {
        return $this->enabled && ($this->events === null || in_array($event, $this->events, true));
    }
}
