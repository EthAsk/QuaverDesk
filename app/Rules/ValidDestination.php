<?php

namespace App\Rules;

use App\Support\DestinationCatalog;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidDestination implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! app(DestinationCatalog::class)->exists($value)) {
            $fail('Choose a destination from the list.');
        }
    }
}
