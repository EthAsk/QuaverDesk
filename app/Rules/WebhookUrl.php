<?php

namespace App\Rules;

use App\Webhooks\SafeUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class WebhookUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || SafeUrl::resolve($value, $why) === null) {
            $fail($why ?? 'That address can\'t be used.');
        }
    }
}
