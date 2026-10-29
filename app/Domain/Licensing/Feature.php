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
        };
    }
}
