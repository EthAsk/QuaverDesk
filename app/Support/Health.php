<?php

namespace App\Support;

use App\Voice\VoiceServer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Is this install in good shape? Each check answers ok, warn (worth fixing, nothing broken) or fail (something
 * isn't working). Run by `php artisan quaverdesk:health`, which `quaverdesk-deploy` runs after every update,
 * and shown on Admin → System.
 */
class Health
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /** A heartbeat the scheduler and the worker leave, so we can tell they are running. */
    public const SCHEDULER = 'heartbeat:scheduler';
    public const QUEUE = 'heartbeat:queue';

    public function __construct(
        private readonly Backups $backups,
        private readonly LicenceManager $licences,
        private readonly VoiceServer $voice,
    ) {
    }

    /** @return list<array{name: string, status: string, detail: string}> */
    public function run(): array
    {
        $checks = [
            'Database' => fn () => $this->database(),
            'Storage' => fn () => $this->storage(),
            'Disk space' => fn () => $this->disk(),
            'Scheduler' => fn () => $this->heartbeat(self::SCHEDULER, 5, 'The scheduler has not run lately. Check the cron entry (docs/DEPLOYMENT.md, step A8).'),
            'Queue worker' => fn () => $this->queue(),
            'Voice server' => fn () => $this->voiceServer(),
            'Licence' => fn () => $this->licence(),
            'Backups' => fn () => $this->backup(),
            'Settings' => fn () => $this->settings(),
        ];

        $out = [];
        foreach ($checks as $name => $check) {
            try {
                [$status, $detail] = $check();
            } catch (Throwable $e) {
                [$status, $detail] = [self::FAIL, 'Could not be checked: '.$e->getMessage()];
            }
            $out[] = ['name' => $name, 'status' => $status, 'detail' => $detail];
        }

        return $out;
    }

    /** @param list<array{status: string}> $results */
    public static function failed(array $results): bool
    {
        return (bool) array_filter($results, fn ($r) => $r['status'] === self::FAIL);
    }

    /** @return array{0: string, 1: string} */
    private function database(): array
    {
        DB::connection()->getPdo();
        $files = count(glob(database_path('migrations/*.php')) ?: []);
        $ran = DB::table('migrations')->count();

        return $ran < $files
            ? [self::FAIL, ($files - $ran).' database update(s) have not run. Run: php artisan migrate --force']
            : [self::OK, "Connected, {$ran} migrations run"];
    }

    private function storage(): array
    {
        foreach ([storage_path('app'), storage_path('framework'), storage_path('logs')] as $dir) {
            if (! is_dir($dir) || ! is_writable($dir)) {
                return [self::FAIL, "{$dir} is not writable by the app's user."];
            }
        }

        return [self::OK, 'Writable'];
    }

    private function disk(): array
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if (! $free || ! $total) {
            return [self::WARN, 'Could not read the free space.'];
        }
        $pct = (int) round(100 * $free / $total);
        $detail = $pct.'% free ('.round($free / 1073741824, 1).' GB)';

        return match (true) {
            $pct < 5 => [self::FAIL, "{$detail}: nearly full. Recordings and backups will start to fail."],
            $pct < 15 => [self::WARN, "{$detail}: getting full."],
            default => [self::OK, $detail],
        };
    }

    private function heartbeat(string $key, int $maxMinutes, string $problem): array
    {
        $at = Cache::get($key);
        if ($at === null) {
            return [self::WARN, 'No run seen yet. If this was just installed or updated, wait a few minutes and check again.'];
        }

        return Carbon::parse($at)->lt(now()->subMinutes($maxMinutes)) ? [self::FAIL, $problem] : [self::OK, 'Ran '.Carbon::parse($at)->diffForHumans()];
    }

    private function queue(): array
    {
        if (config('queue.default') === 'sync') {
            return [self::WARN, 'The queue runs inside web requests (QUEUE_CONNECTION=sync). Voicemail emails and AI processing are slower; use the database queue and the worker (docs/DEPLOYMENT.md, step A8).'];
        }

        return $this->heartbeat(self::QUEUE, 15, 'The queue worker is not running. Voicemail emails, AI processing and webhooks will not be sent. Start it: sudo systemctl restart quaverdesk-queue');
    }

    private function voiceServer(): array
    {
        if (! $this->voice->domain()) {
            return [self::WARN, 'No voice server is set up yet.'];
        }
        $phones = count($this->voice->registrations());

        return [self::OK, "Reachable, {$phones} phone(s) signed in"];
    }

    private function licence(): array
    {
        if (config('tenancy.enabled')) {
            return [self::OK, 'Set for each company on the hosted service'];
        }
        $problem = $this->licences->problem();
        $l = $this->licences->current();

        return match (true) {
            $problem !== null => [self::FAIL, "The licence key was rejected: {$problem}. Running as Free."],
            $l->expiresAt !== null && $l->expiresAt < now()->toDateTimeImmutable() => [self::FAIL, 'The licence has expired.'],
            $l->expiresAt !== null && $l->expiresAt < now()->addDays(14)->toDateTimeImmutable() => [self::WARN, 'The licence runs out on '.$l->expiresAt->format('j M Y').'.'],
            default => [self::OK, ucfirst($l->plan->value).' plan'],
        };
    }

    private function backup(): array
    {
        if (config('tenancy.enabled')) {
            return [self::OK, 'Backed up by the operator, not from here'];
        }
        if (! $this->backups->available()) {
            return [self::WARN, 'Backups are not set up (docs/DEPLOYMENT.md, step A8). Without them a failed disk loses everything.'];
        }
        $s = $this->backups->status();
        if ($s === null) {
            return [self::WARN, 'No backup has been made yet. Run: sudo quaverdesk-backup'];
        }
        if (! $s['ok']) {
            return [self::FAIL, 'The last backup failed: '.($s['message'] ?? '')];
        }

        return $this->backups->recent() ? [self::OK, 'Last backup '.Carbon::parse($s['at'])->diffForHumans()] : [self::WARN, 'The last backup was '.Carbon::parse($s['at'])->diffForHumans().'. They should run every night.'];
    }

    private function settings(): array
    {
        $problems = [];
        if (config('app.debug') && app()->isProduction()) {
            $problems[] = 'APP_DEBUG is on: errors show internal details to visitors';
        }
        if (! str_starts_with((string) config('app.url'), 'https://') && app()->isProduction()) {
            $problems[] = 'APP_URL is not https';
        }

        return $problems ? [self::WARN, implode('; ', $problems).'.'] : [self::OK, 'Looks right'];
    }
}
