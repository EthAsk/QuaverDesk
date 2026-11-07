<?php

namespace App\Http\Controllers;

use App\Ai\AiSettings;
use App\Ai\CallSearch;
use App\Models\Recording;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;

/** AI search (Pro): find calls and voicemails by what was said, or ask a question about them. */
class CallSearchController extends Controller
{
    public function search(Request $request, CallSearch $search, AiSettings $ai): View
    {
        [$q, $kind] = $this->input($request);

        return view('recordings.search', [
            'q' => $q, 'kind' => $kind,
            'results' => $q === '' ? [] : $search->search($request->user(), $q, $kind),
            'askReady' => $ai->analysisReady(),
        ]);
    }

    /** Sends the best matches (only calls this user may see) to the AI provider with the question. */
    public function ask(Request $request, CallSearch $search, AiSettings $ai): View
    {
        [$q, $kind] = $this->input($request);
        $request->validate(['q' => ['required', 'string', 'max:500']]);

        $answer = $sources = $error = null;
        if (! $ai->analysisReady()) {
            $error = 'Connect your AI provider first (Admin → AI provider).';
        } else {
            try {
                ['answer' => $answer, 'sources' => $sources] = $search->ask($request->user(), $q, $kind);
            } catch (RuntimeException $e) {
                Log::warning('AI search question failed.', ['user' => $request->user()->id, 'error' => $e->getMessage()]);
                $error = 'Your AI provider couldn\'t answer just now: '.$e->getMessage();
            }
        }

        return view('recordings.search', [
            'q' => $q, 'kind' => $kind,
            'results' => $search->search($request->user(), $q, $kind),
            'askReady' => $ai->analysisReady(),
            'answer' => $answer === null ? null : CallSearch::answerHtml($answer, $sources),
            'sources' => $sources,
            'askError' => $error,
        ]);
    }

    /** @return array{0: string, 1: ?string} */
    private function input(Request $request): array
    {
        $q = mb_substr(trim((string) $request->input('q', '')), 0, 500);
        $kind = in_array($request->input('kind'), [Recording::CALL, Recording::VOICEMAIL], true) ? $request->input('kind') : null;

        return [$q, $kind];
    }
}
