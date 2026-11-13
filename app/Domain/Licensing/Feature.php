<?php

namespace App\Domain\Licensing;

/**
 * Every paid capability in the product. A feature is either allowed by the
 * installed plan or it is not; the UI shows locked features with an upgrade
 * prompt rather than hiding them.
 */
enum Feature: string
{
    case PhoneNumbers = 'phone_numbers';
    case Routing = 'routing';
    case Recording = 'recording';
    case DeskPhones = 'desk_phones';
    case ByoTrunk = 'byo_trunk';
    case AiTranscripts = 'ai_transcripts';
    case AiSummaries = 'ai_summaries';
    case AiActionItems = 'ai_action_items';
    case AiSentiment = 'ai_sentiment';
    case VoicemailAi = 'voicemail_ai';
    case Passkeys = 'passkeys';
    case CallQueues = 'call_queues';
    case LiveWallboard = 'live_wallboard';
    case AiSearch = 'ai_search';
    case AiReceptionist = 'ai_receptionist';
    case Webhooks = 'webhooks';
    case HubSpot = 'hubspot';
    case Sso = 'sso';
    case Api = 'api';
    case QueueSkills = 'queue_skills';
    case ApiCalls = 'api_calls';
    case Monitoring = 'monitoring';
    case Reports = 'reports';
    case QueueReports = 'queue_reports';

    public function label(): string
    {
        return match ($this) {
            self::PhoneNumbers => 'Phone numbers',
            self::Routing => 'IVR and ring groups',
            self::Recording => 'Call recording',
            self::DeskPhones => 'Desk phones and mobile apps',
            self::ByoTrunk => 'Bring your own SIP trunk',
            self::AiTranscripts => 'AI transcripts',
            self::AiSummaries => 'AI summaries',
            self::AiActionItems => 'AI action items',
            self::AiSentiment => 'AI sentiment',
            self::VoicemailAi => 'Voicemail transcripts and summaries',
            self::Passkeys => 'Passkey sign-in',
            self::CallQueues => 'Call queues',
            self::LiveWallboard => 'Live wallboard',
            self::AiSearch => 'AI search across calls',
            self::AiReceptionist => 'AI receptionist',
            self::Webhooks => 'Webhooks and Zapier',
            self::HubSpot => 'HubSpot CRM',
            self::Sso => 'Single sign-on and directory sync',
            self::Api => 'API access',
            self::QueueSkills => 'Queue priority and backup agents',
            self::ApiCalls => 'Placing calls over the API',
            self::Monitoring => 'Supervisor listen, whisper and barge',
            self::Reports => 'Reports and export',
            self::QueueReports => 'Queue and agent reports',
        };
    }
}
