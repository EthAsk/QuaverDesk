<?php

use Illuminate\Support\Facades\Schedule;

// Commands in app/Console/Commands are registered automatically.
// Run `php artisan schedule:work` (or a cron entry for `schedule:run`) in production.

Schedule::command('recordings:prune')->dailyAt('03:15')->withoutOverlapping();

// Heartbeats, so the health check can tell the scheduler and the queue worker are running.
Schedule::call(fn () => \Illuminate\Support\Facades\Cache::forever(\App\Support\Health::SCHEDULER, now()->toIso8601String()))->everyMinute()->name('heartbeat-scheduler');
Schedule::job(new \App\Jobs\QueueHeartbeat())->everyFiveMinutes()->name('heartbeat-queue');

// Is there a newer release? (Self-hosted only; see UpdateCheck.)
Schedule::command('quaverdesk:check-updates')->dailyAt('05:10')->when(fn () => app(\App\Support\UpdateCheck::class)->enabled());

// Weekly (Monday) and monthly (1st) call reports, for companies that asked for them.
Schedule::command('reports:send')->dailyAt('07:00')->withoutOverlapping();

// Webhook deliveries are kept for a week.
Schedule::command('webhooks:prune')->dailyAt('03:30')->withoutOverlapping();

// Calls whose hang-up never arrived stop showing as ringing.
Schedule::command('calls:close-stale')->hourly()->withoutOverlapping();

// Daily check for a renewed licence key (only if LICENCE_SERVER_URL is set).
Schedule::command('licence:refresh')->dailyAt('04:20')->when(fn () => (bool) config('licensing.server_url'));

// Desk phones locked to a host name (an office's dynamic DNS) follow its address.
Schedule::command('phones:refresh-locks')->everyFiveMinutes()->withoutOverlapping();

// A deployment's own scheduled tasks, in routes/extra/console/*.php. None ship with QuaverDesk.
foreach (glob(__DIR__.'/extra/console/*.php') ?: [] as $extra) {
    require $extra;
}
