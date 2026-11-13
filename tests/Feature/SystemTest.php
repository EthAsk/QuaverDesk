<?php

namespace Tests\Feature;

use App\Jobs\RunBackup;
use App\Models\Setting;
use App\Models\User;
use App\Support\Backups;
use App\Support\Health;
use App\Support\UpdateCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Admin → System: updates, health and backups (self-hosted). */
class SystemTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->owner()->withTwoFactor()->create();
        $this->acknowledgeTerms($this->owner);
        config(['quaverdesk.version' => '1.2.0', 'quaverdesk.update_check' => true, 'tenancy.enabled' => false]);
        Cache::flush();

        $this->dir = storage_path('framework/testing/backups-'.bin2hex(random_bytes(4)));
        File::makeDirectory($this->dir, 0755, true);
        File::put($this->dir.'/backup-script', "#!/bin/sh\n");
        config(['quaverdesk.backup.dir' => $this->dir, 'quaverdesk.backup.command' => $this->dir.'/backup-script', 'quaverdesk.backup.status_file' => $this->dir.'/status.json']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function release(string $tag, int $status = 200): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => $tag, 'name' => "QuaverDesk {$tag}"], $status)]);
    }

    private function backupStatus(array $a = []): void
    {
        File::put($this->dir.'/status.json', json_encode($a + ['at' => now()->toIso8601String(), 'ok' => true, 'file' => 'x', 'size' => 5242880, 'count' => 1, 'message' => 'ok']));
    }

    // ---- updates -----------------------------------------------------------------------------

    public function test_a_newer_release_shows_a_banner_to_admins_only_and_can_be_dismissed(): void
    {
        $this->release('v1.3.0');
        $this->artisan('quaverdesk:check-updates')->assertSuccessful();

        $this->actingAs($this->owner)->get('/dashboard')->assertOk()->assertSee('QuaverDesk 1.3.0 is available')->assertSee("you're on 1.2.0", false);
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertDontSee('is available');

        $this->actingAs($this->owner)->post('/admin/system/dismiss-update')->assertRedirect();
        $this->get('/dashboard')->assertDontSee('QuaverDesk 1.3.0 is available');

        // A later release is news again.
        $this->release('v1.3.1');
        app(UpdateCheck::class)->refresh();
        $this->get('/dashboard')->assertSee('QuaverDesk 1.3.1 is available');
    }

    public function test_no_banner_when_up_to_date_ahead_or_the_answer_is_nonsense(): void
    {
        foreach (['v1.2.0', 'v1.1.0', 'latest', '', 'v2'] as $tag) {
            Cache::flush();
            $this->release($tag);
            app(UpdateCheck::class)->refresh();
            $this->actingAs($this->owner)->get('/dashboard')->assertDontSee('is available');
        }
    }

    public function test_a_failed_check_keeps_what_was_known_and_checks_can_be_turned_off_or_are_not_made_on_the_hosted_service(): void
    {
        $this->release('v1.3.0');
        app(UpdateCheck::class)->refresh();
        $this->release('v9.9.9', 500);
        app(UpdateCheck::class)->refresh();
        $this->assertSame('1.3.0', app(UpdateCheck::class)->latest()['version']);

        config(['quaverdesk.update_check' => false]);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake();
        $this->assertNull(app(UpdateCheck::class)->available());
        $this->assertNull(app(UpdateCheck::class)->refresh());
        $this->actingAs($this->owner)->get('/dashboard')->assertDontSee('is available');
        Http::assertNothingSent();

        config(['quaverdesk.update_check' => true, 'tenancy.enabled' => true]);
        $this->assertFalse(app(UpdateCheck::class)->enabled());
    }

    public function test_the_check_asks_the_public_release_list_and_sends_nothing_about_the_install(): void
    {
        $this->release('v1.3.0');
        app(UpdateCheck::class)->refresh();

        Http::assertSent(fn ($r) => $r->url() === 'https://api.github.com/repos/EthAsk/QuaverDesk/releases/latest' && ! str_contains($r->body(), 'version') && $r->header('Authorization') === []);
    }

    // ---- the page ----------------------------------------------------------------------------

    public function test_the_page_is_for_admins_on_self_hosted_installs_only(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/system')->assertForbidden();
        $this->actingAs($this->owner)->get('/admin/system')->assertOk()->assertSee('Health')->assertSee('Backups')->assertSee('1.2.0');

        config(['tenancy.enabled' => true]);
        $this->get('/admin/system')->assertNotFound();
    }

    public function test_check_now_and_what_to_do_about_an_update(): void
    {
        $this->release('v1.3.0');
        $this->actingAs($this->owner)->post('/admin/system/check-updates')->assertRedirect();
        $this->get('/admin/system')->assertOk()->assertSee('1.3.0 is available')->assertSee('sudo quaverdesk-deploy')->assertSee('never from this page');
    }

    // ---- backups -----------------------------------------------------------------------------

    public function test_the_last_backup_and_the_files_are_shown(): void
    {
        $this->backupStatus();
        File::put($this->dir.'/quaverdesk-20261109T020000Z.tar.gz.age', str_repeat('x', 2048));
        File::put($this->dir.'/not-a-backup.txt', 'x');

        $page = $this->actingAs($this->owner)->get('/admin/system')->assertOk();
        $page->assertSee('Last backup worked')->assertSee('5.0 MB')->assertSee('quaverdesk-20261109T020000Z.tar.gz.age', false);
        $page->assertDontSee('not-a-backup.txt');

        $this->backupStatus(['ok' => false, 'message' => 'Missing the key']);
        $this->get('/admin/system')->assertSee('Last backup failed')->assertSee('Missing the key');
    }

    public function test_not_set_up_says_so(): void
    {
        File::delete($this->dir.'/backup-script');
        $this->actingAs($this->owner)->get('/admin/system')->assertSee("Backups aren't set up", false)->assertDontSee('Back up now');
        $this->post('/admin/system/backup')->assertSessionHas('status', fn ($s) => str_contains($s, 'not set up'));
    }

    public function test_back_up_now_runs_the_backup_in_the_background_once_at_a_time(): void
    {
        Queue::fake();
        $this->actingAs($this->owner)->post('/admin/system/backup')->assertSessionHas('status', fn ($s) => str_contains($s, 'Backing up now'));
        Queue::assertPushed(RunBackup::class, 1);

        $this->get('/admin/system')->assertSee('Backing up…');
        $this->post('/admin/system/backup')->assertSessionHas('status', 'A backup is already running.');
        Queue::assertPushed(RunBackup::class, 1);
    }

    public function test_the_job_runs_only_the_named_script_through_sudo_and_reports_failure(): void
    {
        Process::fake(['*' => Process::result('', '', 0)]);
        $backups = app(Backups::class);
        $backups->claim();
        (new RunBackup())->handle($backups);
        Process::assertRan(fn ($p) => $p->command === ['sudo', '-n', $this->dir.'/backup-script']);
        $this->assertFalse($backups->running(), 'The claim is given back.');

        Process::fake(['*' => Process::result('', 'sudo: a password is required', 1)]);
        $backups->claim();
        (new RunBackup())->handle($backups);
        $this->assertStringContainsString('sudoers', Cache::get(Backups::ERROR));
        $this->actingAs($this->owner)->get('/admin/system')->assertSee('sudoers');
    }

    public function test_a_backup_can_be_downloaded_after_confirming_the_password_and_nothing_else_can(): void
    {
        File::put($this->dir.'/quaverdesk-20261109T020000Z.tar.gz.age', 'ENCRYPTED');
        File::put($this->dir.'/secret.txt', 'nope');
        $good = '/admin/system/backups/quaverdesk-20261109T020000Z.tar.gz.age';

        $this->actingAs($this->owner)->get($good)->assertRedirect(route('password.confirm'));
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->get($good)->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=quaverdesk-20261109T020000Z.tar.gz.age');

        foreach (['secret.txt', '..%2F..%2F.env', 'quaverdesk-2026.tar.gz.age', 'quaverdesk-20261109T020000Z.tar.gz.age.txt', '%2e%2e%2fstatus.json'] as $name) {
            $this->get('/admin/system/backups/'.$name)->assertNotFound();
        }
        $this->actingAs(User::factory()->create())->get($good)->assertForbidden();
    }

    // ---- health ------------------------------------------------------------------------------

    private function health(): array
    {
        return collect(app(Health::class)->run())->keyBy('name')->map(fn ($r) => $r['status'])->all();
    }

    public function test_health_flags_a_dead_scheduler_and_queue_worker_and_a_stale_or_failed_backup(): void
    {
        config(['queue.default' => 'database']);
        $h = $this->health();
        $this->assertSame(['warn', 'warn', 'warn'], [$h['Scheduler'], $h['Queue worker'], $h['Backups']], 'Nothing seen yet is only a warning (just updated).');

        Cache::forever(Health::SCHEDULER, now()->subMinutes(2)->toIso8601String());
        Cache::forever(Health::QUEUE, now()->subMinutes(3)->toIso8601String());
        $this->backupStatus();
        $h = $this->health();
        $this->assertSame(['ok', 'ok', 'ok'], [$h['Scheduler'], $h['Queue worker'], $h['Backups']]);

        Cache::forever(Health::SCHEDULER, now()->subHour()->toIso8601String());
        Cache::forever(Health::QUEUE, now()->subHour()->toIso8601String());
        $this->backupStatus(['at' => now()->subDays(3)->toIso8601String()]);
        $h = $this->health();
        $this->assertSame(['fail', 'fail', 'warn'], [$h['Scheduler'], $h['Queue worker'], $h['Backups']]);

        $this->backupStatus(['ok' => false, 'message' => 'disk full']);
        $this->assertSame('fail', $this->health()['Backups']);
    }

    public function test_health_command_exits_non_zero_only_on_a_failure(): void
    {
        $this->app->instance(\App\Voice\VoiceServer::class, new class(config('voip.freeswitch')) extends \App\Voice\FreeSwitch\FreeSwitchServer
        {
            public function registrations(): array
            {
                return [];
            }
        });
        Cache::forever(Health::SCHEDULER, now()->toIso8601String());
        $this->backupStatus();

        $this->artisan('quaverdesk:health')->assertExitCode(0); // warnings (voice server, queue) don't fail it

        Cache::forever(Health::SCHEDULER, now()->subDay()->toIso8601String());
        $this->artisan('quaverdesk:health')->expectsOutputToContain('Scheduler')->assertExitCode(1);
    }

    public function test_the_queue_heartbeat_job_leaves_its_mark_and_the_scheduler_has_both_heartbeats(): void
    {
        (new \App\Jobs\QueueHeartbeat())->handle();
        $this->assertNotNull(Cache::get(Health::QUEUE));

        $this->artisan('schedule:list')->expectsOutputToContain('heartbeat')->assertSuccessful();
    }

    public function test_pending_database_updates_are_a_failure(): void
    {
        \Illuminate\Support\Facades\DB::table('migrations')->orderByDesc('id')->limit(2)->delete();

        $this->assertSame('fail', $this->health()['Database']);
    }

    // ---- the scripts -------------------------------------------------------------------------

    public function test_the_scripts_are_valid_bash_and_the_deploy_script_backs_up_first_and_checks_health_after(): void
    {
        foreach (['backup.sh', 'deploy.sh'] as $script) {
            $this->assertTrue(Process::run(['bash', '-n', base_path('deploy/'.$script)])->successful(), "{$script} has a syntax error");
        }
        $deploy = file_get_contents(base_path('deploy/deploy.sh'));
        $this->assertLessThan(strpos($deploy, 'git pull'), strpos($deploy, 'BACKUP_CMD'), 'The backup comes before the code is touched.');
        $this->assertGreaterThan(strpos($deploy, 'queue:restart'), strpos($deploy, 'quaverdesk:health'));
        $this->assertStringNotContainsString('checkout', $deploy, 'Updates follow the latest release only: no pinning to a version.');
        $this->assertStringContainsString('quaverdesk ALL=(root) NOPASSWD: /usr/local/sbin/quaverdesk-backup', file_get_contents(base_path('deploy/sudoers/quaverdesk-backup')));
    }
}
