<?php

namespace App\Domain\Ai;

final class CallAnalysis
{
    public const SENTIMENTS = ['positive', 'neutral', 'negative', 'mixed'];

    /** @param list<string> $actionItems */
    public function __construct(
        public readonly ?string $summary,
        public readonly array $actionItems,
        public readonly ?string $sentiment,
    ) {
    }
}
