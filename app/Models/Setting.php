<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Small key/value store for a company's settings (licence key, calling rules).
 * Values are JSON-encoded so arrays and booleans round-trip.
 */
class Setting extends Model
{
    use BelongsToTenant;

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $raw = Cache::rememberForever(self::cacheKey($key), fn () => static::query()->where('key', $key)->value('value'));

        return $raw === null ? $default : json_decode($raw, true);
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
        Cache::forget(self::cacheKey($key));
    }

    public static function forget(string $key): void
    {
        static::query()->where('key', $key)->delete();
        Cache::forget(self::cacheKey($key));
    }

    private static function cacheKey(string $key): string
    {
        return 'setting:'.app(Tenancy::class)->current()->getKey().':'.$key;
    }
}
