<?php

namespace App\Webhooks;

use App\Domain\Licensing\Feature;
use App\Jobs\DeliverWebhook;
use App\Models\Call;
use App\Models\Recording;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\LicenceManager;
use App\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tells the company's webhook endpoints that something happened. Payloads are
 * flat JSON (id, event, created_at, then the event's own fields) so Zapier,
 * Make and n8n offer every field to pick from without digging into nesting.
 */
final class Webhooks
{
    public const CALL_STARTED = 'call.started';
    public const CALL_ENDED = 'call.ended';
    public const VOICEMAIL_RECEIVED = 'voicemail.received';
    public const RECORDING_READY = 'recording.ready';
    public const AI_SUMMARY_READY = 'ai.summary_ready';

    /** Event => what it means, for the admin page. */
    public const EVENTS = [
        self::CALL_STARTED => 'A call starts (incoming or outgoing)',
        self::CALL_ENDED => 'A call ends, with how long it lasted',
        self::VOICEMAIL_RECEIVED => 'A voicemail (or an AI receptionist message) arrives',
        self::RECORDING_READY => 'A call recording is saved',
        self::AI_SUMMARY_READY => 'A summary, action items and sentiment are ready for a call or voicemail',
    ];

    /** Sends $event to every endpoint of the model's company that wants it. Never throws: telephony comes first. */
    public static function emit(string $event, Model $source, array $data): void
    {
        try {
            $tenancy = app(Tenancy::class);
            $tenantId = (int) $source->tenant_id;
            $send = function () use ($event, $data, $tenantId) {
                if (! app(LicenceManager::class)->allows(Feature::Webhooks)) {
                    return;
                }
                $endpoints = WebhookEndpoint::query()->where('tenant_id', $tenantId)->where('enabled', true)->get()
                    ->filter(fn (WebhookEndpoint $e) => $e->wants($event));
                foreach ($endpoints as $endpoint) {
                    self::queue($endpoint, $event, $data);
                }
            };

            if ($tenancy->enabled() && ($tenancy->isEverywhere() || $tenancy->id() !== $tenantId)) {
                $tenancy->run($source->tenant, $send);
            } else {
                $send();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** A sample event to one endpoint, so people can see it arrive. */
    public static function ping(WebhookEndpoint $endpoint): WebhookDelivery
    {
        return self::queue($endpoint, 'ping', ['message' => 'This is a test from QuaverDesk.']);
    }

    private static function queue(WebhookEndpoint $endpoint, string $event, array $data): WebhookDelivery
    {
        $eventId = (string) Str::uuid();
        $delivery = WebhookDelivery::query()->create([
            'tenant_id' => $endpoint->tenant_id,
            'webhook_endpoint_id' => $endpoint->id,
            'event_id' => $eventId,
            'event' => $event,
            'payload' => json_encode(['id' => $eventId, 'event' => $event, 'created_at' => now()->toIso8601String(), ...$data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        DeliverWebhook::dispatch($delivery->id);

        return $delivery;
    }

    /** @return array<string, mixed> */
    public static function callData(Call $call): array
    {
        return [
            'call_id' => $call->id,
            'direction' => $call->direction,
            'from' => $call->from,
            'to' => $call->to,
            'status' => $call->status,
            'duration_seconds' => $call->duration_seconds,
            'user_id' => $call->user_id,
            'user_name' => $call->user?->name,
            'user_extension' => $call->user?->extension,
        ];
    }

    /** @return array<string, mixed> */
    public static function recordingData(Recording $recording, bool $withAi = false): array
    {
        $data = [
            'recording_id' => $recording->id,
            'kind' => $recording->kind,
            'call_id' => $recording->call_id,
            'from' => $recording->from,
            'to' => $recording->to,
            'mailbox' => $recording->mailbox?->name,
            'duration_seconds' => $recording->duration_seconds,
            'url' => route('recordings.show', $recording),
        ];

        if ($withAi) {
            $data += ['summary' => $recording->summary, 'action_items' => $recording->action_items ?? [], 'sentiment' => $recording->sentiment];
        }

        return $data;
    }
}
