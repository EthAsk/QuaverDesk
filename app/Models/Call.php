<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Call extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'carrier_sid', 'parent_sid', 'leg', 'user_id', 'sip_trunk_id', 'direction', 'from', 'to', 'decision', 'status', 'duration_seconds',
    ];

    public const LEG_TRANSFER = 'transfer';
    public const LEG_FOLLOW_ME = 'follow_me';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
