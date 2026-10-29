<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RingGroup extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'extension', 'strategy', 'ring_seconds', 'fallback'];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('position')->orderByPivot('position');
    }
}
