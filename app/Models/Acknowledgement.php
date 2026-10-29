<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Acknowledgement extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['user_id', 'version', 'items', 'ip_address', 'user_agent', 'accepted_at'];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'accepted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Has an owner or admin accepted the current version for this install? */
    public static function currentAccepted(): bool
    {
        return static::query()->where('version', config('voip.terms_version'))->exists();
    }
}
