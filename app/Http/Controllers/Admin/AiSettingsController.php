<?php

namespace App\Http\Controllers\Admin;

use App\Ai\AiClient;
use App\Ai\AiSettings;
use App\Http\Controllers\Controller;
use App\Rules\AiEndpoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class AiSettingsController extends Controller
{
    public function show(AiSettings $settings): View
    {
        $all = $settings->all();

        return view('admin.ai-settings', [
            'transcription' => $all['transcription'],
            'analysis' => $all['analysis'],
            'hasTranscriptionKey' => ! empty($all['transcription']['api_key']),
            'hasAnalysisKey' => ! empty($all['analysis']['api_key']),
        ]);
    }

    public function update(Request $request, AiSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'transcription.base_url' => ['required', 'url:https,http', 'max:255', new AiEndpoint()],
            'transcription.model' => ['required', 'string', 'max:100'],
            'transcription.api_key' => ['nullable', 'string', 'max:500'],
            'analysis.provider' => ['required', Rule::in([AiSettings::ANTHROPIC, AiSettings::OPENAI_COMPATIBLE])],
            'analysis.base_url' => ['nullable', 'required_if:analysis.provider,'.AiSettings::OPENAI_COMPATIBLE, 'url:https,http', 'max:255', new AiEndpoint()],
            'analysis.model' => ['required', 'string', 'max:100'],
            'analysis.api_key' => ['nullable', 'string', 'max:500'],
        ]);

        $settings->save([
            'transcription' => [
                'base_url' => $data['transcription']['base_url'],
                'model' => $data['transcription']['model'],
                'api_key' => $data['transcription']['api_key'] ?? null,
            ],
            'analysis' => [
                'provider' => $data['analysis']['provider'],
                'base_url' => $data['analysis']['base_url'] ?? null,
                'model' => $data['analysis']['model'],
                'api_key' => $data['analysis']['api_key'] ?? null,
            ],
        ]);

        return back()->with('status', 'AI provider saved. Keys are stored encrypted and not shown again.');
    }

    /** Sends a short made-up transcript to the summary provider to prove the key and model work. */
    public function test(AiClient $ai): RedirectResponse
    {
        try {
            $result = $ai->analyse(
                'Caller: Hi, this is Sam from Hilltop Builders. Could someone call me back about the quote for the office extension? Thanks.',
                true, false, false, 'voicemail',
            );
        } catch (Throwable $e) {
            return back()->withErrors(['test' => 'Test failed: '.$e->getMessage()]);
        }

        return back()->with('status', 'Connection works. Sample summary: '.($result->summary ?? '(empty)'));
    }

    public function forget(Request $request, AiSettings $settings): RedirectResponse
    {
        $part = $request->validate(['part' => ['required', Rule::in(['transcription', 'analysis'])]])['part'];
        $settings->forget($part);

        return back()->with('status', 'API key removed.');
    }
}
