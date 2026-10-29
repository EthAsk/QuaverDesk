<?php

namespace App\Domain\Calls;

use App\Domain\Telephony\DialDecision;
use App\Domain\Telephony\Dialplan;

/** Turns a softphone dial decision into a CallScript. */
final class OutboundScript
{
    public const MSG_NO_CALLER_ID = 'This phone system has no outgoing caller ID set up. Please contact your administrator.';
    public const MSG_NO_TRUNK = 'This phone system has no phone line for outside calls yet. Please contact your administrator.';

    /**
     * @param  ?string  $callerId  number shown to the person called
     * @param  ?string  $trunk  voice server gateway for phone-network calls; without one they can't be made
     * @param  ?array{status: string, whisper: string}  $recording  when set, the call is recorded; the
     *                                                              called person first hears $recording['whisper']
     * @param  ?string  $action  gets the dial outcome once the call ends
     * @param  ?int  $maxSeconds  calls to phone numbers are cut off after this long (toll-fraud limit)
     */
    public static function fromDecision(
        DialDecision $decision,
        ?string $callerId,
        ?string $trunk = null,
        ?array $recording = null,
        ?string $action = null,
        ?int $maxSeconds = null,
    ): CallScript {
        $script = new CallScript();

        $options = $action ? ['action' => $action] : [];
        // Only calls to phone numbers are recorded: never emergency calls, never internal ones.
        if ($recording !== null && $decision->type === DialDecision::NUMBER) {
            $options['record'] = true;
            $options['recordingStatusCallback'] = $recording['status'];
        }
        // Never cut off an emergency call.
        if ($maxSeconds !== null && $decision->type === DialDecision::NUMBER) {
            $options['maxSeconds'] = $maxSeconds;
        }
        // Never delay or record an emergency call.
        $whisper = ($recording !== null && $decision->type === DialDecision::NUMBER) ? $recording['whisper'] : null;

        return match ($decision->type) {
            DialDecision::CLIENT => $script->dial([DialTarget::client($decision->target)], $options + ['timeout' => 25]),

            DialDecision::NUMBER, DialDecision::EMERGENCY => $callerId && $trunk
                ? $script->dial([DialTarget::number($decision->target, $trunk, $whisper)], $options + ['callerId' => $callerId])
                : $script->say(match (true) {
                    $decision->type === DialDecision::EMERGENCY => Dialplan::noEmergencyService()->message,
                    $trunk === null => self::MSG_NO_TRUNK,
                    default => self::MSG_NO_CALLER_ID,
                })->hangup(),

            default => $script->say($decision->message ?? 'This call cannot be connected.')->hangup(),
        };
    }
}
