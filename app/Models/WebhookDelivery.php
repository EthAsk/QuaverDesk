<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One event sent to one endpoint, kept for a week so a failure can be looked at. */
class WebhookDelivery extends Model
{
    use BelongsToTenant;

    public const KEEP_DAYS = 7;

    protected $fillable = ['webhook_endpoint_id', 'event_id', 'event', 'payload', 'status', 'attempts', 'response_code', 'error', 'delivered_at'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted', 'delivered_at' => 'datetime'];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
