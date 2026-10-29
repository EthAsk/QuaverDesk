<?php

namespace App\Voice;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Audio conversion with ffmpeg. Uploads are untrusted: ffmpeg is told which
 * format to read (from the detected file type, never guessed by ffmpeg, so a
 * playlist dressed up as audio can't make it fetch other files) and may only
 * open the one local file it was given.
 */
final class Audio
{
    /** Detected MIME type => ffmpeg demuxer. */
    public const FORMATS = [
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/wave' => 'wav',
        'audio/vnd.wave' => 'wav',
        'audio/ogg' => 'ogg',
        'audio/opus' => 'ogg',
        'audio/flac' => 'flac',
        'audio/x-flac' => 'flac',
        'audio/aac' => 'aac',
        'audio/mp4' => 'mov',
        'audio/x-m4a' => 'mov',
        'video/mp4' => 'mov',
        'audio/webm' => 'matroska',
        'video/webm' => 'matroska',
    ];

    private const RATE = 16000;

    public function __construct(private readonly string $ffmpeg = 'ffmpeg')
    {
    }

    /**
     * Converts an uploaded or recorded file to what the voice server plays best:
     * 16 kHz mono WAV at an even loudness, cut to $maxSeconds.
     *
     * @return float length in seconds
     */
    public function import(string $in, string $mime, string $out, int $maxSeconds): float
    {
        $format = self::FORMATS[strtolower($mime)] ?? throw new RuntimeException('Unsupported audio type.');

        $this->run([
            '-f', $format, '-i', 'file:'.$in,
            '-t', (string) $maxSeconds,
            '-vn', '-map_metadata', '-1',
            // Even out the volume so recordings don't sound quieter or louder than each other.
            '-af', 'loudnorm=I=-19:TP=-2:LRA=11',
            '-ac', '1', '-ar', (string) self::RATE, '-c:a', 'pcm_s16le', '-f', 'wav', '-bitexact',
            'file:'.$out,
        ]);

        return self::seconds($out);
    }

    /** A copy that sounds like an ordinary phone line (300–3400 Hz at 8 kHz), for previews. */
    public function phoneLine(string $in, string $out): void
    {
        $this->run([
            '-f', 'wav', '-i', 'file:'.$in,
            '-af', 'highpass=f=300,lowpass=f=3400',
            '-ac', '1', '-ar', '8000', '-c:a', 'pcm_s16le', '-f', 'wav', '-bitexact',
            'file:'.$out,
        ]);
    }

    /**
     * Shrinks a call recording (8 kHz WAV from the voice server, about 115 MB an
     * hour) to AAC in an .m4a, about 14 MB an hour, keeping both sides of the call.
     * Plays in every browser and is accepted by transcription services.
     */
    public function compressCall(string $in, string $out): void
    {
        $this->run([
            '-f', 'wav', '-i', 'file:'.$in,
            '-c:a', 'aac', '-b:a', '32k',
            '-movflags', '+faststart', '-f', 'mp4',
            'file:'.$out,
        ]);
    }

    /** @return array{0: string, 1: string} MIME type and file extension of stored audio */
    public static function typeOf(string $bytes): array
    {
        return substr($bytes, 4, 4) === 'ftyp' ? ['audio/mp4', 'm4a'] : ['audio/wav', 'wav'];
    }

    /** Length of a WAV file this class wrote (16-bit mono, plain 44-byte header). */
    public static function seconds(string $wav): float
    {
        $header = (string) file_get_contents($wav, false, null, 0, 44);
        $rate = strlen($header) === 44 ? unpack('V', substr($header, 24, 4))[1] : 0;

        return $rate > 0 ? round(max(0, filesize($wav) - 44) / ($rate * 2), 1) : 0.0;
    }

    /** @param list<string> $args */
    private function run(array $args): void
    {
        $result = Process::timeout(120)->run([
            $this->ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-protocol_whitelist', 'file', ...$args,
        ]);

        $out = (string) end($args);
        if (! $result->successful() || ! is_file(substr($out, 5)) || filesize(substr($out, 5)) <= 44) {
            throw new RuntimeException('Could not convert the audio: '.trim($result->errorOutput()));
        }
    }
}
