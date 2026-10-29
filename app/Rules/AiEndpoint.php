<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Where API keys and call audio may be sent: https anywhere, or plain http
 * only to a model running on this server (localhost), so a key or a caller's
 * words never cross a network unencrypted.
 */
class AiEndpoint implements ValidationRule
{
    public static function allows(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower(trim($parts['host'], '[]'));

        return match (strtolower($parts['scheme'])) {
            'https' => true,
            'http' => in_array($host, ['localhost', '127.0.0.1', '::1'], true),
            default => false,
        };
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::allows($value)) {
            $fail('Use an https:// address. Plain http:// is only allowed for a model on this server (localhost).');
        }
    }
}
