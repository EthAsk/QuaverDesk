<?php

namespace App\Tenancy;

use App\Domain\Telephony\DialDecision;
use App\Models\Call;
use App\Models\PhoneNumber;
use App\Models\Recording;
use App\Models\Sound;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * What a hosted company has used against its plan. Self-hosted installs
 * have no quotas (their licence sets seats; everything else is their own).
 */
class Quotas
{
    public function __construct(private readonly Tenancy $tenancy)
    {
    }

    /** The current company's plan, or null when quotas don't apply. */
    public function tenant(): ?Tenant
    {
        return $this->tenancy->enabled() ? $this->tenancy->current() : null;
    }

    public function storageBytes(): int
    {
        return (int) Recording::query()->sum('bytes') + (int) Sound::query()->sum('bytes');
    }

    /** Whether $adding more bytes would go over the company's storage. */
    public function storageFull(int $adding = 0): bool
    {
        $tenant = $this->tenant();

        return $tenant !== null && $this->storageBytes() + $adding > $tenant->storage_mb * 1024 * 1024;
    }

    public function numbersFull(): bool
    {
        $tenant = $this->tenant();

        return $tenant !== null && PhoneNumber::query()->count() >= $tenant->max_numbers;
    }

    /**
     * Seconds of outside calls this calendar month (UK time), incoming and
     * outgoing, including calls still going. Running out stops outgoing calls
     * only: incoming calls are still answered.
     */
    public function outsideSecondsThisMonth(): int
    {
        $calls = Call::query()
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('direction', 'inbound')->where('decision', '!=', 'reject'))
                ->orWhere(fn ($q) => $q->where('direction', 'outbound')->where('decision', DialDecision::NUMBER)))
            ->where('status', '!=', 'rejected')->where('created_at', '>=', self::startOfMonth())
            ->get(['duration_seconds', 'created_at']);

        return (int) $calls->sum(fn (Call $c) => $c->duration_seconds ?? (int) $c->created_at->diffInSeconds(now(), true));
    }

    /** Seconds of outside calls left this month; null when there's no monthly allowance. */
    public function outsideSecondsLeft(): ?int
    {
        $tenant = $this->tenant();

        return $tenant === null ? null : max(0, $tenant->monthly_minutes * 60 - $this->outsideSecondsThisMonth());
    }

    public static function startOfMonth(): CarbonImmutable
    {
        return CarbonImmutable::now('Europe/London')->startOfMonth();
    }
}
