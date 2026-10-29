<?php

namespace App\Support;

use App\Models\Recording;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps recording audio on the private disk, encrypted with the app key, so a
 * copied disk or backup does not expose calls. Never served directly: audio
 * is only streamed through an authorised controller.
 */
class RecordingStore
{
    private const DISK = 'local';

    public function put(Recording $recording, string $audio): void
    {
        $path = 'recordings/'.$recording->id.'-'.bin2hex(random_bytes(6)).'.enc';
        $sealed = Crypt::encrypt($audio, false);
        Storage::disk(self::DISK)->put($path, $sealed);

        $recording->forceFill(['path' => $path, 'status' => 'stored', 'bytes' => strlen($sealed)])->save();
    }

    public function get(Recording $recording): ?string
    {
        if (! $recording->path || ! Storage::disk(self::DISK)->exists($recording->path)) {
            return null;
        }

        try {
            return Crypt::decrypt(Storage::disk(self::DISK)->get($recording->path), false);
        } catch (DecryptException) {
            return null; // encrypted with an older APP_KEY: unreadable, treat as gone
        }
    }

    public function deleteAudio(Recording $recording): void
    {
        if ($recording->path) {
            Storage::disk(self::DISK)->delete($recording->path);
        }

        $recording->forceFill(['path' => null, 'status' => 'deleted', 'bytes' => 0])->save();
    }
}
