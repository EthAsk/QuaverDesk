<?php

namespace App\Domain\Telephony;

final class DialDecision
{
    public const CLIENT = 'client';
    public const NUMBER = 'number';
    public const EMERGENCY = 'emergency';
    public const REJECT = 'reject';
    public const RING_GROUP = 'ring_group';
    public const PARK = 'park'; // target: the slot, or null for "any free slot"
    public const RECORD_SOUND = 'record_sound'; // *0: record a sound for greetings and menus
    public const VOICEMAIL = 'voicemail'; // *00: play your messages, record your greeting

    private function __construct(
        public readonly string $type,
        public readonly ?string $target = null,
        public readonly ?string $message = null,
    ) {
    }

    public static function client(string $identity): self
    {
        return new self(self::CLIENT, $identity);
    }

    public static function number(string $e164): self
    {
        return new self(self::NUMBER, $e164);
    }

    public static function emergency(string $digits): self
    {
        return new self(self::EMERGENCY, $digits);
    }

    public static function ringGroup(int $id): self
    {
        return new self(self::RING_GROUP, (string) $id);
    }

    public static function park(?int $slot): self
    {
        return new self(self::PARK, $slot === null ? null : (string) $slot);
    }

    public static function recordSound(): self
    {
        return new self(self::RECORD_SOUND);
    }

    public static function voicemail(): self
    {
        return new self(self::VOICEMAIL);
    }

    public static function reject(string $message): self
    {
        return new self(self::REJECT, null, $message);
    }
}
