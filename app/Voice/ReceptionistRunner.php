<?php

namespace App\Voice;

use App\Ai\AiClient;
use App\Domain\Ai\ReceptionistPrompt;
use App\Domain\Ai\ReceptionistTurn;
use App\Domain\Calls\CallScript;
use App\Domain\Routing\Destination;
use App\Domain\Routing\InboundRouter;
use App\Domain\Routing\ReceptionistConfig;
use App\Domain\Routing\RoutingContext;
use App\Domain\Routing\RoutingDirectory;
use App\Domain\Routing\CallbackUrls;
use App\Http\Controllers\Webhooks\VoiceController;
use App\Jobs\SendVoicemailEmail;
use App\Models\Call;
use App\Models\Recording;
use App\Support\RecordingPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One turn of a call with the AI receptionist: what the caller just said is
 * transcribed by the company's transcription provider, then their summaries
 * provider decides what to say and do (ReceptionistPrompt). Anything that
 * goes wrong sends the caller to the receptionist's fallback, so a caller is
 * never stuck with a broken assistant.
 */
class ReceptionistRunner
{
    public const MSG_PARDON = 'Sorry, I didn\'t catch that. How can I help?';
    public const MSG_HANDING_OVER = 'Let me put you through to someone who can help.';
    public const MSG_TROUBLE = 'Sorry, I\'m having trouble. Let me put you through.';

    /** Silent turns in a row before the caller goes to the fallback. */
    public const MAX_SILENT = 2;

    private const STATE_MINUTES = 60;

    public function __construct(
        private readonly RoutingDirectory $directory,
        private readonly InboundRouter $router,
        private readonly CallbackUrls $urls,
        private readonly AiClient $ai,
        private readonly RecordingPolicy $policy,
    ) {
    }

    public function turn(Destination $to, RoutingContext $ctx, CallRequest $call): CallScript
    {
        $config = $this->directory->receptionist((int) $to->id);
        if ($config === null) {
            return $this->router->unavailable();
        }
        if (! $config->ready || $call->callId() === null) {
            return $this->fallback($config, $ctx);
        }

        $key = 'receptionist:'.$call->callId();
        $state = Cache::get($key, ['turns' => [], 'silent' => 0]);
        $listen = $this->urls->receptionist($to->withStep(min(255, $to->step + 1)), $ctx);

        $heard = $this->transcribe($call);
        if ($heard === false) {
            return $this->fallback($config, $ctx, self::MSG_TROUBLE);
        }
        if ($heard === '') {
            if (++$state['silent'] >= self::MAX_SILENT) {
                Cache::forget($key);

                return $this->fallback($config, $ctx, self::MSG_HANDING_OVER);
            }
            Cache::put($key, $state, now()->addMinutes(self::STATE_MINUTES));

            return (new CallScript())->say(self::MSG_PARDON)->append(InboundRouter::listen($listen));
        }

        $state['silent'] = 0;
        $state['turns'][] = ['caller', $heard];
        $callerTurns = count(array_filter($state['turns'], fn ($t) => $t[0] === 'caller'));
        $log = Call::query()->where('carrier_sid', $call->callId())->first();

        try {
            $reply = $this->ai->complete(ReceptionistPrompt::build($config, $state['turns'], $log?->from,
                now()->timezone('Europe/London'), $callerTurns >= $config->maxTurns), 400);
            $decision = ReceptionistPrompt::parse($reply, $config);
        } catch (Throwable $e) {
            Log::warning('AI receptionist could not decide.', ['call' => $call->callId(), 'error' => $e->getMessage()]);
            $decision = null;
        }
        if ($decision === null) {
            Cache::forget($key);

            return $this->fallback($config, $ctx, self::MSG_TROUBLE);
        }

        $state['turns'][] = ['receptionist', $decision->say];
        $script = (new CallScript())->say($decision->say);

        if ($decision->action === ReceptionistTurn::CONTINUE && $callerTurns < $config->maxTurns) {
            Cache::put($key, $state, now()->addMinutes(self::STATE_MINUTES));

            return $script->append(InboundRouter::listen($listen));
        }
        Cache::forget($key);

        return match ($decision->action) {
            ReceptionistTurn::TRANSFER => $script->append($this->router->route($config->routes[$decision->route - 1]['to'], $ctx->next())),
            ReceptionistTurn::MESSAGE => $this->takeMessage($config, $decision, $state['turns'], $log, $call)
                ? $script->hangup()
                : $this->fallback($config, $ctx, self::MSG_TROUBLE),
            ReceptionistTurn::END => $script->hangup(),
            default => $script->append($this->fallback($config, $ctx, self::MSG_HANDING_OVER)), // out of turns
        };
    }

    /** What the caller said: text, '' for nothing (silence), or false when the provider failed. */
    private function transcribe(CallRequest $call): string|false
    {
        $file = $call->recording();
        if ($file === null || VoiceController::wavSeconds((string) $file->getRealPath()) < 1) {
            return '';
        }
        try {
            return mb_substr(trim($this->ai->transcribe((string) file_get_contents((string) $file->getRealPath()), 'speech.wav')), 0, 2000);
        } catch (Throwable $e) {
            Log::warning('AI receptionist could not transcribe.', ['call' => $call->callId(), 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Saves the message to the receptionist's mailbox as a written voicemail
     * (no audio), emailed like any other.
     *
     * @param  list<array{0: string, 1: string}>  $turns
     */
    private function takeMessage(ReceptionistConfig $config, ReceptionistTurn $decision, array $turns, ?Call $log, CallRequest $call): bool
    {
        if ($config->mailboxId === null) {
            return false;
        }
        $m = $decision->message;
        $summary = trim(($m['name'] ?? 'The caller').($m['number'] ? " ({$m['number']})" : '').': '.$m['message']);
        $transcript = collect($turns)->map(fn ($t) => ($t[0] === 'caller' ? 'Caller: ' : 'Receptionist: ').$t[1])->implode("\n");

        $recording = Recording::query()->firstOrCreate(['carrier_sid' => $call->callId().'-reception'], [
            'kind' => Recording::VOICEMAIL,
            'call_id' => $log?->id,
            'mailbox_id' => $config->mailboxId,
            'from' => $log?->from,
            'to' => $log?->to,
            'status' => Recording::MESSAGE,
            'transcript' => $transcript,
            'summary' => $summary,
            'ai_status' => 'done',
            'expires_at' => now()->addDays($this->policy->retentionDays()),
        ]);
        if ($recording->wasRecentlyCreated) {
            SendVoicemailEmail::dispatch($recording->id);
        }

        return true;
    }

    private function fallback(ReceptionistConfig $config, RoutingContext $ctx, ?string $say = null): CallScript
    {
        $script = new CallScript();
        if ($say !== null) {
            $script->say($say);
        }

        return $script->append($this->router->route($config->fallback ?? Destination::hangup(), $ctx->next()));
    }
}
