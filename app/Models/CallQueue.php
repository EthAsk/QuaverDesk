<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A call queue: callers wait in turn, hearing hold music and their place in
 * the queue, while the agents logged in to it are rung (see App\Voice\CallQueueRunner).
 */
class CallQueue extends Model
{
    use BelongsToTenant;

    public const HOLD_SLICE_SECONDS = 20; // hold music between checks for a free agent

    protected $fillable = ['name', 'extension', 'greeting', 'ring_seconds', 'max_wait_seconds', 'announce_position',
        'hold_sound_id', 'overflow', 'overflow_when_empty', 'strategy'];

    protected function casts(): array
    {
        return ['announce_position' => 'boolean', 'overflow_when_empty' => 'boolean'];
    }

    /** Its agents, logged in or not. */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('logged_in', 'priority');
    }

    /** Agents taking its calls now. */
    public function agents(): BelongsToMany
    {
        return $this->members()->wherePivot('logged_in', true)->where('forward_only', false);
    }

    public function holdSound(): BelongsTo
    {
        return $this->belongsTo(Sound::class, 'hold_sound_id');
    }

    public function callers(): HasMany
    {
        return $this->hasMany(QueueCaller::class);
    }

    /** What callers hear while they wait: its own music, else the company's, else nothing. */
    public function holdMusicUrl(): ?string
    {
        return ($this->holdSound ?? Sound::holdMusic())?->url();
    }

    /** Extensions queues use, so people and ring groups can't take them. @return list<string> */
    public static function extensions(): array
    {
        return static::query()->whereNotNull('extension')->pluck('extension')->all();
    }
}
