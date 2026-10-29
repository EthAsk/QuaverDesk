<?php

namespace App\Domain\Routing;

final class MailboxConfig
{
    public function __construct(
        public readonly string $greeting,
        public readonly int $maxSeconds,
        /** URL of a recording played instead of the greeting text */
        public readonly ?string $greetingSound = null,
    ) {
    }
}
