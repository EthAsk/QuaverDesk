<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** A key for the company's own software to read its phone system. Only a hash of it is kept. */
class ApiToken extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'allowed_user_ids' => 'array', 'allowed_prefixes' => 'array'];
    }

    /** Makes a token; returns it with its token, which is never shown again. @return array{0: self, 1: string} */
    public static function make(string $name, string $scope = 'read', array $options = []): array
    {
        $plain = 'qdapi_'.Str::random(48);
        $token = new self(['name' => $name]);
        $token->forceFill(['token_hash' => hash('sha256', $plain), 'last_four' => substr($plain, -4), 'scope' => $scope] + $options)->save();

        return [$token, $plain];
    }

    /** A key that can place calls (Pro), as opposed to one that only reads. */
    public function canCall(): bool
    {
        return $this->scope === 'calls';
    }

    /** The token these credentials are for, within the current company. */
    public static function find_(?string $plain): ?self
    {
        return $plain === null || $plain === '' ? null : self::query()->where('token_hash', hash('sha256', $plain))->first();
    }
}
