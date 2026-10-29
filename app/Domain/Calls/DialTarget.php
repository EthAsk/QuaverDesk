<?php

namespace App\Domain\Calls;

final class DialTarget
{
    private function __construct(
        public readonly string $kind,           // client | number | sip
        public readonly string $value,          // identity, E.164 or sip: URI
        public readonly ?string $trunk = null,  // voice server gateway for phone-network calls
        public readonly ?string $whisper = null, // spoken to the called person before connecting
        public readonly ?FollowMe $followMe = null, // set when this is a person's own mobile
        public readonly ?string $legId = null,  // the voice server's id for this leg, when it is logged
    ) {
    }

    public static function client(string $identity): self
    {
        return new self('client', $identity);
    }

    public static function number(string $e164, ?string $trunk = null, ?string $whisper = null): self
    {
        return new self('number', $e164, $trunk, $whisper);
    }

    /** A person's own mobile, rung alongside their phones; they press 1 to take the call. */
    public static function mobile(string $e164, string $trunk, FollowMe $followMe): self
    {
        return new self('number', $e164, $trunk, null, $followMe);
    }

    /** A registered desk phone, e.g. sip:u12-ab12@voice.example.co.uk */
    public static function sip(string $uri): self
    {
        return new self('sip', $uri);
    }

    public function withLegId(string $id): self
    {
        return new self($this->kind, $this->value, $this->trunk, $this->whisper, $this->followMe, $id);
    }
}
