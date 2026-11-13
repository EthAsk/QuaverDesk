<?php

namespace App\Voice;

use App\Domain\Licensing\Feature;
use App\Models\Call;
use App\Models\CallQueue;
use App\Models\QueueCaller;
use App\Models\User;
use App\Support\LicenceManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Who a queue rings for the caller whose turn it is. Only agents who are free
 * are considered (the runner has already left out anyone on a call).
 *
 *  - ring_all      every free agent together (the original behaviour)
 *  - longest_idle  the one who finished their last call longest ago
 *  - round_robin   the next one after whoever was rung last
 *  - fewest_calls  the one with the fewest calls so far today
 *  - priority      (Pro) every free agent of the best priority level that has one:
 *                  first choices, then backups, and so on
 *
 * The single-agent strategies remember who has been rung for this caller, so if
 * nobody picks up the next round tries someone else; once everyone has been
 * tried they start again.
 */
class QueueStrategy
{
    public const RING_ALL = 'ring_all';
    public const LONGEST_IDLE = 'longest_idle';
    public const ROUND_ROBIN = 'round_robin';
    public const FEWEST_CALLS = 'fewest_calls';
    public const PRIORITY = 'priority';

    public const LABELS = [
        self::RING_ALL => 'Ring every free agent together',
        self::LONGEST_IDLE => 'Longest idle first',
        self::ROUND_ROBIN => 'Round robin',
        self::FEWEST_CALLS => 'Fewest calls today',
        self::PRIORITY => 'By priority: first choices, then backups',
    ];

    public function __construct(private readonly LicenceManager $licences)
    {
    }

    /** The strategy a queue actually uses: priority needs the Pro plan, and anything unknown rings everyone. */
    public function effective(CallQueue $queue): string
    {
        $s = (string) $queue->strategy;

        return match (true) {
            $s === self::PRIORITY && $this->licences->allows(Feature::QueueSkills) => self::PRIORITY,
            in_array($s, [self::LONGEST_IDLE, self::ROUND_ROBIN, self::FEWEST_CALLS], true) => $s,
            default => self::RING_ALL,
        };
    }

    /**
     * @param  Collection<int, list<\App\Domain\Calls\DialTarget>>  $free  free agents' phones, keyed by user id
     * @param  Collection<int, User>  $agents  the queue's logged-in agents (with their priority on the pivot)
     * @return Collection<int, list<\App\Domain\Calls\DialTarget>>
     */
    public function pick(CallQueue $queue, QueueCaller $caller, Collection $free, Collection $agents): Collection
    {
        $strategy = $this->effective($queue);
        if ($strategy === self::RING_ALL || $free->count() <= 1) {
            return $free;
        }

        $rung = array_map('intval', $caller->rung_agents ?? []);
        $candidates = $free->except($rung);
        if ($candidates->isEmpty()) {
            $candidates = $free;
            $rung = [];
        }

        $ids = $candidates->keys()->map(fn ($id) => (int) $id)->sort()->values();
        $chosen = match ($strategy) {
            self::LONGEST_IDLE => collect([$this->longestIdle($ids)]),
            self::FEWEST_CALLS => collect([$this->fewestCalls($ids)]),
            self::ROUND_ROBIN => collect([$ids->first(fn ($id) => $id > (int) $queue->last_agent_id) ?? $ids->first()]),
            self::PRIORITY => $this->bestTier($ids, $agents),
        };

        $caller->forceFill(['rung_agents' => [...$rung, ...$chosen->all()]])->save();
        if ($strategy === self::ROUND_ROBIN) {
            $queue->forceFill(['last_agent_id' => $chosen->first()])->saveQuietly();
        }

        return $candidates->only($chosen->all());
    }

    /** Whoever has gone longest without finishing a call (never having had one counts as longest). */
    private function longestIdle(Collection $ids): int
    {
        $last = Call::query()->whereIn('user_id', $ids)->whereNotNull('duration_seconds')
            ->selectRaw('user_id, max(updated_at) as finished')->groupBy('user_id')->pluck('finished', 'user_id');

        return (int) $ids->sortBy(fn ($id) => [$last[$id] ?? '0000-00-00 00:00:00', $id])->first();
    }

    private function fewestCalls(Collection $ids): int
    {
        $since = CarbonImmutable::now('Europe/London')->startOfDay()->utc();
        $counts = Call::query()->whereIn('user_id', $ids)->where('created_at', '>=', $since)
            ->where(fn ($q) => $q->whereNull('leg')->orWhere('leg', Call::LEG_ON_NET))
            ->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');

        return (int) $ids->sortBy(fn ($id) => [(int) ($counts[$id] ?? 0), $id])->first();
    }

    /** Every free agent of the lowest priority number that has any. @return Collection<int, int> */
    private function bestTier(Collection $ids, Collection $agents): Collection
    {
        $priority = $agents->mapWithKeys(fn (User $u) => [$u->id => (int) ($u->pivot->priority ?? 1)]);
        $best = $ids->map(fn ($id) => $priority[$id] ?? 1)->min();

        return $ids->filter(fn ($id) => ($priority[$id] ?? 1) === $best)->values();
    }
}
