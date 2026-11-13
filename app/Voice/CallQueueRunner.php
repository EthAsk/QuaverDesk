<?php

namespace App\Voice;

use App\Domain\Calls\CallScript;
use App\Domain\Calls\DialTarget;
use App\Domain\Licensing\Feature;
use App\Domain\Routing\CallbackUrls;
use App\Domain\Routing\Destination;
use App\Domain\Routing\InboundRouter;
use App\Domain\Routing\RoutingContext;
use App\Models\CallQueue;
use App\Models\QueueCaller;
use App\Models\User;
use App\Support\DatabaseRoutingDirectory;
use App\Support\LicenceManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Runs a call queue, one round per request. Each round the caller is either
 * rung through to the free agents (when it's their turn: there are at least
 * as many free agents as callers ahead of them, them included) with hold
 * music instead of ringing, or hears hold music for a while and comes back.
 * They hear their place in the queue when they join, when it changes, and
 * every minute. After the queue's longest wait, or straight away when nobody
 * is logged in to it, they go to its overflow.
 *
 * An agent is free when logged in to the queue and none of their phones is on
 * a call. Rounds don't count as routing hops (see InboundRouter::MAX_HOPS):
 * the longest wait bounds them instead.
 */
class CallQueueRunner
{
    public const MSG_FIRST = 'You are first in the queue. Thank you for holding.';
    public const MSG_POSITION = 'You are number %d in the queue. Thank you for holding.';
    public const ANNOUNCE_EVERY_SECONDS = 60;

    public function __construct(
        private readonly InboundRouter $router,
        private readonly CallbackUrls $urls,
        private readonly DatabaseRoutingDirectory $directory,
        private readonly LicenceManager $licences,
        private readonly VoiceServer $voice,
    ) {
    }

    public function round(Destination $to, RoutingContext $ctx, CallRequest $call, bool $afterRing): CallScript
    {
        $queue = $this->licences->allows(Feature::CallQueues) ? CallQueue::query()->with('holdSound')->find($to->id) : null;
        $callId = $call->callId();
        if ($queue === null || $callId === null) {
            return $this->router->unavailable();
        }

        $caller = QueueCaller::query()->firstOrCreate(
            ['call_queue_id' => $queue->id, 'carrier_sid' => $callId],
            ['from' => substr($call->from(), 0, 64), 'joined_at' => now(), 'seen_at' => now()],
        );
        if ($caller->ended_at !== null) {
            // Back in the same queue (a menu leading here again): a new place at the back.
            $caller->forceFill(['joined_at' => now(), 'ended_at' => null, 'outcome' => null, 'announced_position' => null, 'announced_at' => null, 'rung_agents' => null]);
        }

        if ($afterRing && in_array($call->dialStatus(), ['completed', 'answered'], true)) {
            $caller->close(QueueCaller::ANSWERED);

            return (new CallScript())->hangup();
        }

        $script = new CallScript();
        if ($to->step === 0) {
            $script->answer(); // so the caller hears the music, not ringing
            if (filled($queue->greeting)) {
                $script->say($queue->greeting);
            }
        }

        $agents = $queue->agents()->with('deskPhones')->get();
        $remaining = $queue->max_wait_seconds - $caller->waitedSeconds();
        if ($remaining <= 0 || ($agents->isEmpty() && $queue->overflow_when_empty)) {
            $caller->close(QueueCaller::OVERFLOW);

            return $script->append($this->overflow($queue, $ctx));
        }

        $caller->forceFill(['seen_at' => now(), 'ringing_until' => null])->save();
        $position = $this->position($caller);
        $free = $this->freeAgents($agents);
        $next = $this->urls->queue($to->withStep(min(999, $to->step + 1)), $ctx);

        if ($queue->announce_position && $this->shouldAnnounce($caller, $position)) {
            $script->say($position === 1 ? self::MSG_FIRST : sprintf(self::MSG_POSITION, $position));
            $caller->forceFill(['announced_position' => $position, 'announced_at' => now()])->save();
        }

        if ($position > $free->count()) {
            return $script->hold($queue->holdMusicUrl(), min(CallQueue::HOLD_SLICE_SECONDS, $remaining))->redirect($next);
        }

        $timeout = max(5, min($queue->ring_seconds, $remaining));
        $caller->forceFill(['ringing_until' => now()->addSeconds($timeout)])->save();
        $options = ['timeout' => $timeout, 'holdMusic' => $queue->holdMusicUrl(), 'action' => $this->urls->queue($to->withStep(min(999, $to->step + 1)), $ctx, true)];
        if ($ctx->record) {
            $options += ['record' => true, 'recordingStatusCallback' => $this->urls->recordingSaved()];
        }

        $ring = app(QueueStrategy::class)->pick($queue, $caller, $free, $agents);

        return $script->dial(array_merge(...$ring->values()->all()), $options);
    }

    /**
     * The call ended while in a queue (the caller hung up, or a call an agent
     * took finished). It was answered if it outlasted the agents' ringing.
     */
    public static function callEnded(string $callId): void
    {
        foreach (QueueCaller::query()->where('carrier_sid', $callId)->whereNull('ended_at')->get() as $caller) {
            $answered = $caller->ringing_until !== null && now()->gt($caller->ringing_until->copy()->addSeconds(3));
            $caller->close($answered ? QueueCaller::ANSWERED : QueueCaller::ABANDONED);
        }
    }

    /** Callers still waiting ahead of this one, plus one. */
    private function position(QueueCaller $caller): int
    {
        return QueueCaller::query()->where('call_queue_id', $caller->call_queue_id)->whereKeyNot($caller->id)->waiting()
            ->where(fn ($q) => $q->where('joined_at', '<', $caller->joined_at)
                ->orWhere(fn ($q) => $q->where('joined_at', $caller->joined_at)->where('id', '<', $caller->id)))
            ->count() + 1;
    }

    private function shouldAnnounce(QueueCaller $caller, int $position): bool
    {
        return $caller->announced_at === null
            || $caller->announced_position !== $position
            || $caller->announced_at->lt(now()->subSeconds(self::ANNOUNCE_EVERY_SECONDS));
    }

    /**
     * Each free agent's phones. An agent with a phone on a call (or ringing)
     * is busy; if the voice server can't say, everyone counts as free.
     *
     * @param  Collection<int, User>  $agents
     * @return Collection<int, list<DialTarget>> keyed by user id
     */
    private function freeAgents(Collection $agents): Collection
    {
        try {
            $busy = array_flip($this->voice->busyPhones());
        } catch (RuntimeException $e) {
            Log::warning('Could not tell which phones are busy; ringing every agent in the queue.', ['error' => $e->getMessage()]);
            $busy = [];
        }

        return $agents->mapWithKeys(fn (User $u) => [$u->id => $this->directory->targetsFor($u)])
            ->filter(fn (array $targets) => $targets !== [] && ! array_filter($targets, fn (DialTarget $t) => isset($busy[self::phoneOf($t)])));
    }

    /** The SIP username a dial target rings: user-12, or a desk phone's u12-ab12cd. */
    private static function phoneOf(DialTarget $t): string
    {
        return $t->kind === 'sip' && preg_match('/^sips?:([^@]+)@/', $t->value, $m) ? $m[1] : $t->value;
    }

    private function overflow(CallQueue $queue, RoutingContext $ctx): CallScript
    {
        $to = Destination::parse($queue->overflow);
        if ($to === null || ($to->type === Destination::QUEUE && $to->id === $queue->id)) {
            return $this->router->unavailable();
        }

        return $this->router->route($to, $ctx->next());
    }
}
