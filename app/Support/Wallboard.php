<?php

namespace App\Support;

use App\Models\Call;
use App\Models\CallQueue;
use App\Models\QueueCaller;
use App\Models\User;
use App\Voice\VoiceServer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What the live wallboard shows (Pro): calls in progress, each queue's
 * waiting callers and agents, who is on a call, and today's totals.
 * Built fresh on every poll from the call log, the queues and the voice
 * server's list of busy phones.
 */
class Wallboard
{
    /** Calls older than this with no end recorded are assumed lost, not live. */
    private const STALE_HOURS = 12;

    private const MAX_LIVE = 50;

    private const MAX_PEOPLE = 200;

    public function __construct(private readonly VoiceServer $voice)
    {
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $now = CarbonImmutable::now();
        $today = $now->timezone('Europe/London')->startOfDay()->utc();
        $busy = $this->busyUsers();

        $live = Call::query()->with('user:id,name')
            ->whereNull('leg')->whereNull('duration_seconds')
            ->whereNotIn('status', ['rejected', Call::STATUS_REFUSED])
            ->where('created_at', '>', $now->subHours(self::STALE_HOURS))
            ->latest('id')->limit(self::MAX_LIVE)->get();

        $todays = Call::query()->whereNull('leg')->where('created_at', '>=', $today);
        $totals = [
            'inbound' => (clone $todays)->where('direction', 'inbound')->count(),
            'outbound' => (clone $todays)->where('direction', 'outbound')->count(),
            'missed' => (clone $todays)->where('direction', 'inbound')->whereIn('status', ['no-answer', 'busy', 'failed'])->count(),
            'talk_seconds' => (int) (clone $todays)->whereIn('status', ['completed', 'answered'])->sum('duration_seconds'),
        ];

        $queues = CallQueue::query()->with(['agents:id,name'])->orderBy('name')->get()->map(function (CallQueue $q) use ($today, $busy) {
            $waiting = $q->callers()->waiting()->orderBy('joined_at')->get();
            $outcomes = $q->callers()->where('joined_at', '>=', $today)->whereNotNull('outcome')
                ->selectRaw('outcome, count(*) as n')->groupBy('outcome')->pluck('n', 'outcome');
            $agents = $q->agents;

            return [
                'id' => $q->id,
                'name' => $q->name,
                'waiting' => $waiting->count(),
                'longest_wait' => $waiting->isEmpty() ? 0 : $waiting->first()->waitedSeconds(),
                'agents' => $agents->count(),
                'agents_busy' => $busy === null ? null : $agents->filter(fn (User $u) => isset($busy[$u->id]))->count(),
                'answered' => (int) ($outcomes[QueueCaller::ANSWERED] ?? 0),
                'abandoned' => (int) ($outcomes[QueueCaller::ABANDONED] ?? 0),
                'overflowed' => (int) ($outcomes[QueueCaller::OVERFLOW] ?? 0),
            ];
        })->values();

        $callsToday = Call::query()->whereNull('leg')->where('created_at', '>=', $today)->whereNotNull('user_id')
            ->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');
        $people = User::query()->with('queues:id,name')->orderBy('name')->limit(self::MAX_PEOPLE)->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'extension' => $u->dialable(),
                'status' => $busy === null ? 'unknown' : (isset($busy[$u->id]) ? 'busy' : 'free'),
                'queues' => $u->queues->filter(fn ($q) => (bool) $q->pivot->logged_in)->pluck('name')->values(),
                'calls_today' => (int) ($callsToday[$u->id] ?? 0),
            ])->values();

        return [
            'at' => $now->toIso8601String(),
            'voice_server' => $busy !== null,
            'totals' => $totals,
            'live' => $live->map(fn (Call $c) => [
                'id' => $c->id,
                'direction' => $c->direction,
                'from' => $c->from,
                'to' => $c->to,
                'person' => $c->user?->name,
                'seconds' => max(0, (int) $c->created_at->diffInSeconds($now, true)),
            ])->values(),
            'queues' => $queues,
            'people' => $people,
        ];
    }

    /** User ids with a phone on a call, or null if the voice server can't say. @return array<int, true>|null */
    private function busyUsers(): ?array
    {
        try {
            $phones = $this->voice->busyPhones();
        } catch (Throwable $e) {
            Log::info('Wallboard: the voice server could not list busy phones.', ['error' => $e->getMessage()]);

            return null;
        }
        $users = [];
        foreach ($phones as $phone) {
            if (preg_match('/^(?:user-|u)(\d{1,9})(?:-[a-f0-9]{6})?$/', $phone, $m)) {
                $users[(int) $m[1]] = true;
            }
        }

        return $users;
    }
}
