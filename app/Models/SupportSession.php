<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operator's visit to a company, signed in as one of its people: asked for
 * with a reason, started once from a single-use link, time-limited, and
 * announced to the company's owners.
 */
class SupportSession extends Model
{
    protected $fillable = ['operator_id', 'tenant_id', 'user_id', 'reason', 'token_hash', 'token_expires_at'];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
