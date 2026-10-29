<?php

namespace App\Tenancy;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Validation rules for company tables. Laravel's exists/unique rules query the
 * table directly, skipping the company scope, so a form could otherwise name
 * another company's person or sound.
 */
class TenantRule
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)->where('tenant_id', app(Tenancy::class)->current()->getKey());
    }

    public static function unique(string $table, string $column): Unique
    {
        return Rule::unique($table, $column)->where('tenant_id', app(Tenancy::class)->current()->getKey());
    }
}
