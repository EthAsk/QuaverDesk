<?php

namespace App\Support;

use App\Domain\Telephony\DialDecision;
use App\Mail\CallLimitReached;
use App\Models\Call;
use App\Models\Setting;
use App\Models\User;
use App\Tenancy\Quotas;
use App\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Toll-fraud protection for calls to phone numbers. A hijacked account or
 * phone can't run up a large bill: each person has a daily allowance of calls
 * and minutes, the whole system has a cap on simultaneous outside calls, and
 * every call is cut off at a maximum length by the voice server itself.
 * Admins get an email the first time each limit is hit in a day.
 *
 * Emergency calls are never limited. Calls between colleagues are free and
 * not counted.
 *
 * On the hosted service each company's plan also caps its lines and its
 * minutes in a month, and the whole service has its own caps as a backstop.
 */
final class CallLimits
{
    public const DEFAULTS = [
        'calls_per_day' => 100,      // per person
        'minutes_per_day' => 240,    // per person
        'max_call_minutes' => 120,   // any single call
        'concurrent' => 4,           // whole system
    ];

    public function __construct(private readonly Quotas $quotas, private readonly Tenancy $tenancy)
    {
    }

    /** @return array{calls_per_day: int, minutes_per_day: int, max_call_minutes: int, concurrent: int} */
    public function settings(): array
    {
        $limits = array_map('intval', (array) Setting::get('calling.limits', []) + self::DEFAULTS);
        if ($tenant = $this->quotas->tenant()) {
            $limits['concurrent'] = min($limits['concurrent'], $tenant->concurrent_calls);
        }

        return $limits;
    }

    /**
     * A refusal if this call would go over a limit; otherwise null.
     */
    public function refusal(User $user, DialDecision $decision): ?DialDecision
    {
        if ($decision->type !== DialDecision::NUMBER) {
            return null; // emergency, internal and refused calls are never limited
        }

        $limits = $this->settings();
        $today = $this->outsideCalls()->where('user_id', $user->id)->where('created_at', '>=', self::startOfDay());

        if ((clone $today)->count() >= $limits['calls_per_day']) {
            return $this->refuse($user, 'calls', "You've reached today's limit of {$limits['calls_per_day']} outside calls.");
        }
        if ($this->secondsUsed($user) >= $limits['minutes_per_day'] * 60) {
            return $this->refuse($user, 'minutes', "You've used today's {$limits['minutes_per_day']} minutes of outside calls.");
        }
        if ($this->inProgress()->count() >= $limits['concurrent']) {
            return $this->refuse($user, 'concurrent', "All {$limits['concurrent']} outside lines are in use. Try again in a moment.");
        }
        if ($this->quotas->outsideSecondsLeft() === 0) {
            return $this->refuse($user, 'monthly', "Your company has used this month's {$this->quotas->tenant()->monthly_minutes} minutes of outside calls.");
        }

        return $this->serviceRefusal();
    }

    /** The hosted service's own caps, across every company. */
    private function serviceRefusal(): ?DialDecision
    {
        if (! $this->tenancy->enabled()) {
            return null;
        }

        [$going, $seconds] = $this->tenancy->everywhere(fn () => [
            $this->inProgress(480)->count(), // the longest call any company may allow
            $this->seconds($this->outsideCalls()->where('created_at', '>=', self::startOfDay())),
        ]);
        $cap = match (true) {
            $going >= (int) config('tenancy.max_concurrent_calls') => 'concurrent',
            $seconds >= (int) config('tenancy.max_minutes_per_day') * 60 => 'minutes',
            default => null,
        };
        if ($cap === null) {
            return null;
        }

        if (Cache::add('call-limit:service:'.$cap.':'.self::startOfDay()->toDateString(), true, now()->addDay())) {
            Log::critical('The hosted service reached its cap on outside calls; calls to phone numbers are being refused for every company.', [
                'cap' => $cap, 'calls_in_progress' => $going, 'minutes_today' => intdiv($seconds, 60),
            ]);
        }

        return DialDecision::reject('Calls to phone numbers are unavailable right now. Please try again later.');
    }

    /** The longest this call may last: the per-call maximum, or what's left of today's minutes. */
    public function maxSeconds(User $user): int
    {
        $limits = $this->settings();

        $left = min($limits['max_call_minutes'] * 60, $limits['minutes_per_day'] * 60 - $this->secondsUsed($user),
            $this->quotas->outsideSecondsLeft() ?? PHP_INT_MAX);

        return max(60, $left);
    }

    private function refuse(User $user, string $limit, string $message): DialDecision
    {
        // One email per limit, per day: per person, or for the whole company for its lines and month.
        $who = in_array($limit, ['concurrent', 'monthly'], true) ? 'all' : $user->id;
        $key = 'call-limit:'.$user->tenant_id.':'.$limit.':'.$who.':'.self::startOfDay()->toDateString();
        if (Cache::add($key, true, now()->addDay())) {
            $admins = User::query()->whereIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN])->get();
            foreach ($admins as $admin) {
                Mail::to($admin)->queue(new CallLimitReached($admin, $user, $message));
            }
        }

        return DialDecision::reject($message.' Ask an administrator if you need more.');
    }

    private function secondsUsed(User $user): int
    {
        return $this->seconds($this->outsideCalls()->where('user_id', $user->id)->where('created_at', '>=', self::startOfDay()));
    }

    private function seconds($query): int
    {
        // Calls still going count for the time so far.
        return (int) $query->get(['duration_seconds', 'created_at'])
            ->sum(fn (Call $c) => $c->duration_seconds ?? (int) $c->created_at->diffInSeconds(now(), true));
    }

    /**
     * Outside calls still going (calls that never reported an end stop counting
     * after the longest allowed call, or after $minutes across the service).
     */
    private function inProgress(?int $minutes = null)
    {
        $oldest = now()->subMinutes(($minutes ?? $this->settings()['max_call_minutes']) + 5);

        return $this->outsideCalls()->whereNull('duration_seconds')->where('created_at', '>=', $oldest);
    }

    private function outsideCalls()
    {
        return Call::query()->where('direction', 'outbound')->where('decision', DialDecision::NUMBER)
            ->where('status', '!=', 'rejected');
    }

    private static function startOfDay(): CarbonImmutable
    {
        return CarbonImmutable::now('Europe/London')->startOfDay();
    }
}
