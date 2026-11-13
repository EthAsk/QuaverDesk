<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One caller's time in a queue. */
class QueueCaller extends Model
{
    use BelongsToTenant;

    public const ANSWERED = 'answered';
    public const OVERFLOW = 'overflow';
    public const ABANDONED = 'abandoned';

    /** Seconds after its last round that a caller still counts as waiting (rounds are at most this far apart). */
    public const FRESH_SECONDS = 150;

    protected $fillable = ['call_queue_id', 'carrier_sid', 'from', 'joined_at', 'seen_at', 'ringing_until',
        'announced_position', 'announced_at', 'ended_at', 'outcome', 'rung_agents'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'seen_at' => 'datetime', 'ringing_until' => 'datetime',
            'announced_at' => 'datetime', 'ended_at' => 'datetime', 'rung_agents' => 'array'];
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(CallQueue::class, 'call_queue_id');
    }

    /**
     * Still in the queue. A caller an agent answered stops coming back for
     * rounds, so after its ring time (plus a margin) it no longer counts,
     * even before the call ends and closes it.
     */
    public function scopeWaiting(Builder $q): Builder
    {
        return $q->whereNull('ended_at')->where(fn (Builder $q) => $q
            ->where('seen_at', '>=', now()->subSeconds(self::FRESH_SECONDS))
            ->where(fn (Builder $q) => $q->whereNull('ringing_until')->orWhere('ringing_until', '>=', now()->subSeconds(5))));
    }

    public function waitedSeconds(): int
    {
        return max(0, (int) $this->joined_at->diffInSeconds(now(), true));
    }

    public function close(string $outcome): void
    {
        $this->forceFill(['ended_at' => now(), 'outcome' => $outcome])->save();
    }
}
