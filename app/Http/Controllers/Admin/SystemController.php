<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunBackup;
use App\Support\Backups;
use App\Support\Health;
use App\Support\UpdateCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Admin → System (self-hosted installs): version and updates, health, and backups. */
class SystemController extends Controller
{
    public function __construct()
    {
        // The hosted service is run by its operator, not from a company's own admin pages.
        abort_if(config('tenancy.enabled'), 404);
    }

    public function show(UpdateCheck $updates, Backups $backups, Health $health): View
    {
        return view('admin.system', [
            'version' => (string) config('quaverdesk.version'),
            'latest' => $updates->latest(),
            'updateAvailable' => $updates->available(),
            'updateChecks' => $updates->enabled(),
            'health' => $health->run(),
            'backupsReady' => $backups->available(),
            'backup' => $backups->status(),
            'backupFiles' => $backups->files(),
            'backupRunning' => $backups->running(),
            'backupError' => Cache::get(Backups::ERROR),
        ]);
    }

    public function checkUpdates(UpdateCheck $updates): RedirectResponse
    {
        if (! $updates->enabled()) {
            return back()->with('status', 'Update checks are turned off (QUAVERDESK_UPDATE_CHECK=false).');
        }

        return back()->with('status', $updates->refresh() === null ? 'Could not reach the release list. Try again later.' : 'Checked.');
    }

    public function dismissUpdate(UpdateCheck $updates): RedirectResponse
    {
        $updates->dismiss();

        return back();
    }

    /** Takes a backup now, in the background (it can take a few minutes). */
    public function backup(Backups $backups): RedirectResponse
    {
        if (! $backups->available()) {
            return back()->with('status', 'Backups are not set up on this server yet. See docs/DEPLOYMENT.md, step A8.');
        }
        if (! $backups->claim()) {
            return back()->with('status', 'A backup is already running.');
        }
        Cache::forget(Backups::ERROR);
        RunBackup::dispatch();

        return back()->with('status', 'Backing up now. It takes a few minutes: refresh this page to see when it finishes.');
    }

    /** One encrypted backup, to keep somewhere else. It is unreadable without the private key you keep off the server. */
    public function download(Backups $backups, string $file): BinaryFileResponse
    {
        $path = $backups->path($file);
        abort_if($path === null, 404);

        return response()->download($path, $file, ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store, private']);
    }
}
