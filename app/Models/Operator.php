<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** Someone who runs the hosted service. Not a member of any company. */
class Operator extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    // No "remember me" for operators.
    public function getRememberTokenName(): string
    {
        return '';
    }
}
