<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IvrMenu extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'greeting', 'greeting_sound_id', 'options', 'timeout_seconds', 'fallback'];

    protected function casts(): array
    {
        return ['options' => 'array'];
    }

    /** A recording that plays instead of the greeting text. */
    public function greetingSound(): BelongsTo
    {
        return $this->belongsTo(Sound::class, 'greeting_sound_id');
    }
}
