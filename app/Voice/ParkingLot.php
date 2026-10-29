<?php

namespace App\Voice;

use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Call parking: each company has its own lot of slots 1 to 9 on the voice
 * server. A call parked on a slot waits there with hold music until any phone
 * in the company dials ** and the slot number.
 */
class ParkingLot
{
    public const SLOTS = 9;
    public const WAIT_SECONDS = 180; // then it rings whoever parked it again

    public function __construct(
        private readonly VoiceServer $voice,
        private readonly Tenancy $tenancy,
    ) {
    }

    /** The voice server's name for this company's lot. */
    public function name(): string
    {
        return 'qd-park-'.$this->tenancy->current()->getKey(); // throws, rather than share a lot, when no company is chosen
    }

    /** @return list<int> */
    public function occupied(): array
    {
        try {
            return $this->voice->parkedSlots($this->name());
        } catch (RuntimeException $e) {
            Log::warning('Could not read the parked calls.', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function isOccupied(int $slot): bool
    {
        return in_array($slot, $this->occupied(), true);
    }

    /**
     * A free slot, held for a moment so two people parking at once (the phone
     * asks for one before it transfers) don't get the same one. Null when every
     * slot is taken.
     */
    public function reserve(): ?int
    {
        $taken = $this->occupied();
        foreach (range(1, self::SLOTS) as $slot) {
            if (! in_array($slot, $taken, true) && Cache::add('park:'.$this->name().':'.$slot, true, 20)) {
                return $slot;
            }
        }

        return null;
    }

    /** Where a call sent to ** (or **N) is parked: slot N if it is free, otherwise any free slot. */
    public function slotFor(?int $wanted): ?int
    {
        return $wanted !== null && ! $this->isOccupied($wanted) ? $wanted : $this->reserve();
    }
}
