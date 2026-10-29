<?php

namespace App\Ai;

use App\Domain\Ai\AnalysisParser;
use App\Domain\Ai\AnalysisPrompt;
use App\Domain\Ai\CallAnalysis;
use App\Rules\AiEndpoint;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to the customer's own AI accounts. We never run models ourselves.
 *  - Transcription: any OpenAI-compatible /audio/transcriptions endpoint
 *    (OpenAI, or compatible hosted or self-hosted speech-to-text servers).
 *  - Analysis: Anthropic's Messages API, or any OpenAI-compatible
 *    /chat/completions endpoint.
 */
class AiClient
{
    public const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
    public const ANTHROPIC_VERSION = '2023-06-01';

    public function __construct(private readonly AiSettings $settings)
    {
    }

    public function transcribe(string $audio, string $filename = 'audio.wav'): string
    {
        $t = $this->settings->all()['transcription'];
        if (empty($t['api_key'])) {
            throw new RuntimeException('Transcription is not set up. Add your provider under Admin → AI provider.');
        }

        self::assertEndpoint($t['base_url']);
        $response = Http::withToken($t['api_key'])->withoutRedirecting()
            ->timeout(180)
            ->attach('file', $audio, $filename)
            ->post(rtrim($t['base_url'], '/').'/audio/transcriptions', ['model' => $t['model']]);

        if ($response->failed()) {
            throw new RuntimeException('Transcription provider returned HTTP '.$response->status().'.');
        }

        $text = $response->json('text');
        if (! is_string($text)) {
            throw new RuntimeException('Transcription provider returned no text.');
        }

        return trim($text);
    }

    public function analyse(string $transcript, bool $summary, bool $actionItems, bool $sentiment, string $kind): CallAnalysis
    {
        $a = $this->settings->all()['analysis'];
        if (empty($a['api_key'])) {
            throw new RuntimeException('Summaries are not set up. Add your provider under Admin → AI provider.');
        }

        $prompt = AnalysisPrompt::build($transcript, $summary, $actionItems, $sentiment, $kind);

        $reply = $a['provider'] === AiSettings::ANTHROPIC
            ? $this->anthropic($a, $prompt)
            : $this->openAiCompatible($a, $prompt);

        return AnalysisParser::parse($reply, $summary, $actionItems, $sentiment);
    }

    /** @param array{system: string, user: string} $prompt */
    private function anthropic(array $a, array $prompt): string
    {
        $response = Http::withHeaders([
            'x-api-key' => $a['api_key'],
            'anthropic-version' => self::ANTHROPIC_VERSION,
        ])->timeout(90)->post(self::ANTHROPIC_URL, [
            'model' => $a['model'],
            'max_tokens' => 800,
            'system' => $prompt['system'],
            'messages' => [['role' => 'user', 'content' => $prompt['user']]],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Anthropic returned HTTP '.$response->status().'.');
        }

        return collect($response->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");
    }

    /** @param array{system: string, user: string} $prompt */
    private function openAiCompatible(array $a, array $prompt): string
    {
        self::assertEndpoint((string) $a['base_url']);
        $response = Http::withToken($a['api_key'])->withoutRedirecting()->timeout(90)
            ->post(rtrim($a['base_url'], '/').'/chat/completions', [
                'model' => $a['model'],
                'messages' => [
                    ['role' => 'system', 'content' => $prompt['system']],
                    ['role' => 'user', 'content' => $prompt['user']],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('AI provider returned HTTP '.$response->status().'.');
        }

        return (string) $response->json('choices.0.message.content', '');
    }

    /** Settings saved before the https rule existed are checked again here, before a key is sent. */
    private static function assertEndpoint(string $url): void
    {
        if (! AiEndpoint::allows($url)) {
            throw new RuntimeException('The AI provider address must use https:// (or http:// on this server only). Update it under Admin → AI provider.');
        }
    }
}
