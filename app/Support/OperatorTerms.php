<?php

namespace App\Support;

/**
 * Statements an owner/admin must tick before calling is switched on. Each
 * acceptance is stored with user, time, IP and the exact wording, so the
 * licensor can show the operator was told. Change the wording? Bump
 * config('voip.terms_version') so everyone re-accepts.
 */
final class OperatorTerms
{
    /** @return array<string, string> */
    public static function items(): array
    {
        return [
            'emergency' => 'This software may not connect emergency calls (999/112), may not pass an accurate location, and will not work during power, internet or carrier outages. Everyone who uses it will have another way to call emergency services.',
            'operator' => 'My organisation runs this installation for itself and is responsible for it, including carrier accounts, call charges, number use, and compliance with Ofcom rules and other laws that apply to us. We will not use it to provide phone systems to other organisations.',
            'recording' => 'If we record, transcribe or analyse calls, we will tell callers and staff, have a lawful basis to do it, and meet UK GDPR and any other law that applies to us.',
            'ai' => 'If we connect our own AI provider, call audio and transcripts are sent to that provider under our own agreement with them. We are responsible for that choice.',
            'fraud' => 'We are responsible for securing our accounts and credentials and for any charges from fraudulent or unauthorised calls, including on our own SIP trunk.',
            'as_is' => 'The software is provided "as is", without warranty, under its licence. I have read the DISCLAIMER and LICENSE.',
        ];
    }

    public static function hash(): string
    {
        return hash('sha256', json_encode(self::items()));
    }
}
