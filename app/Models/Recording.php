<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A call recording or a voicemail. Audio and AI output are encrypted at rest. */
class Recording extends Model
{
    use BelongsToTenant;

    public const CALL = 'call';
    public const VOICEMAIL = 'voicemail';

    protected $fillable = [
        'kind', 'call_id', 'mailbox_id', 'carrier_sid', 'upload_path', 'from', 'to', 'duration_seconds',
        'path', 'status', 'error', 'transcript', 'summary', 'action_items', 'sentiment',
        'ai_status', 'ai_error', 'emailed_at', 'expires_at',
    ];

    protected $hidden = ['upload_path', 'path'];

    protected function casts(): array
    {
        return [
            'transcript' => 'encrypted',
            'summary' => 'encrypted',
            'action_items' => 'encrypted:array',
            'emailed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function hasAudio(): bool
    {
        return $this->status === 'stored' && $this->path !== null;
    }

    /** Recordings this user may see: admins see all; others see their own calls and their mailboxes. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $q;
        }

        return $q->where(function (Builder $q) use ($user) {
            $q->whereHas('call', fn (Builder $c) => $c->where('user_id', $user->id))
                ->orWhereHas('mailbox.members', fn (Builder $m) => $m->whereKey($user->id));
        });
    }
}
