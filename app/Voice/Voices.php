<?php

namespace App\Voice;

use App\Models\Setting;
use App\Tenancy\Tenancy;

/**
 * The voices this server can speak with, and the one admins chose under Voice
 * (falling back to the TTS_* settings). Voice ids look like "kokoro:bf_emma",
 * "piper:en_GB-cori-high", "freeswitch" or "command".
 */
final class Voices
{
    /** Kokoro's English voices: id => [name, description]. */
    public const KOKORO = [
        'bf_emma' => ['Emma', 'British woman'],
        'bf_isabella' => ['Isabella', 'British woman'],
        'bf_alice' => ['Alice', 'British woman'],
        'bf_lily' => ['Lily', 'British woman'],
        'bm_george' => ['George', 'British man'],
        'bm_lewis' => ['Lewis', 'British man'],
        'bm_daniel' => ['Daniel', 'British man'],
        'bm_fable' => ['Fable', 'British man'],
        'af_heart' => ['Heart', 'American woman'],
        'af_bella' => ['Bella', 'American woman'],
        'af_sarah' => ['Sarah', 'American woman'],
        'am_michael' => ['Michael', 'American man'],
        'am_adam' => ['Adam', 'American man'],
    ];

    private const ACCENTS = ['en_GB' => 'British', 'en_US' => 'American'];

    /** @param array<string, mixed> $config config('voip.tts') */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * @return array<string, array{name: string, about: string, engine: string}> most natural first
     */
    public function available(): array
    {
        $voices = [];

        if ($this->kokoroReady()) {
            foreach (self::KOKORO as $id => [$name, $about]) {
                $voices['kokoro:'.$id] = ['name' => $name, 'about' => $about, 'engine' => 'Kokoro'];
            }
        }

        foreach ($this->piperVoices() as $id) {
            // e.g. en_GB-northern_english_male-medium
            [$lang, $speaker, $quality] = array_pad(explode('-', $id, 3), 3, '');
            $voices['piper:'.$id] = [
                'name' => ucwords(str_replace('_', ' ', $speaker)),
                'about' => trim((self::ACCENTS[$lang] ?? $lang).', '.$quality.' quality', ', '),
                'engine' => 'Piper',
            ];
        }

        $voices['freeswitch'] = ['name' => 'Built-in', 'about' => 'Robotic. Only heard on calls.', 'engine' => 'FreeSWITCH'];

        return $voices;
    }

    public function current(): string
    {
        $chosen = $this->setting('tts.voice');

        return is_string($chosen) && isset($this->available()[$chosen]) ? $chosen : $this->fromEnv();
    }

    public function speed(): float
    {
        return max(0.7, min(1.3, (float) ($this->setting('tts.speed') ?? $this->config['speed'] ?? 1.0)));
    }

    /** The company's choice; none when a call belongs to no company (told "not in service" in the default voice). */
    private function setting(string $key): mixed
    {
        return app(Tenancy::class)->id() === null ? null : Setting::get($key);
    }

    /** TTS settings for Prompts, for the chosen voice (or the one given, for previews). */
    public function config(?string $voice = null, ?float $speed = null): array
    {
        $voice ??= $this->current();
        [$driver, $name] = array_pad(explode(':', $voice, 2), 2, null);

        $config = $this->config;
        $config['driver'] = $driver;
        $config['speed'] = $speed ?? $this->speed();
        if ($driver === 'piper' && $name !== null) {
            $config['piper']['voice'] = $this->piperDir().'/'.$name.'.onnx';
        }
        if ($driver === 'kokoro' && $name !== null) {
            $config['kokoro']['voice'] = $name;
        }

        return $config;
    }

    private function fromEnv(): string
    {
        return match ($this->config['driver'] ?? 'freeswitch') {
            'piper' => empty($this->config['piper']['voice']) ? 'freeswitch' : 'piper:'.basename($this->config['piper']['voice'], '.onnx'),
            'kokoro' => 'kokoro:'.($this->config['kokoro']['voice'] ?? 'bf_emma'),
            'command' => 'command',
            default => 'freeswitch',
        };
    }

    private function kokoroReady(): bool
    {
        $dir = (string) ($this->config['kokoro']['models'] ?? '');

        return $dir !== '' && is_file($dir.'/kokoro-v1.0.onnx') && is_file($dir.'/voices-v1.0.bin');
    }

    private function piperDir(): ?string
    {
        $dir = $this->config['piper']['voices'] ?? null;
        if (! $dir && ! empty($this->config['piper']['voice'])) {
            $dir = dirname($this->config['piper']['voice']);
        }

        return $dir ? rtrim($dir, '/') : null;
    }

    /** @return list<string> voice names that have both the model and its .json */
    private function piperVoices(): array
    {
        $dir = $this->piperDir();
        if ($dir === null || ! is_dir($dir)) {
            return [];
        }

        $names = [];
        foreach (glob($dir.'/*.onnx') ?: [] as $model) {
            if (is_file($model.'.json')) {
                $names[] = basename($model, '.onnx');
            }
        }
        sort($names);

        return $names;
    }
}
