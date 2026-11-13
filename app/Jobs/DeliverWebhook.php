<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Webhooks\SafeUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends one event to one endpoint. A failure is tried again after 1 minute,
 * 5 minutes, 30 minutes, 2 hours, 6 hours and 24 hours, then given up on.
 *
 * Each request is signed so the receiver can tell it came from here:
 * X-QuaverDesk-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>" with the endpoint's secret>.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public const BACKOFF = [60, 300, 1800, 7200, 21600, 86400];

    public int $tries = 1;

    public function __construct(public readonly int $deliveryId)
    {
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);
        $endpoint = $delivery?->endpoint;
        if ($delivery === null || $delivery->status !== 'pending') {
            return;
        }
        if ($endpoint === null || ! $endpoint->enabled) {
            $delivery->forceFill(['status' => 'failed', 'error' => 'The endpoint was switched off or removed.'])->save();

            return;
        }

        $delivery->increment('attempts');
        $code = null;

        try {
            $ip = SafeUrl::resolve($endpoint->url, $why) ?? throw new \RuntimeException((string) $why);
            $parts = parse_url($endpoint->url);
            $port = $parts['port'] ?? 443;
            $body = $delivery->payload;
            $time = time();

            $response = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()
                ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$parts['host']}:{$port}:{$ip}"]]])
                ->withHeaders([
                    'User-Agent' => 'QuaverDesk-Webhooks/1',
                    'X-QuaverDesk-Event' => $delivery->event,
                    'X-QuaverDesk-Delivery' => $delivery->event_id,
                    'X-QuaverDesk-Signature' => "t={$time},v1=".hash_hmac('sha256', "{$time}.{$body}", $endpoint->secret),
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            $code = $response->status();
            if (! $response->successful()) {
                throw new \RuntimeException("The endpoint answered HTTP {$code}.");
            }
        } catch (Throwable $e) {
            $this->failed($delivery, $code, $e->getMessage());

            return;
        }

        $delivery->forceFill(['status' => 'delivered', 'response_code' => $code, 'error' => null, 'delivered_at' => now()])->save();
        $endpoint->forceFill(['last_success_at' => now()])->save();
    }

    private function failed(WebhookDelivery $delivery, ?int $code, string $message): void
    {
        $delay = self::BACKOFF[$delivery->attempts - 1] ?? null;
        $delivery->forceFill([
            'status' => $delay === null ? 'failed' : 'pending',
            'response_code' => $code,
            'error' => substr($message, 0, 250),
        ])->save();
        $delivery->endpoint?->forceFill(['last_failure_at' => now()])->save();

        if ($delay !== null) {
            self::dispatch($delivery->id)->delay($delay);
        }
    }
}
