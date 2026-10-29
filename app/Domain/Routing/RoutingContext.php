<?php

namespace App\Domain\Routing;

/**
 * State that travels with a call through every routing callback.
 *  - hop:    how many routing steps so far (loop protection)
 *  - record: whether answered legs of this call are recorded
 */
final class RoutingContext
{
    public function __construct(
        public readonly int $hop = 0,
        public readonly bool $record = false,
    ) {
    }

    public function next(): self
    {
        return new self($this->hop + 1, $this->record);
    }
}
