<?php

namespace App\Tenancy;

use App\Domain\Telephony\DialDecision;
use App\Models\Call;
use App\Support\CallLimits;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Hosted service: what calls to phone numbers count against, and cost
 * (tenancy.call_charges). Included minutes cover UK calls; UK calls past
 * them are charged per minute, and other numbers (international and special
 * UK ranges) always are, by destination. Works on the current company.
 */
final class CallCharges
{
    /** Kinds of call the monthly minutes cover. */
    public const INCLUDED = ['inbound', 'landline', 'mobile'];

    public static function enabled(): bool
    {
        return (bool) config('tenancy.enabled') && (bool) config('tenancy.call_charges.enabled');
    }

    /** inbound, landline, mobile, or other (international and special UK numbers). */
    public static function kind(string $direction, ?string $to): string
    {
        if ($direction === 'inbound') {
            return 'inbound';
        }
        $to = (string) $to;

        return match (true) {
            (bool) preg_match('/^\+44(1|2|3|80)/', $to) => 'landline',
            (bool) preg_match('/^\+447(?![06])/', $to) => 'mobile',
            default => 'other',
        };
    }

    /** Pence a minute to call a number (the rate card plus our markup), or null if it has no rate. */
    public static function otherRate(string $e164): ?float
    {
        return CallRates::pence($e164);
    }

    /** Whether a call to this number would use the monthly minutes (every call does while charges are off). */
    public static function usesMinutes(string $e164): bool
    {
        return self::counts(self::kind('outbound', $e164));
    }

    /** Whether calls of this kind use the monthly minutes. */
    public static function counts(string $kind): bool
    {
        return ! self::enabled() || in_array($kind, self::INCLUDED, true);
    }

    /** With charges on, a refusal for a number that can't be called because it has no rate. */
    public static function refusal(string $e164): ?DialDecision
    {
        if (! self::enabled() || self::usesMinutes($e164) || self::otherRate($e164) !== null) {
            return null;
        }

        return DialDecision::reject('Calls to this number aren\'t available on your plan. Contact your provider.');
    }

    /** How far UK calls may go past the monthly minutes, in seconds, before outgoing calls stop. */
    public static function overageSeconds(int $monthlyMinutes): int
    {
        return self::enabled() ? intdiv($monthlyMinutes * 60 * max(0, (int) config('tenancy.call_charges.overage_percent')), 100) : 0;
    }

    /**
     * A month's calls to and from phone numbers for the current company, and
     * what they cost beyond the plan: UK minutes past the allowance (by
     * kind, in the order the calls were made) and every "other" call.
     *
     * @return array{allowance_minutes: int, included_minutes: int, extra: array<string, array{minutes: int, pence_per_minute: float, pence: int}>,
     *               other: array<string, array{minutes: int, pence_per_minute: float, pence: int}>, total_pence: int}
     */
    public static function usage(int $monthlyMinutes, ?CarbonInterface $month = null): array
    {
        $start = CarbonImmutable::instance($month ?? now())->setTimezone('Europe/London')->startOfMonth();
        $calls = Call::query()
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('direction', 'inbound')->where('decision', '!=', 'reject'))
                ->orWhere(fn ($q) => $q->where('direction', 'outbound')->where('decision', DialDecision::NUMBER)))
            ->where('status', '!=', 'rejected')->where('created_at', '>=', $start)->where('created_at', '<', $start->addMonth())
            ->tap(fn ($q) => self::onOurTrunks($q))
            ->orderBy('created_at')->orderBy('id')->get(['direction', 'to', 'duration_seconds', 'created_at']);
        $max = CallLimits::maxCallMinutes();

        $left = $monthlyMinutes * 60;
        $extraSeconds = [];
        $extraPence = [];
        $other = [];
        $includedSeconds = 0;
        foreach ($calls as $call) {
            $seconds = $call->countedSeconds($max);
            $kind = self::kind($call->direction, $call->to);
            if ($kind === 'other') {
                // Charged from the first second, to the minute, at the rate for its destination.
                $rate = CallRates::lookup((string) $call->to);
                $prefix = $rate['prefix'] ?? self::otherPrefix((string) $call->to);
                $other[$prefix]['minutes'] = ($other[$prefix]['minutes'] ?? 0) + (int) ceil($seconds / 60);
                $other[$prefix]['pence_per_minute'] = $rate['pence'] ?? 0.0;

                continue;
            }
            $covered = min($left, $seconds);
            $left -= $covered;
            $includedSeconds += $covered;
            if ($seconds > $covered) {
                // UK minutes past the allowance, at the rate for where the call went.
                $rate = $kind === 'inbound' ? CallRates::inboundPence() : (CallRates::pence((string) $call->to) ?? 0.0);
                $extraSeconds[$kind] = ($extraSeconds[$kind] ?? 0) + $seconds - $covered;
                $extraPence[$kind] = ($extraPence[$kind] ?? 0.0) + ($seconds - $covered) / 60 * $rate;
            }
        }

        $extra = [];
        foreach ($extraSeconds as $kind => $seconds) {
            $minutes = (int) ceil($seconds / 60);
            $pence = (int) ceil($extraPence[$kind]);
            $extra[$kind] = ['minutes' => $minutes, 'pence_per_minute' => round($pence / max(1, $minutes), 2), 'pence' => $pence];
        }
        foreach ($other as $prefix => $line) {
            $other[$prefix]['pence'] = (int) ceil($line['minutes'] * $line['pence_per_minute']);
        }
        ksort($other);

        return ['allowance_minutes' => $monthlyMinutes, 'included_minutes' => intdiv($includedSeconds, 60), 'extra' => $extra, 'other' => $other,
            'total_pence' => array_sum(array_column($extra, 'pence')) + array_sum(array_column($other, 'pence'))];
    }

    /**
     * Whether calls on a trunk are ours to count and charge: only a trunk we
     * supply (marked "Ours" in the operator console). A company's own trunk
     * is paid for by them, so its calls use no minutes and cost nothing.
     */
    public static function ours(?\App\Models\SipTrunk $trunk): bool
    {
        return (bool) $trunk?->supplied_by_operator;
    }

    /** Limits a call log query to calls on trunks we supply. */
    public static function onOurTrunks(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereHas('trunk', fn ($q) => $q->where('supplied_by_operator', true));
    }

    /** A company's charges for a month, or null while call charges are off. @return array<string, mixed>|null */
    public static function forCompany(\App\Models\Tenant $tenant, ?CarbonInterface $month = null): ?array
    {
        return self::enabled() ? app(Tenancy::class)->run($tenant, fn () => self::usage((int) $tenant->monthly_minutes, $month)) : null;
    }

    /** The rate table prefix a number was charged under (or "+44…" style for one without a rate). */
    private static function otherPrefix(string $e164): string
    {
        $best = null;
        foreach (array_keys((array) config('tenancy.call_charges.other_pence')) as $prefix) {
            if (str_starts_with($e164, (string) $prefix) && ($best === null || strlen((string) $prefix) > strlen($best))) {
                $best = (string) $prefix;
            }
        }

        return $best ?? substr($e164, 0, 3);
    }
}
