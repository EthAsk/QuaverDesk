<?php

namespace App\Jobs;

use App\Domain\Licensing\Feature;
use App\HubSpot\HubSpotClient;
use App\HubSpot\HubSpotError;
use App\HubSpot\HubSpotSettings;
use App\Models\Call;
use App\Models\Recording;
use App\Models\Setting;
use App\Support\LicenceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Logs a finished call against the HubSpot contact with that phone number, and
 * later adds the AI summary and recording link to the same HubSpot call. Calls
 * from or to nobody in HubSpot are left alone (unless the company asks for
 * contacts to be made). Does nothing for internal calls.
 */
class SyncHubSpotCall implements ShouldQueue
{
    use Queueable;

    public const BACKOFF = [60, 300, 1800, 7200];

    public int $tries = 1;

    public function __construct(public readonly int $callId, public readonly int $attempt = 1)
    {
    }

    public function handle(HubSpotSettings $settings, LicenceManager $licences): void
    {
        $options = $settings->all();
        if (! $licences->allows(Feature::HubSpot) || ! $options['token'] || ! $options['log_calls']) {
            return;
        }
        $call = Call::query()->with('user')->find($this->callId);
        $party = $call ? self::party($call) : null;
        if ($party === null || $call->duration_seconds === null) {
            return;
        }

        $client = new HubSpotClient($options['token']);

        try {
            // Two events for one call (it ended; its summary is ready) must not log it twice.
            Cache::lock("hubspot-call:{$call->id}", 30)->block(20, function () use ($call, $party, $client, $options) {
                $call->refresh();
                $properties = self::properties($call, $options);

                if ($call->hubspot_call_id) {
                    $client->updateCall($call->hubspot_call_id, ['hs_call_body' => $properties['hs_call_body']]);
                } elseif (($contact = $client->findContact($party) ?? ($options['create_contacts'] ? $client->createContact($party) : null)) !== null) {
                    $call->forceFill(['hubspot_call_id' => $client->createCall($properties, $contact)])->save();
                } else {
                    return;
                }
                self::note(true, null);
            });
        } catch (Throwable $e) {
            self::note(false, $e->getMessage());
            $retry = ! $e instanceof HubSpotError || $e->retryable();
            if ($retry && ($delay = self::BACKOFF[$this->attempt - 1] ?? null) !== null) {
                self::dispatch($this->callId, $this->attempt + 1)->delay($delay);
            }
        }
    }

    /** The outside number on an inbound or outbound call; null for anything else. */
    public static function party(Call $call): ?string
    {
        if (in_array($call->leg, [Call::LEG_TRANSFER, Call::LEG_FOLLOW_ME], true)) {
            return null;
        }
        $number = match ($call->direction) {
            'inbound' => $call->from,
            'outbound' => $call->to,
            default => null,
        };

        return $number !== null && preg_match('/^\+\d{7,15}$/', $number) ? $number : null;
    }

    /** @return array<string, mixed> */
    public static function properties(Call $call, array $options): array
    {
        $inbound = $call->direction === 'inbound';
        $status = match (true) {
            $call->status === 'no-answer' => 'NO_ANSWER',
            $call->status === 'busy' => 'BUSY',
            in_array($call->status, ['failed', 'rejected', Call::STATUS_REFUSED], true) => 'FAILED',
            $call->duration_seconds > 0 && $call->status === 'completed' => 'COMPLETED',
            default => $inbound ? 'NO_ANSWER' : 'CONNECTING',
        };

        $props = [
            'hs_timestamp' => $call->created_at->toIso8601String(),
            'hs_call_title' => ($inbound ? 'Incoming call' : 'Outgoing call').($call->user ? " ({$call->user->name})" : ''),
            'hs_call_direction' => $inbound ? 'INBOUND' : 'OUTBOUND',
            'hs_call_from_number' => (string) $call->from,
            'hs_call_to_number' => (string) $call->to,
            'hs_call_status' => $status,
            'hs_call_body' => self::body($call, $options),
        ];
        if ($status === 'COMPLETED') {
            $props['hs_call_duration'] = (string) ($call->duration_seconds * 1000);
        }

        return $props;
    }

    private static function body(Call $call, array $options): string
    {
        $lines = [];
        foreach (Recording::query()->where('call_id', $call->id)->orderBy('id')->get() as $recording) {
            $what = $recording->kind === Recording::VOICEMAIL ? 'Voicemail' : 'Recording';
            if ($options['include_summary'] && $recording->summary) {
                $lines[] = '<strong>Summary:</strong> '.e($recording->summary);
                if ($recording->action_items) {
                    $lines[] = '<strong>Action items:</strong> '.e(implode('; ', $recording->action_items));
                }
                if ($recording->sentiment) {
                    $lines[] = '<strong>Sentiment:</strong> '.e($recording->sentiment);
                }
            }
            if ($options['include_recording_link'] && $recording->status === 'stored') {
                $lines[] = '<a href="'.e(route('recordings.show', $recording)).'">'.$what.' in '.e(config('app.name')).'</a> (sign-in needed)';
            }
        }

        return implode('<br>', $lines);
    }

    private static function note(bool $ok, ?string $error): void
    {
        Setting::put('hubspot_last', ['at' => now()->toIso8601String(), 'ok' => $ok, 'error' => $error === null ? null : substr($error, 0, 250)]);
    }
}
