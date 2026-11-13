<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Is there a newer QuaverDesk than this one? Asked of the public releases once a day (a command does the
 * asking; pages only read the answer, so a slow or blocked network never slows a page). Self-hosted installs
 * only: the hosted service is updated by its operator. Updating is still done by hand with
 * `sudo quaverdesk-deploy`, which is why the notice says so.
 */
class UpdateCheck
{
    private const KEY = 'update-check';

    public function enabled(): bool
    {
        return (bool) config('quaverdesk.update_check') && ! config('tenancy.enabled');
    }

    /** Asks for the latest release and remembers the answer. @return array{version: string, url: string, name: string}|null */
    public function refresh(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $response = Http::timeout(8)->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'QuaverDesk-update-check'])->withoutRedirecting()
                ->get('https://api.github.com/repos/'.config('quaverdesk.update_repo').'/releases/latest');
            $tag = $response->successful() ? ltrim((string) $response->json('tag_name'), 'vV') : '';
            if (! preg_match('/^\d+\.\d+\.\d+$/', $tag)) {
                throw new \RuntimeException('no usable release');
            }
            $latest = ['version' => $tag, 'url' => 'https://github.com/'.config('quaverdesk.update_repo').'/releases/tag/v'.$tag, 'name' => (string) ($response->json('name') ?: "QuaverDesk {$tag}"), 'checked_at' => now()->toIso8601String()];
        } catch (Throwable) {
            // Keep what we knew and try again tomorrow's run (or the next "Check now").
            return $this->latest();
        }
        Cache::forever(self::KEY, $latest);

        return $latest;
    }

    /** What the last check found, or null. @return array{version: string, url: string, name: string, checked_at?: string}|null */
    public function latest(): ?array
    {
        $latest = Cache::get(self::KEY);

        return is_array($latest) ? $latest : null;
    }

    /** A newer release than this install, that an admin hasn't dismissed. */
    public function available(): ?array
    {
        $latest = $this->enabled() ? $this->latest() : null;
        if ($latest === null || ! version_compare($latest['version'], (string) config('quaverdesk.version'), '>')) {
            return null;
        }

        return $latest;
    }

    public function showBanner(): ?array
    {
        $latest = $this->available();

        return $latest !== null && Setting::get('update.dismissed') !== $latest['version'] ? $latest : null;
    }

    public function dismiss(): void
    {
        if ($latest = $this->available()) {
            Setting::put('update.dismissed', $latest['version']);
        }
    }
}
