<?php

namespace App\Support;

use App\Domain\Routing\CallbackUrls;
use App\Domain\Routing\Destination;
use App\Domain\Routing\RoutingContext;
use App\Voice\VoiceUrl;

/**
 * Callback URLs carry routing state in the query string. That is safe because
 * only the voice server can call them (shared secret, checked by middleware),
 * and it only ever calls URLs this app gave it.
 */
class RouteCallbackUrls implements CallbackUrls
{
    public function route(Destination $destination, RoutingContext $ctx): string
    {
        return VoiceUrl::to('webhooks.voice.route', ['to' => (string) $destination] + $this->ctx($ctx));
    }

    public function afterDial(Destination $next, RoutingContext $ctx): string
    {
        return VoiceUrl::to('webhooks.voice.after-dial', ['next' => (string) $next] + $this->ctx($ctx));
    }

    public function ivrChoice(Destination $ivr, RoutingContext $ctx): string
    {
        return VoiceUrl::to('webhooks.voice.ivr-choice', ['ivr' => (string) $ivr] + $this->ctx($ctx));
    }

    public function voicemailSaved(Destination $mailbox): string
    {
        return VoiceUrl::to('webhooks.voice.voicemail-saved', ['box' => (string) $mailbox]);
    }

    public function recordingSaved(): string
    {
        return VoiceUrl::to('webhooks.voice.recording-saved', []);
    }

    /** @return array<string, int> */
    private function ctx(RoutingContext $ctx): array
    {
        return ['hop' => $ctx->hop] + ($ctx->record ? ['rec' => 1] : []);
    }

    public static function contextFrom(?string $hop, ?string $rec): RoutingContext
    {
        return new RoutingContext(max(0, min(100, (int) $hop)), $rec === '1');
    }
}
