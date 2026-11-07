<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An AI receptionist (Pro): answers calls, puts callers through to the
 * routes set here, or takes a message for its mailbox.
 */
class Receptionist extends Model
{
    use BelongsToTenant;

    public const MAX_ROUTES = 8;

    protected $fillable = ['name', 'business', 'greeting', 'about', 'routes', 'mailbox_id', 'fallback', 'max_turns'];

    protected function casts(): array
    {
        return ['routes' => 'array'];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
