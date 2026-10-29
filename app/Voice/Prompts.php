<?php

namespace App\Voice;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Turns text into something the voice server can play. With the "kokoro",
 * "piper" or "command" driver the audio is made once per distinct text (and voice),
 * cached under storage/app/prompts and served from /voice/prompts/{hash}.wav.
 */
class Prompts
{
    /** @var list<string> program and arguments that write {out}; empty = use the voice server's own voice */
    private readonly array $command;

    /**
     * @param array{driver: string, engine: string, voice: string, command: list<string>, speed?: float,
     *              piper?: array{binary: string, voice: ?string}, kokoro?: array{python: string, models: ?string, voice: string}} $config
     */
    public function __construct(private readonly array $config)
    {
        $speed = max(0.7, min(1.3, (float) ($config['speed'] ?? 1.0)));

        $this->command = match ($config['driver']) {
            'piper' => empty($config['piper']['voice']) ? [] : [
                $config['piper']['binary'] ?: 'piper',
                '--model', $config['piper']['voice'],
                '--output-file', '{out}',
                '--sentence-silence', '0.25', // a natural pause between sentences
                '--length-scale', (string) round(1 / $speed, 2),
            ],
            'kokoro' => empty($config['kokoro']['models']) ? [] : [
                $config['kokoro']['python'] ?: 'python3',
                dirname(__DIR__, 2).'/bin/kokoro-tts',
                '--model-dir', $config['kokoro']['models'],
                '--voice', $config['kokoro']['voice'],
                '--speed', (string) $speed,
                '--out', '{out}',
            ],
            'command' => $config['command'],
            default => [],
        };
    }

    /** Whether prompts are made as audio files (rather than spoken by the voice server). */
    public function makesFiles(): bool
    {
        return $this->command !== [];
    }

    /** Make (or reuse) the audio for this text ahead of a call, so callers never wait for it. */
    public function warm(string $text): void
    {
        if ($this->makesFiles() && trim($text) !== '') {
            $this->make($text);
        }
    }

    /**
     * @return array{0: 'file', 1: string}|array{0: 'speak', 1: string}
     *         a URL to play, or text for the voice server's own speech engine
     */
    public function for(string $text): array
    {
        if ($this->makesFiles()) {
            try {
                return ['file', VoiceUrl::to('voice.prompt', ['hash' => $this->make($text)])];
            } catch (Throwable $e) {
                Log::error('Text-to-speech failed; falling back to the voice server.', ['error' => $e->getMessage()]);
            }
        }

        return ['speak', $text];
    }

    /** Path of the audio for this text, made now if need be (for previews). */
    public function file(string $text): string
    {
        if (! $this->makesFiles()) {
            throw new RuntimeException('This voice is spoken by the voice server, so it can only be heard on a call.');
        }

        return self::path($this->make($text));
    }

    public function engine(): string
    {
        return $this->config['engine'];
    }

    public function voice(): string
    {
        return $this->config['voice'];
    }

    public static function path(string $hash): string
    {
        return storage_path('app/prompts/'.$hash.'.wav');
    }

    private function make(string $text): string
    {
        $hash = sha1(json_encode([$this->command, $text]));
        $path = self::path($hash);

        if (! is_file($path)) {
            @mkdir(dirname($path), 0750, true);
            $tmp = $path.'.'.bin2hex(random_bytes(4)).'.wav';
            $args = array_map(fn (string $a) => str_replace('{out}', $tmp, $a), $this->command);

            $result = Process::timeout(60)->input($text)->run($args);
            if (! $result->successful() || ! is_file($tmp) || filesize($tmp) <= 44) {
                @unlink($tmp);

                throw new RuntimeException('TTS command failed: '.trim($result->errorOutput()));
            }
            rename($tmp, $path);
        }

        return $hash;
    }
}
