<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The server's backups, as the web page sees them. The backups themselves are made by the root-owned
 * quaverdesk-backup script (nightly, and on "Back up now"): encrypted to a public key, so this server can't
 * read them. The script leaves a small status file; its folder is readable by the app's group so the
 * encrypted files can be listed and downloaded to keep somewhere else. Restoring is never done from the web.
 */
class Backups
{
    public const FILE = '/^quaverdesk-\d{8}T\d{6}Z\.tar\.gz\.age$/';

    public const ERROR = 'backup-web-error';

    private const RUNNING = 'backup-running';

    public function available(): bool
    {
        return ! config('tenancy.enabled') && is_file((string) config('quaverdesk.backup.command'));
    }

    /** @return array{at: string, ok: bool, file: string, size: int, count: int, message: string}|null */
    public function status(): ?array
    {
        $raw = @file_get_contents((string) config('quaverdesk.backup.status_file'));
        $status = $raw ? json_decode($raw, true) : null;

        return is_array($status) && isset($status['at'], $status['ok']) ? $status : null;
    }

    /** True when the last backup worked and is recent enough (nightly, so a day and a half). */
    public function recent(): bool
    {
        $s = $this->status();

        return $s !== null && $s['ok'] && now()->diffInHours(\Illuminate\Support\Carbon::parse($s['at']), true) < 36;
    }

    /** @return list<array{name: string, size: int, at: int}> newest first */
    public function files(): array
    {
        $dir = rtrim((string) config('quaverdesk.backup.dir'), '/');
        $out = [];
        foreach (@scandir($dir) ?: [] as $name) {
            if (preg_match(self::FILE, $name) && is_file("{$dir}/{$name}")) {
                $out[] = ['name' => $name, 'size' => (int) @filesize("{$dir}/{$name}"), 'at' => (int) @filemtime("{$dir}/{$name}")];
            }
        }
        usort($out, fn ($a, $b) => $b['at'] <=> $a['at']);

        return $out;
    }

    /** The full path of one backup file, or null (the name must be exactly one of ours: no folders, no tricks). */
    public function path(string $name): ?string
    {
        if (! preg_match(self::FILE, $name)) {
            return null;
        }
        $path = rtrim((string) config('quaverdesk.backup.dir'), '/').'/'.$name;

        return is_file($path) && is_readable($path) ? $path : null;
    }

    public function running(): bool
    {
        return Cache::has(self::RUNNING);
    }

    /** Claims the right to start one; false if one is already going. */
    public function claim(): bool
    {
        return Cache::add(self::RUNNING, true, now()->addMinutes(15));
    }

    public function release(): void
    {
        Cache::forget(self::RUNNING);
    }
}
