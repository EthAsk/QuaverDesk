<?php

use Illuminate\Support\Facades\Schedule;

// Commands in app/Console/Commands are registered automatically.
// Run `php artisan schedule:work` (or a cron entry for `schedule:run`) in production.

Schedule::command('recordings:prune')->dailyAt('03:15')->withoutOverlapping();

// Daily check for a renewed licence key (only if LICENCE_SERVER_URL is set).
Schedule::command('licence:refresh')->dailyAt('04:20')->when(fn () => (bool) config('licensing.server_url'));
