<?php

namespace App\Domain\Routing;

use App\Domain\Calls\DialTarget;

final class RingGroupConfig
{
    public const SIMULTANEOUS = 'simultaneous';
    public const IN_ORDER = 'in_order';

    /**
     * @param  list<list<DialTarget>>  $members  each member's devices (softphone, desk phones), in ring order
     */
    public function __construct(
        public readonly string $strategy,
        public readonly int $ringSeconds,
        public readonly array $members,
        public readonly ?Destination $fallback,
    ) {
    }
}
