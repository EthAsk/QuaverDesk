<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Domain\Routing\WeeklySchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class PhoneNumber extends Model
{
    use BelongsToTenant;

    protected $fillable = ['e164', 'label', 'source', 'sip_trunk_id', 'destination', 'hours', 'closed_destination', 'record_calls'];

    protected function casts(): array
    {
        return ['hours' => 'array', 'record_calls' => 'boolean'];
    }

    public function trunk(): BelongsTo
    {
        return $this->belongsTo(SipTrunk::class, 'sip_trunk_id');
    }

    public function schedule(): ?WeeklySchedule
    {
        if (! $this->hours) {
            return null;
        }

        try {
            return WeeklySchedule::fromArray($this->hours);
        } catch (InvalidArgumentException) {
            return null; // bad stored hours: treat as always open rather than drop calls
        }
    }
}
