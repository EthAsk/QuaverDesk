<?php

namespace App\Domain\Routing;

use App\Domain\Calls\CallScript;
use DateTimeImmutable;

/**
 * Turns "a call arrived for this destination" into a CallScript, one step at
 * a time. Each step that needs the caller's response or a dial outcome ends
 * with a callback URL that resumes routing here.
 *
 * A RoutingContext travels with every callback: a hop counter (so a
 * misconfiguration such as an IVR option pointing back at itself can never
 * loop forever) and whether answered calls are recorded.
 */
final class InboundRouter
{
    public const MAX_HOPS = 12;
    public const IVR_ATTEMPTS = 3;
    public const USER_RING_SECONDS = 25;

    public const MSG_UNAVAILABLE = 'Sorry, nobody is available to take your call. Please try again later. Goodbye.';
    public const MSG_INVALID = 'Sorry, that is not a valid option.';
    public const MSG_VOICEMAIL_THANKS = 'Thank you. Your message has been recorded. Goodbye.';
    public const DEFAULT_DISCLOSURE = 'This call may be recorded and transcribed for training and quality purposes.';

    public function __construct(
        private readonly RoutingDirectory $directory,
        private readonly CallbackUrls $urls,
    ) {
    }

    /**
     * First step for a call to one of our numbers, honouring opening hours.
     * When recording, the caller hears the disclosure before anything else.
     */
    public function forNumber(
        Destination $open,
        ?WeeklySchedule $hours,
        ?Destination $closed,
        DateTimeImmutable $now,
        bool $record = false,
        ?string $disclosure = null,
    ): CallScript {
        $ctx = new RoutingContext(0, $record);
        $target = ($hours !== null && ! $hours->isOpen($now)) ? ($closed ?? Destination::hangup()) : $open;

        $script = new CallScript();
        if ($record) {
            $script->say($disclosure ?: self::DEFAULT_DISCLOSURE);
        }

        return $script->append($this->route($target, $ctx));
    }

    public function route(Destination $to, RoutingContext $ctx): CallScript
    {
        if ($ctx->hop > self::MAX_HOPS) {
            return $this->unavailable();
        }

        return match ($to->type) {
            Destination::USER => $this->toUser($to, $ctx),
            Destination::RING_GROUP => $this->toRingGroup($to, $ctx),
            Destination::IVR => $this->toIvr($to, $ctx),
            Destination::VOICEMAIL => $this->toVoicemail($to),
            default => $this->unavailable(),
        };
    }

    /**
     * @param  string  $dialStatus  dial outcome: completed, answered, busy, no-answer, failed, canceled
     */
    public function afterDial(string $dialStatus, Destination $next, RoutingContext $ctx): CallScript
    {
        if (in_array($dialStatus, ['completed', 'answered'], true)) {
            return (new CallScript())->hangup();
        }

        return $this->route($next, $ctx->next());
    }

    public function ivrChoice(Destination $ivr, ?string $digits, RoutingContext $ctx): CallScript
    {
        $menu = $ivr->id ? $this->directory->ivr($ivr->id) : null;
        if ($menu === null) {
            return $this->unavailable();
        }

        $choice = $digits !== null ? substr(trim($digits), 0, 1) : '';
        if ($choice !== '' && isset($menu->options[$choice])) {
            return $this->route($menu->options[$choice], $ctx->next());
        }

        // Nothing pressed: play the menu again (up to IVR_ATTEMPTS times) without a complaint.
        $again = (new CallScript())->redirect($this->urls->route($ivr->withStep($ivr->step + 1), $ctx->next()));

        return $choice === '' ? $again : (new CallScript())->say(self::MSG_INVALID)->append($again);
    }

    /** After a voicemail: thank the caller, or apologise if nothing was recorded. */
    public static function voicemailDone(bool $recorded = true): CallScript
    {
        return (new CallScript())->say($recorded ? self::MSG_VOICEMAIL_THANKS : self::MSG_UNAVAILABLE)->hangup();
    }

    private function toUser(Destination $to, RoutingContext $ctx): CallScript
    {
        $targets = $this->directory->userTargets($to->id);
        if ($targets === []) {
            return $this->unavailable();
        }

        $mailbox = $this->directory->userMailbox($to->id);
        $next = $mailbox ? new Destination(Destination::VOICEMAIL, $mailbox) : Destination::hangup();

        return (new CallScript())->dial($targets, $this->dialOptions($ctx, [
            'timeout' => self::USER_RING_SECONDS,
            'action' => $this->urls->afterDial($next, $ctx),
        ]));
    }

    private function toRingGroup(Destination $to, RoutingContext $ctx): CallScript
    {
        $group = $this->directory->ringGroup($to->id);
        if ($group === null) {
            return $this->unavailable();
        }

        $fallback = $group->fallback ?? Destination::hangup();

        if ($group->members === []) {
            return $this->route($fallback, $ctx->next());
        }

        if ($group->strategy === RingGroupConfig::SIMULTANEOUS) {
            return (new CallScript())->dial(
                array_merge(...$group->members),
                $this->dialOptions($ctx, ['timeout' => $group->ringSeconds, 'action' => $this->urls->afterDial($fallback, $ctx)]),
            );
        }

        // In order: ring one member at a time; step says whose turn it is.
        $member = $group->members[$to->step] ?? null;
        if ($member === null) {
            return $this->route($fallback, $ctx->next());
        }

        return (new CallScript())->dial($member, $this->dialOptions($ctx, [
            'timeout' => $group->ringSeconds,
            'action' => $this->urls->afterDial($to->withStep($to->step + 1), $ctx),
        ]));
    }

    private function toIvr(Destination $to, RoutingContext $ctx): CallScript
    {
        $menu = $this->directory->ivr($to->id);
        if ($menu === null) {
            return $this->unavailable();
        }

        // step = how many times the menu has already played without a valid choice.
        if ($to->step >= self::IVR_ATTEMPTS) {
            return $this->route($menu->fallback ?? Destination::hangup(), $ctx->next());
        }

        return (new CallScript())
            ->gather($menu->greeting, [
                'numDigits' => 1,
                'timeout' => $menu->timeoutSeconds,
                'action' => $this->urls->ivrChoice($to, $ctx),
                'sound' => $menu->greetingSound,
            ]);
    }

    private function toVoicemail(Destination $to): CallScript
    {
        $box = $this->directory->mailbox($to->id);
        if ($box === null) {
            return $this->unavailable();
        }

        return (new CallScript())
            ->sayOrPlay($box->greeting, $box->greetingSound)
            ->record([
                'maxLength' => $box->maxSeconds,
                'action' => $this->urls->voicemailSaved(new Destination(Destination::VOICEMAIL, $to->id)),
            ]);
    }

    /** @param array<string, mixed> $options */
    private function dialOptions(RoutingContext $ctx, array $options): array
    {
        if ($ctx->record) {
            $options['record'] = true;
            $options['recordingStatusCallback'] = $this->urls->recordingSaved();
        }

        return $options;
    }

    private function unavailable(): CallScript
    {
        return (new CallScript())->say(self::MSG_UNAVAILABLE)->hangup();
    }
}
