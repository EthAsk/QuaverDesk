<?php

namespace App\Support;

use App\Domain\Licensing\Feature;
use App\Domain\Telephony\DialDecision;
use App\Domain\Telephony\Dialplan;
use App\Models\Call;
use App\Models\MonitorEvent;
use App\Models\Setting;
use App\Models\User;
use App\Voice\VoiceServer;
use RuntimeException;

/**
 * Supervisors listening in on live calls (Pro). It rings the supervisor's own phone and joins them to the call
 * in one of three ways: listen (they hear both sides), whisper (they also talk, and only the agent hears them)
 * or barge (they talk to both). Never on emergency calls, never on your own call, only for admins an owner has
 * chosen, only after someone has confirmed callers and staff are told, and every use is logged.
 */
class Monitoring
{
    public const MODES = ['listen', 'whisper', 'barge'];

    public const STARTED = 'started';

    public function __construct(
        private readonly LicenceManager $licences,
        private readonly VoiceServer $voice,
        private readonly DatabaseRoutingDirectory $directory,
    ) {
    }

    /** @return list<int> */
    public function supervisorIds(): array
    {
        return array_map('intval', (array) Setting::get('monitoring.supervisors', []));
    }

    /** @return array{by: int, name: string, at: string}|null */
    public function acknowledgement(): ?array
    {
        return Setting::get('monitoring.ack') ?: null;
    }

    public function acknowledge(User $by): void
    {
        Setting::put('monitoring.ack', ['by' => $by->id, 'name' => $by->name, 'at' => now()->toIso8601String()]);
    }

    public function setSupervisors(array $ids): void
    {
        $admins = User::query()->whereIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN])->whereIn('id', $ids)->pluck('id')->all();
        Setting::put('monitoring.supervisors', array_values($admins));
    }

    public function mayMonitor(User $user): bool
    {
        return $this->licences->allows(Feature::Monitoring) && $user->isAdmin() && $this->acknowledgement() !== null
            && in_array($user->id, $this->supervisorIds(), true);
    }

    /** One of the MODES result strings, or a refusal reason. Always logged. */
    public function start(User $supervisor, Call $call, string $mode): string
    {
        $result = $this->attempt($supervisor, $call, $mode);
        MonitorEvent::query()->create(['user_id' => $supervisor->id, 'call_id' => $call->id, 'mode' => $mode, 'result' => $result]);

        return $result;
    }

    private function attempt(User $supervisor, Call $call, string $mode): string
    {
        if ($call->decision === DialDecision::EMERGENCY || Dialplan::isEmergency((string) $call->to) || Dialplan::isEmergency((string) $call->from)) {
            return 'emergency';
        }
        // Only a call still going (the wallboard's own definition of live).
        if ($call->duration_seconds !== null || $call->leg !== null || $call->created_at->lt(now()->subHours(12)) || in_array($call->status, ['rejected', Call::STATUS_REFUSED], true)) {
            return 'not_live';
        }
        if ($call->user_id === $supervisor->id) {
            return 'own_call';
        }

        $phones = collect($this->directory->targetsFor($supervisor))
            ->map(fn ($t) => $t->kind === 'client' ? $t->value : (preg_match('/^sips?:([^@]+)@/', $t->value, $m) ? $m[1] : null))->filter()->values()->all();
        if ($phones === []) {
            return 'no_phone';
        }

        // The agent is the far side of an incoming call (the caller is on the first leg) and the first leg of an outgoing one.
        $agentLeg = $call->direction === 'inbound' ? 'b' : 'a';
        try {
            $this->voice->monitorCall($phones, $call->carrier_sid, $mode, $agentLeg);
        } catch (RuntimeException) {
            return 'failed';
        }

        return self::STARTED;
    }
}
