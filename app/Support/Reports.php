<?php

namespace App\Support;

use App\Models\Call;
use App\Models\CallQueue;
use App\Models\QueueCaller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Historical call reports (UK time). Built from the call log and the queues'
 * callers; the busiest company's year of calls is a few hundred thousand rows
 * at most, and everything is read in a single pass without loading it all.
 */
class Reports
{
    public const TZ = 'Europe/London';

    public const MAX_DAYS = 366;

    /** Default for "answered within": most call centres use 20–30 seconds. */
    public const SERVICE_LEVEL_SECONDS = 30;

    public readonly CarbonImmutable $start;

    public readonly CarbonImmutable $end;

    /** From the start of $from to the end of $to, UK days. */
    public function __construct(CarbonImmutable|string $from, CarbonImmutable|string $to)
    {
        $this->start = CarbonImmutable::parse($from, self::TZ)->startOfDay();
        $this->end = CarbonImmutable::parse($to, self::TZ)->endOfDay();
    }

    /** Calls in the range as a person would count them: not the legs inside a transfer or follow-me ring. */
    public function calls(): Builder
    {
        return Call::query()->where(fn (Builder $q) => $q->whereNull('leg')->orWhere('leg', Call::LEG_ON_NET))
            ->where('created_at', '>=', $this->start->utc())->where('created_at', '<=', $this->end->utc());
    }

    /**
     * @return array{summary: array<string, int|float>, days: array<string, array{inbound: int, outbound: int, missed: int}>, hours: array<int, int>}
     */
    public function overview(): array
    {
        $s = ['inbound' => 0, 'outbound' => 0, 'internal' => 0, 'answered' => 0, 'missed' => 0, 'talk_seconds' => 0];
        $days = [];
        for ($d = $this->start; $d <= $this->end; $d = $d->addDay()) {
            $days[$d->format('Y-m-d')] = ['inbound' => 0, 'outbound' => 0, 'missed' => 0];
        }
        $hours = array_fill(0, 24, 0);

        foreach ($this->calls()->select(['created_at', 'direction', 'status', 'duration_seconds'])->cursor() as $c) {
            $local = CarbonImmutable::parse($c->created_at)->timezone(self::TZ);
            $day = $local->format('Y-m-d');
            $answered = in_array($c->status, ['completed', 'answered'], true) && (int) $c->duration_seconds > 0;
            $missed = $c->direction === 'inbound' && ! $answered && in_array($c->status, ['no-answer', 'busy', 'failed', 'ended', 'ringing', 'initiated'], true);

            $key = in_array($c->direction, ['inbound', 'outbound', 'internal'], true) ? $c->direction : 'internal';
            $s[$key]++;
            if ($answered) {
                $s['answered']++;
                $s['talk_seconds'] += (int) $c->duration_seconds;
            }
            if ($missed) {
                $s['missed']++;
                $days[$day]['missed']++;
            }
            if (isset($days[$day]) && $key !== 'internal') {
                $days[$day][$key]++;
            }
            if ($c->direction === 'inbound') {
                $hours[(int) $local->format('G')]++;
            }
        }
        $s['answer_rate'] = $s['inbound'] > 0 ? (int) round(100 * ($s['inbound'] - $s['missed']) / $s['inbound']) : null;
        $s['avg_seconds'] = $s['answered'] > 0 ? (int) round($s['talk_seconds'] / $s['answered']) : 0;

        return ['summary' => $s, 'days' => $days, 'hours' => $hours];
    }

    /**
     * Each queue: callers offered, answered, abandoned and overflowed; average and longest wait; and the
     * share answered within the service level. Wait is joining to being answered or giving up, so it
     * includes the time agents' phones rang.
     *
     * @return list<array<string, mixed>>
     */
    public function queues(int $serviceLevel = self::SERVICE_LEVEL_SECONDS): array
    {
        $rows = [];
        foreach (CallQueue::query()->orderBy('name')->get() as $queue) {
            $r = ['name' => $queue->name, 'offered' => 0, 'answered' => 0, 'abandoned' => 0, 'overflowed' => 0, 'avg_wait' => 0, 'longest_wait' => 0, 'service_level' => null];
            $waits = 0;
            $within = 0;
            $counted = 0;
            $callers = $queue->callers()->where('joined_at', '>=', $this->start->utc())->where('joined_at', '<=', $this->end->utc())
                ->whereNotNull('outcome')->select(['joined_at', 'ended_at', 'outcome'])->cursor();
            foreach ($callers as $c) {
                $wait = $c->ended_at ? max(0, (int) $c->joined_at->diffInSeconds($c->ended_at, true)) : 0;
                $r['offered']++;
                match ($c->outcome) {
                    QueueCaller::ANSWERED => $r['answered']++,
                    QueueCaller::ABANDONED => $r['abandoned']++,
                    QueueCaller::OVERFLOW => $r['overflowed']++,
                    default => null,
                };
                $waits += $wait;
                $counted++;
                $r['longest_wait'] = max($r['longest_wait'], $wait);
                if ($c->outcome === QueueCaller::ANSWERED && $wait <= $serviceLevel) {
                    $within++;
                }
            }
            $r['avg_wait'] = $counted > 0 ? (int) round($waits / $counted) : 0;
            $r['service_level'] = $r['offered'] > 0 ? (int) round(100 * $within / $r['offered']) : null;
            $rows[] = $r;
        }

        return $rows;
    }

    /** @return list<array{name: string, taken: int, made: int, missed: int, talk_seconds: int}> */
    public function agents(): array
    {
        $by = [];
        foreach ($this->calls()->whereNotNull('user_id')->select(['user_id', 'direction', 'status', 'duration_seconds'])->cursor() as $c) {
            $a = &$by[$c->user_id];
            $a ??= ['taken' => 0, 'made' => 0, 'missed' => 0, 'talk_seconds' => 0];
            $answered = in_array($c->status, ['completed', 'answered'], true) && (int) $c->duration_seconds > 0;
            if ($c->direction === 'outbound') {
                $a['made']++;
            } elseif ($answered) {
                $a['taken']++;
            } elseif ($c->direction === 'inbound') {
                $a['missed']++;
            }
            $a['talk_seconds'] += $answered ? (int) $c->duration_seconds : 0;
            unset($a);
        }

        $names = User::query()->whereIn('id', array_keys($by))->pluck('name', 'id');

        return collect($by)->map(fn ($a, $id) => ['name' => $names[$id] ?? "User {$id}"] + $a)
            ->sortByDesc(fn ($a) => $a['taken'] + $a['made'])->values()->all();
    }

    /** A cell safe to open in a spreadsheet: text starting with = + - @ is read as a formula otherwise. */
    public static function cell(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && str_contains("=+-@\t\r", $text[0]) ? "'".$text : $text;
    }
}
