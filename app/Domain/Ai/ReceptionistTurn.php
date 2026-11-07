<?php

namespace App\Domain\Ai;

/** What the AI receptionist decided to do after the caller spoke. */
final class ReceptionistTurn
{
    public const CONTINUE = 'continue';
    public const TRANSFER = 'transfer';
    public const MESSAGE = 'message';
    public const END = 'end';

    public function __construct(
        /** Spoken to the caller first. */
        public readonly string $say,
        public readonly string $action,
        /** 1-based route number, for TRANSFER. */
        public readonly ?int $route = null,
        /** @var array{name: ?string, number: ?string, message: string}|null for MESSAGE */
        public readonly ?array $message = null,
    ) {
    }
}
