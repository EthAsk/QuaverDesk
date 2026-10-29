<?php

namespace App\Support;

use App\Domain\Licensing\Feature;
use App\Domain\Licensing\Plan;
use App\Domain\Routing\InboundRouter;
use App\Models\Recording;
use App\Models\Setting;
use App\Tenancy\Quotas;

/** Install-wide recording and AI rules, combined with what the licence allows. */
class RecordingPolicy
{
    public const PLUS_RETENTION_DAYS = 90;

    public function __construct(private readonly LicenceManager $licences, private readonly Quotas $quotas)
    {
    }

    /** Calls can be recorded: the plan includes it and, on the hosted service, storage isn't full. Voicemail is always kept. */
    public function canRecordCalls(): bool
    {
        return $this->licences->allows(Feature::Recording) && ! $this->quotas->storageFull();
    }

    public function recordOutbound(): bool
    {
        return $this->canRecordCalls() && (bool) Setting::get('recording.outbound', false);
    }

    public function disclosure(): string
    {
        return Setting::get('recording.disclosure') ?: InboundRouter::DEFAULT_DISCLOSURE;
    }

    /** Plus keeps recordings 90 days; Pro chooses (1 day to 10 years). */
    public function retentionDays(): int
    {
        if ($this->licences->current()->plan !== Plan::Pro) {
            return self::PLUS_RETENTION_DAYS;
        }

        return max(1, min(3650, (int) Setting::get('recording.retention_days', self::PLUS_RETENTION_DAYS)));
    }

    public function autoAi(): bool
    {
        return (bool) Setting::get('recording.auto_ai', true);
    }

    /** @return array{transcript: bool, summary: bool, actionItems: bool, sentiment: bool} */
    public function aiFeatures(Recording $recording): array
    {
        $l = $this->licences;
        $voicemail = $recording->kind === Recording::VOICEMAIL;

        $transcript = $voicemail ? $l->allows(Feature::VoicemailAi) : $l->allows(Feature::AiTranscripts);
        $summary = $voicemail ? $l->allows(Feature::VoicemailAi) : $l->allows(Feature::AiSummaries);

        return [
            'transcript' => $transcript,
            'summary' => $transcript && $summary,
            'actionItems' => $transcript && $l->allows(Feature::AiActionItems),
            'sentiment' => $transcript && $l->allows(Feature::AiSentiment),
        ];
    }

    public function aiAllowed(Recording $recording): bool
    {
        return $this->aiFeatures($recording)['transcript'];
    }
}
