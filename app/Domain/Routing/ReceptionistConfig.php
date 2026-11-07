<?php

namespace App\Domain\Routing;

final class ReceptionistConfig
{
    /**
     * @param  list<array{name: string, about: string, to: Destination}>  $routes  where it may put callers through, numbered from 1
     */
    public function __construct(
        public readonly string $business,
        public readonly string $greeting,
        public readonly string $about,
        public readonly array $routes,
        public readonly ?int $mailboxId,
        public readonly ?Destination $fallback,
        public readonly int $maxTurns,
        /** The plan includes it and the AI provider is set up for transcripts and summaries. */
        public readonly bool $ready,
    ) {
    }
}
