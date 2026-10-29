<?php

namespace App\Domain\Licensing;

use DateTimeImmutable;

/**
 * A verified licence. Instances only come from LicenceVerifier (or the
 * free() fallback), so holding one means the signature has been checked.
 */
final class Licence
{
    public function __construct(
        public readonly string $id,
        public readonly string $licensee,
        public readonly Plan $plan,
        public readonly int $seats,
        public readonly DateTimeImmutable $issuedAt,
        public readonly ?DateTimeImmutable $expiresAt,
    ) {
    }

    public static function free(): self
    {
        return new self('free', 'Unlicensed', Plan::Free, Plan::Free->userCap(), new DateTimeImmutable('@0'), null);
    }

    public function isFree(): bool
    {
        return $this->plan === Plan::Free;
    }

    public function allows(Feature $feature): bool
    {
        return $this->plan->allows($feature);
    }

    /** The most users this install may have. */
    public function maxUsers(): int
    {
        return $this->plan->userCap() ?? $this->seats;
    }
}
