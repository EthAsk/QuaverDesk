<?php

namespace App\Support;

use App\Domain\Telephony\Dialplan;
use App\Mail\ApiCallAlert;
use App\Models\ApiCall;
use App\Models\ApiToken;
use App\Models\Call;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * What a calling token may and may not do. Checked before a call is placed and again, by the normal outbound
 * rules, when the person answers. Each refusal is [HTTP status, message].
 */
class ApiCallRules
{
    public const MAX_IN_PROGRESS = 2;

    public const NUMBER = '/^\+[1-9]\d{7,14}$/';

    /** @return array{0: int, 1: string}|null */
    public function refusal(ApiToken $token, ?User $user, string $to): ?array
    {
        if (! config('quaverdesk.api_calls_enabled', true) || Setting::get('api.calls_paused', false)) {
            return [503, 'Placing calls over the API is switched off.'];
        }
        if ($user === null || ! in_array($user->id, array_map('intval', $token->allowed_user_ids ?? []), true) || $user->isForwardOnly() || $user->isSuspended()) {
            return [403, 'That token may not place calls for that person.'];
        }
        if (($why = $this->destination($token, $to)) !== null) {
            return [422, $why];
        }

        $now = now();
        $counts = fn ($since) => ApiCall::query()->where('api_token_id', $token->id)->where('status', '!=', 'refused')->where('created_at', '>=', $since)->count();
        if ($counts($now->subMinute()) >= $token->per_minute || $counts($now->subHour()) >= $token->per_hour) {
            $this->alert("a calling token ({$token->name}) went over its call limit");

            return [429, 'Too many calls requested. Try again shortly.'];
        }
        if ($this->minutesToday($token) >= $token->daily_minutes) {
            $this->alert("a calling token ({$token->name}) reached its daily call minutes");

            return [429, "This token's daily minutes are used up."];
        }
        if ($this->inProgress($token, null) >= self::MAX_IN_PROGRESS || $this->inProgress($token, $user->id) >= self::MAX_IN_PROGRESS) {
            return [429, 'Too many calls from this token are already in progress.'];
        }

        return null;
    }

    /** Why a number can't be called this way, or null. UK numbers only unless the token lists the country; never premium-rate or emergency. */
    public function destination(ApiToken $token, string $to): ?string
    {
        if (! preg_match(self::NUMBER, $to)) {
            return 'The number must be in international format, such as +447700900123.';
        }
        if (Dialplan::isEmergency(ltrim($to, '+')) || Dialplan::isEmergency($to)) {
            return 'Emergency numbers can not be called this way.';
        }
        if (str_starts_with($to, '+44')) {
            // 09 premium rate and 070 personal numbers.
            return preg_match('/^\+44(9|70)/', $to) ? 'Premium-rate numbers can not be called this way.' : null;
        }

        return $this->prefixOf($token, $to) === null ? 'Only UK numbers can be called with this token.' : null;
    }

    /** The country a number is in, as a dialling prefix: +44, or whichever of the token's listed countries it starts with. */
    public function prefixOf(ApiToken $token, string $to): ?string
    {
        if (str_starts_with($to, '+44')) {
            return '+44';
        }
        $match = collect($token->allowed_prefixes ?? [])->filter(fn ($p) => str_starts_with($to, $p))->sortByDesc(fn ($p) => strlen($p))->first();

        return $match ?: null;
    }

    /** The first call to a new country is worth an admin knowing about. */
    public function newCountry(ApiToken $token, ?string $prefix): bool
    {
        return $prefix !== null && ! ApiCall::query()->where('api_token_id', $token->id)->where('prefix', $prefix)->exists();
    }

    public function minutesToday(ApiToken $token): int
    {
        $since = now('Europe/London')->startOfDay()->utc();
        $ids = ApiCall::query()->where('api_token_id', $token->id)->where('created_at', '>=', $since)->pluck('id');

        return (int) ceil(Call::query()->whereIn('api_call_id', $ids)->sum('duration_seconds') / 60);
    }

    private function inProgress(ApiToken $token, ?int $userId): int
    {
        $ids = ApiCall::query()->where('api_token_id', $token->id)->whereIn('status', ['requested', 'answered'])
            ->where('created_at', '>=', now()->subMinutes(10))->when($userId, fn ($q) => $q->where('user_id', $userId))->pluck('id');

        return Call::query()->whereIn('api_call_id', $ids)->whereNull('duration_seconds')->count();
    }

    /** Tells the admins, at most once a day for each thing. */
    public function alert(string $what): void
    {
        if (! Cache::add('api-call-alert:'.app(\App\Tenancy\Tenancy::class)->id().':'.md5($what), true, now()->addDay())) {
            return;
        }
        foreach (User::query()->whereIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN])->get()->filter->canSignIn() as $admin) {
            Mail::to($admin)->queue(new ApiCallAlert($what));
        }
    }
}
