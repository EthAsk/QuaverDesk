<?php

namespace App\Voice;

use App\Domain\Calls\CallScript;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Everything the portal needs from the voice server that runs calls.
 * FreeSWITCH is the implementation; controllers only see this interface.
 */
interface VoiceServer
{
    /**
     * What the browser softphone needs to sign in.
     *
     * @return array{websocket_url: string, uri: string, username: string, password: string, stun_url: ?string}
     *
     * @throws \RuntimeException when the voice server is not configured
     */
    public function softphoneSettings(User $user): array;

    /** True only if the request genuinely came from our voice server. */
    public function verifyWebhook(Request $request): bool;

    /** Voice server markup that carries out a call script. */
    public function render(CallScript $script): string;

    /** Content type of render() output. */
    public function responseContentType(): string;

    /** SIP domain phones register to, or null if not configured. */
    public function domain(): ?string;

    /** Write phones, trunks and allow-lists to the voice server and reload it. */
    public function sync(): void;

    /**
     * The slots in a parking lot that have a call waiting.
     *
     * @return list<int>
     *
     * @throws \RuntimeException when the voice server can't be asked
     */
    public function parkedSlots(string $lot): array;

    /**
     * Call a phone number from the voice server; $url (a voice webhook) then runs the call.
     *
     * @throws \RuntimeException when the voice server can't place the call
     */
    public function callNumber(string $e164, string $trunk, string $callerId, string $callId, string $url): void;
}
