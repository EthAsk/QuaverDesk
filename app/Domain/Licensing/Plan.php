<?php

namespace App\Domain\Licensing;

enum Plan: string
{
    case Free = 'free';
    case Plus = 'plus';
    case Pro = 'pro';

    /** Hard cap on users for the plan; null means "limited by licence seats". */
    public function userCap(): ?int
    {
        return match ($this) {
            self::Free => 3,
            self::Plus, self::Pro => null,
        };
    }

    /**
     * How many of each thing Free allows: enough to run a real small office and
     * try the product, with the next one being the reason to upgrade.
     */
    public const FREE_LIMITS = ['phone_numbers' => 1, 'ring_groups' => 1, 'ivr_menus' => 1, 'sip_trunks' => 1];

    /** @return list<Feature> */
    public function features(): array
    {
        // Free makes real calls: one trunk, one number, one ring group and one menu (FREE_LIMITS).
        $free = [Feature::PhoneNumbers, Feature::Routing, Feature::ByoTrunk];
        $plus = [
            ...$free,
            Feature::Recording,
            Feature::DeskPhones,
            Feature::AiTranscripts,
            Feature::AiSummaries,
            Feature::VoicemailAi,
            Feature::Passkeys,
        ];

        return match ($this) {
            self::Free => $free,
            self::Plus => $plus,
            self::Pro => [...$plus, Feature::AiActionItems, Feature::AiSentiment],
        };
    }

    /** The most of a thing (a table name in FREE_LIMITS) the plan allows; null for no limit. */
    public function limit(string $what): ?int
    {
        return $this === self::Free ? (self::FREE_LIMITS[$what] ?? null) : null;
    }

    public function allows(Feature $feature): bool
    {
        return in_array($feature, $this->features(), true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
