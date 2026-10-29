<?php

namespace App\Http\Controllers;

use App\Models\Sound;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves uploaded greetings and hold music to the voice server (and admins' previews). */
class SoundFileController extends Controller
{
    public function __invoke(string $file): BinaryFileResponse
    {
        $path = Sound::pathFor($file);
        abort_unless(is_file($path), 404);

        // Short cache: a replaced or deleted sound should stop playing soon.
        return response()->file($path, ['Content-Type' => 'audio/wav', 'Cache-Control' => 'public, max-age=300']);
    }
}
