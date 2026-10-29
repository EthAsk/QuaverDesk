<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Passkeys\Passkey as BasePasskey;

/**
 * A passkey, found only through its person, so a sign-in on one company's
 * address never finds another company's passkey.
 */
class Passkey extends BasePasskey
{
    protected static function booted(): void
    {
        static::addGlobalScope('company', fn (Builder $query) => $query->whereHas('user'));
    }
}
