<?php

namespace App\Domain\Calls;

/** How a person's mobile is rung for their calls. */
final class FollowMe
{
    public const SCREEN = 'You have a call through your work phone. Press 1 to answer.';

    public function __construct(
        public readonly int $userId,
        public readonly string $callerId,   // the company's number, shown on the mobile
        public readonly int $delaySeconds,  // 0: together with their phones
        public readonly int $maxSeconds,    // toll-fraud limit once answered
        public readonly ?int $trunkId = null,
    ) {
    }
}
