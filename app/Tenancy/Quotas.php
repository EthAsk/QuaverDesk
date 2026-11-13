<?php

namespace App\Tenancy;

use App\Domain\Telephony\DialDecision;
use App\Models\Call;
use App\Models\PhoneNumber;
use App\Models\Recording;
use App\Models\Sound;
use App\Models\Tenant;
use App\Support\CallLimits;
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
     * Seconds of UK calls this calendar month (UK time), incoming and
     * outgoing to UK landlines and mobiles (CallCharges::INCLUDED), including
     * calls still going: what the monthly minutes cover. Running out stops
     * outgoing calls only (after any paid extra, see CallCharges): incoming
     * calls are still answered.
     */
    public function outsideSecondsThisMonth(): int
    {
        $calls = Call::query()
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('direction', 'inbound')->where('decision', '!=', 'reject'))
                ->orWhere(fn ($q) => $q->where('direction', 'outbound')->where('decision', DialDecision::NUMBER)))
            ->where('status', '!=', 'rejected')->where('created_at', '>=', self::startOfMonth())
            ->tap(fn ($q) => CallCharges::onOurTrunks($q)) // calls on the company's own trunks are theirs
            ->get(['direction', 'to', 'duration_seconds', 'created_at']);
        $max = CallLimits::maxCallMinutes();

        return (int) $calls->filter(fn (Call $c) => CallCharges::counts(CallCharges::kind($c->direction, $c->to)))
            ->sum(fn (Call $c) => $c->countedSeconds($max));
    }

    /** Seconds of UK calls left this month, including any paid extra; null when there's no monthly allowance. */
    public function outsideSecondsLeft(): ?int
    {
        $tenant = $this->tenant();

        return $tenant === null ? null
            : max(0, $tenant->monthly_minutes * 60 + CallCharges::overageSeconds($tenant->monthly_minutes) - $this->outsideSecondsThisMonth());
    }

    public static function startOfMonth(): CarbonImmutable
    {
        return CarbonImmutable::now('Europe/London')->startOfMonth();
    }
}
