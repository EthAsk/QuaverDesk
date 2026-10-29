<?php

namespace App\Http\Controllers;

use App\Voice\Prompts;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves spoken prompts to the voice server. Only already-made prompts exist here. */
class PromptController extends Controller
{
    public function __invoke(string $hash): BinaryFileResponse
    {
        $path = Prompts::path($hash);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'audio/wav', 'Cache-Control' => 'public, max-age=86400']);
    }
}
