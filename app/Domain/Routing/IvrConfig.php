<?php

namespace App\Domain\Routing;

final class IvrConfig
{
    /** @param array<string, Destination> $options keyed by digit '0'–'9' */
    public function __construct(
        public readonly string $greeting,
        public readonly array $options,
        public readonly int $timeoutSeconds,
        public readonly ?Destination $fallback,
        /** URL of a recording played instead of the greeting text */
        public readonly ?string $greetingSound = null,
    ) {
    }
}
