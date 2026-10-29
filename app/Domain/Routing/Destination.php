<?php

namespace App\Domain\Routing;

use InvalidArgumentException;

/**
 * Where a call goes next. Serialises to a short string ("ivr:3", "ring_group:5:2")
 * so it can travel safely in voice server callback URLs.
 */
final class Destination
{
    public const USER = 'user';
    public const RING_GROUP = 'ring_group';
    public const IVR = 'ivr';
    public const VOICEMAIL = 'voicemail';
    public const HANGUP = 'hangup';

    public const TYPES = [self::USER, self::RING_GROUP, self::IVR, self::VOICEMAIL, self::HANGUP];

    public function __construct(
        public readonly string $type,
        public readonly ?int $id = null,
        public readonly int $step = 0,
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown destination type: {$type}");
        }
        if ($type !== self::HANGUP && ($id === null || $id < 1)) {
            throw new InvalidArgumentException("Destination {$type} needs an id.");
        }
        if ($step < 0) {
            throw new InvalidArgumentException('Step cannot be negative.');
        }
    }

    public static function hangup(): self
    {
        return new self(self::HANGUP);
    }

    public static function parse(?string $value): ?self
    {
        if ($value === null || ! preg_match('/^(user|ring_group|ivr|voicemail|hangup)(?::(\d{1,9}))?(?::(\d{1,3}))?$/', $value, $m)) {
            return null;
        }

        try {
            return new self($m[1], isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null, isset($m[3]) ? (int) $m[3] : 0);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function withStep(int $step): self
    {
        return new self($this->type, $this->id, $step);
    }

    public function __toString(): string
    {
        if ($this->type === self::HANGUP) {
            return self::HANGUP;
        }

        return $this->type.':'.$this->id.($this->step > 0 ? ':'.$this->step : '');
    }
}
