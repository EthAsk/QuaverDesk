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

    /**
     * Ring a person's phones (all at once) and, when one answers, run the call at $url. $callId is the id
     * the voice server gives the call, so the call can be found again in the webhooks.
     *
     * @param  list<string>  $sipUsernames  the SIP usernames of their softphone and desk phones
     *
     * @throws \RuntimeException when the voice server can't place the call
     */
    public function callUser(array $sipUsernames, string $callId, string $label, string $url): void;


    /**
     * Ring a supervisor's phones and join whoever answers to a live call: they listen, whisper (only the agent
     * hears them) or barge (both sides hear them). $agentLeg says which side of the call is our person.
     *
     * @param  list<string>  $sipUsernames  the supervisor's phones
     * @param  string  $mode  listen | whisper | barge
     * @param  string  $agentLeg  a | b
     *
     * @throws \RuntimeException when the voice server can't do it
     */
    public function monitorCall(array $sipUsernames, string $callId, string $mode, string $agentLeg): void;

    /**
     * Send key presses out on a call, to the phone or device at its far end.
     *
     * @throws \RuntimeException when the voice server can't do it
     */
    public function sendDtmf(string $callId, string $digits): void;

    /**
     * Tell a registered desk phone to download its settings now (SIP NOTIFY check-sync).
     *
     * @throws \RuntimeException when the voice server can't be asked
     */
    public function resyncPhone(string $sipUsername): void;

    /**
     * Phones and softphones signed in now, by SIP username: where from and what they are.
     *
     * @return array<string, array{ip: string, agent: string, transport: string}>
     *
     * @throws \RuntimeException when the voice server can't be asked
     */
    public function registrations(): array;

    /**
     * Our phones (softphones user-12, desk phones u12-ab12cd) on a call now,
     * ringing or talking, by SIP username.
     *
     * @return list<string>
     *
     * @throws \RuntimeException when the voice server can't be asked
     */
    public function busyPhones(): array;
}
