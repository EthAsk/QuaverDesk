<?php

namespace App\Voice;

use App\Models\Recording;

/**
 * Short-lived addresses for the voice server to fetch one voicemail's audio
 * while someone plays it under *00. Voicemail is encrypted at rest and
 * otherwise only reaches signed-in people, so the address carries its own
 * signature and expiry; they sit in the path because the voice server's
 * file cache works from the path.
 */
final class VoicemailAudio
{
    public const MINUTES = 15;

    public static function url(Recording $message): string
    {
        $expires = now()->addMinutes(self::MINUTES)->getTimestamp();

        return VoiceUrl::to('voice.message', ['file' => $message->id.'-'.$expires.'-'.self::signature($message->id, $expires)]);
    }

    /** The recording id from a signed name, if the signature is right and it hasn't expired. */
    public static function verify(string $file): ?int
    {
        if (! preg_match('/^(\d+)-(\d+)-([0-9a-f]{40})$/', $file, $m) || (int) $m[2] < now()->getTimestamp()) {
            return null;
        }

        return hash_equals(self::signature((int) $m[1], (int) $m[2]), $m[3]) ? (int) $m[1] : null;
    }

    private static function signature(int $id, int $expires): string
    {
        return substr(hash_hmac('sha256', "voicemail-audio|{$id}|{$expires}", (string) config('app.key')), 0, 40);
    }
}
