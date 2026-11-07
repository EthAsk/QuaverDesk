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
        // The partner who sold it paid for their own branding: no "Powered by QuaverDesk".
        public readonly bool $ownBranding = false,
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

    /**
     * An install renamed for a partner (APP_NAME) says "Powered by QuaverDesk"
     * unless its licence includes the partner's own branding.
     */
    public function showsPoweredBy(string $appName): bool
    {
        return strcasecmp(trim($appName), 'QuaverDesk') !== 0 && ! $this->ownBranding;
    }

    /** The most users this install may have. */
    public function maxUsers(): int
    {
        return $this->plan->userCap() ?? $this->seats;
    }
}
