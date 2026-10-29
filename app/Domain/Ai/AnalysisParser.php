<?php

namespace App\Domain\Ai;

use JsonException;
use RuntimeException;

/** Strictly reads the model's reply into a CallAnalysis. Anything unexpected is dropped. */
final class AnalysisParser
{
    public static function parse(string $reply, bool $summary, bool $actionItems, bool $sentiment): CallAnalysis
    {
        $start = strpos($reply, '{');
        $end = strrpos($reply, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new RuntimeException('The AI provider did not return the expected format.');
        }

        try {
            $data = json_decode(substr($reply, $start, $end - $start + 1), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The AI provider did not return valid JSON.');
        }
        if (! is_array($data)) {
            throw new RuntimeException('The AI provider did not return the expected format.');
        }

        $text = fn ($v, int $max) => is_string($v) && trim($v) !== '' ? mb_substr(trim(strip_tags($v)), 0, $max) : null;

        $items = [];
        if ($actionItems && is_array($data['action_items'] ?? null)) {
            foreach (array_slice($data['action_items'], 0, 5) as $item) {
                if ($t = $text($item, 300)) {
                    $items[] = $t;
                }
            }
        }

        $mood = $sentiment && is_string($data['sentiment'] ?? null) ? strtolower(trim($data['sentiment'])) : null;

        return new CallAnalysis(
            $summary ? $text($data['summary'] ?? null, 1200) : null,
            $items,
            in_array($mood, CallAnalysis::SENTIMENTS, true) ? $mood : null,
        );
    }
}
