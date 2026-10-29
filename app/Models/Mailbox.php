<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mailbox extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'greeting', 'greeting_sound_id', 'max_seconds', 'attach_audio', 'include_transcript', 'delete_after_send', 'extra_emails'];

    protected function casts(): array
    {
        return [
            'attach_audio' => 'boolean',
            'include_transcript' => 'boolean',
            'delete_after_send' => 'boolean',
            'extra_emails' => 'array',
        ];
    }

    /** People who receive this mailbox's emails and can play its messages. */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /** People whose unanswered calls come to this mailbox. */
    public function owners(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return list<string> */
    public function recipientEmails(): array
    {
        return collect($this->members()->pluck('email'))
            ->merge($this->extra_emails ?? [])
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))
            ->map(fn ($e) => strtolower($e))
            ->unique()->values()->all();
    }

    /** A recording that plays instead of the greeting text. */
    public function greetingSound(): BelongsTo
    {
        return $this->belongsTo(Sound::class, 'greeting_sound_id');
    }
}
