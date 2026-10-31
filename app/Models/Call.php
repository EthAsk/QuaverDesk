<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Call extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'carrier_sid', 'parent_sid', 'leg', 'user_id', 'sip_trunk_id', 'direction', 'from', 'to', 'decision', 'status', 'reason', 'duration_seconds',
    ];

    /** Turned away before it reached the company: it didn't prove which trunk it came in on. */
    public const STATUS_REFUSED = 'refused';

    /** Statuses shown as problems in call logs. */
    public const PROBLEMS = ['rejected', self::STATUS_REFUSED, 'failed', 'busy', 'no-answer'];

    public const LEG_TRANSFER = 'transfer';
    public const LEG_FOLLOW_ME = 'follow_me';
    public const LEG_ON_NET = 'on_net'; // connected straight to a number on this service, not through trunks

    public function trunk(): BelongsTo
    {
        return $this->belongsTo(SipTrunk::class, 'sip_trunk_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Seconds this call counts towards limits and allowances: its duration, or
     * the time so far while it could still be going. One that never reported
     * an end and is older than the longest allowed call counts nothing.
     */
    public function countedSeconds(int $maxCallMinutes): int
    {
        if ($this->duration_seconds !== null) {
            return (int) $this->duration_seconds;
        }
        $elapsed = (int) $this->created_at->diffInSeconds(now(), true);

        return $elapsed <= ($maxCallMinutes + 5) * 60 ? $elapsed : 0;
    }
}
