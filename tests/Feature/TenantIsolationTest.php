<?php

namespace Tests\Feature;

use App\Domain\Licensing\LicenceCodec;
use App\Domain\Licensing\Plan;
use App\Models\Call;
use App\Models\DeskPhone;
use App\Models\IvrMenu;
use App\Models\Mailbox;
use App\Models\PhoneNumber;
use App\Models\Recording;
use App\Models\RingGroup;
use App\Models\Setting;
use App\Models\SipTrunk;
use App\Models\Sound;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RecordingStore;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use ZipArchive;
use Tests\TestCase;

/** One company can never see, change or keep another's data. */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenancy $tenancy;
    private Tenant $acme;
    private Tenant $bolt;

    /** One of everything Bolt owns, by the route parameter that names it. @var array<string, int> */
    private array $bolts = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true, 'tenancy.base_domain' => 'quaverdesk.test']);
        $this->tenancy = app(Tenancy::class);
        $this->acme = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $this->bolt = Tenant::query()->create(['name' => 'Bolt', 'slug' => 'bolt']);

        $keys = LicenceCodec::generateKeyPair();
        config(['voip.licence_public_key' => $keys['public']]);
        foreach ([$this->acme, $this->bolt] as $tenant) {
            $this->in($tenant, function () use ($keys) {
                Setting::put('licence_key', LicenceCodec::issue($keys['secret'], 'Test Ltd', Plan::Pro, 25, null));
                $this->acknowledgeTerms(User::factory()->owner()->withTwoFactor()->create());
            });
        }

        $this->bolts = $this->in($this->bolt, function () {
            $user = User::factory()->create(['name' => 'Bolt Person']);
            $trunk = SipTrunk::create(['name' => 'Bolt trunk', 'provider' => 'other', 'inbound_ips' => [['ip' => '203.0.113.9', 'prefix' => 32]],
                'origination_uris' => ['sip:sbc.example.net'], 'status' => 'active', 'auth_password' => 'trunk-secret-1']);
            $call = Call::create(['carrier_sid' => self::callId(1), 'user_id' => $user->id, 'direction' => 'internal', 'status' => 'ended']);

            return [
                'user' => $user->id,
                'trunk' => $trunk->id,
                'number' => PhoneNumber::create(['e164' => '+442079460000', 'sip_trunk_id' => $trunk->id, 'destination' => 'hangup'])->id,
                'group' => RingGroup::create(['name' => 'Bolt group', 'strategy' => 'simultaneous', 'ring_seconds' => 20])->id,
                'menu' => IvrMenu::create(['name' => 'Bolt menu', 'greeting' => 'Hi', 'options' => []])->id,
                'mailbox' => Mailbox::create(['name' => 'Bolt box', 'greeting' => 'Leave a message'])->id,
                'phone' => DeskPhone::create(['user_id' => $user->id, 'label' => 'Desk', 'sip_username' => 'u'.$user->id.'-abc123', 'sip_password' => 'desk-secret-1'])->id,
                'sound' => Sound::create(['name' => 'Bolt music', 'file' => str_repeat('b', 40), 'seconds' => 3])->id,
                'passkey' => DB::table('passkeys')->insertGetId(['user_id' => $user->id, 'name' => 'Bolt key', 'credential_id' => 'bolt-credential',
                    'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]),
                'recording' => Recording::create(['kind' => 'call', 'carrier_sid' => self::callId(1).'-call', 'call_id' => $call->id,
                    'status' => 'stored', 'transcript' => 'Bolt secret plans'])->id,
            ];
        });
        // The operator console's company pages don't exist on a company's address.
        $this->bolts['tenant'] = $this->bolt->id;
        $this->tenancy->set(null);
    }

    private function in(Tenant $tenant, \Closure $callback): mixed
    {
        return $this->tenancy->run($tenant, $callback);
    }

    /** Every row Bolt owns, to prove nothing changed. */
    private function boltSnapshot(): array
    {
        return $this->in($this->bolt, fn () => collect([User::class, SipTrunk::class, PhoneNumber::class, RingGroup::class, IvrMenu::class,
            Mailbox::class, DeskPhone::class, Sound::class, Recording::class, Call::class, Setting::class])
            ->mapWithKeys(fn ($m) => [$m => $m::query()->orderBy('id')->get()->map->getAttributes()->all()])
            ->put('passkeys', DB::table('passkeys')->orderBy('id')->get()->all())->all());
    }

    public function test_no_route_reaches_another_companys_records(): void
    {
        $admin = $this->in($this->acme, fn () => User::query()->first());
        $before = $this->boltSnapshot();
        $tried = 0;

        foreach (app('router')->getRoutes() as $route) {
            /** @var Route $route */
            // Not records: prompt/sound file names (unguessable, voice server only) and reset tokens.
            $params = array_values(array_diff($route->parameterNames(), ['hash', 'file', 'token']));
            if (! $params || str_starts_with($route->uri(), 'webhooks/')) {
                continue;
            }
            foreach ($params as $param) {
                $this->assertArrayHasKey($param, $this->bolts, "Route {$route->uri()} takes {{$param}}: add a Bolt record for it to this test.");
            }

            $uri = preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $this->bolts[$m[1]], $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);
                $status = $this->call($method, 'http://acme.quaverdesk.test/'.$uri, ['name' => 'Hijacked', 'extension' => '555'])->getStatusCode();

                // Signed invite links fail their signature, and passkeys check their owner (403);
                // everything else finds nothing (404).
                $expected = preg_match('/^(invite\.|passkey\.)/', (string) $route->getName()) ? [403, 404] : [404];
                $this->assertContains($status, $expected, "{$method} /{$uri} answered {$status} for another company's record.");
                $tried++;
            }
        }

        $this->assertGreaterThan(25, $tried);

        // Control: the same admin, same permissions, reaches their own records, so the 404s above are the company check.
        $own = $this->in($this->acme, fn () => RingGroup::create(['name' => 'Acme group', 'strategy' => 'simultaneous', 'ring_seconds' => 20]));
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);
        $this->get("http://acme.quaverdesk.test/admin/ring-groups/{$own->id}")->assertOk()->assertSee('Acme group');
        $this->assertEquals($before, $this->boltSnapshot(), 'Another company\'s data changed.');
    }

    public function test_export_holds_the_companys_data_and_audio_but_no_secrets(): void
    {
        $audio = self::wav(2);
        $this->in($this->bolt, function () use ($audio) {
            app(RecordingStore::class)->put(Recording::query()->first(), $audio);
            Setting::put('ai', ['transcription' => ['base_url' => 'https://api.example.test', 'api_key' => 'sk-should-not-leak']]);
        });

        $this->artisan('tenant:export', ['slug' => 'bolt'])->assertSuccessful();
        $path = collect(File::files(storage_path('app/exports')))->last()->getPathname();
        $zip = new ZipArchive();
        $zip->open($path);

        try {
            $all = '';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $all .= $zip->getFromIndex($i);
            }
            $users = json_decode($zip->getFromName('data/users.json'), true);
            $recording = $this->bolts['recording'];

            $this->assertContains('Bolt Person', array_column($users, 'name'));
            $this->assertNotContains('Acme Owner', array_column($users, 'name'));
            $this->assertStringContainsString('Bolt secret plans', $zip->getFromName('data/recordings.json'));
            $this->assertSame($audio, $zip->getFromName("audio/recordings/{$recording}.wav"));
            $this->assertNotFalse($zip->getFromName('data/ring_group_user.json'));
            foreach (['password-1234', '$2y$', 'trunk-secret-1', 'desk-secret-1', 'sk-should-not-leak', 'qdl1.', 'two_factor_secret', 'sip_secret'] as $secret) {
                $this->assertStringNotContainsString($secret, $all, "The export leaked {$secret}");
            }
            $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        } finally {
            $zip->close();
            File::deleteDirectory(storage_path('app/exports'));
        }
    }

    public function test_deleting_a_company_removes_everything_it_owns_and_nothing_else(): void
    {
        $recordingFile = $this->in($this->bolt, function () {
            $recording = Recording::query()->first();
            app(RecordingStore::class)->put($recording, self::wav(1));

            return storage_path('app/private/'.$recording->fresh()->path);
        });
        $acmeBefore = $this->in($this->acme, fn () => User::query()->count());

        $this->artisan('tenant:delete', ['slug' => 'bolt', '--skip-export' => true])->assertFailed(); // still active
        $this->artisan('tenant:suspend', ['slug' => 'bolt'])->assertSuccessful();
        $this->artisan('tenant:delete', ['slug' => 'bolt', '--skip-export' => true])
            ->expectsQuestion('Type the slug (bolt) to confirm', 'nope')->assertFailed();
        $this->assertNotNull(Tenant::query()->find($this->bolt->id));

        $this->artisan('tenant:delete', ['slug' => 'bolt', '--skip-export' => true])
            ->expectsQuestion('Type the slug (bolt) to confirm', 'bolt')->assertSuccessful();

        $this->assertNull(Tenant::query()->find($this->bolt->id));
        foreach (['users', 'calls', 'recordings', 'sip_trunks', 'phone_numbers', 'ring_groups', 'ivr_menus', 'mailboxes', 'desk_phones', 'sounds', 'settings', 'acknowledgements'] as $table) {
            $this->assertSame(0, DB::table($table)->where('tenant_id', $this->bolt->id)->count(), "{$table} still has Bolt's rows");
        }
        $this->assertFileDoesNotExist($recordingFile);
        $this->assertSame($acmeBefore, $this->in($this->acme, fn () => User::query()->count()));
        $this->assertNotNull($this->in($this->acme, fn () => Setting::get('licence_key')));
    }

    public function test_one_company_is_restored_from_its_export_without_touching_the_others(): void
    {
        $audio = self::wav(2);
        $b = $this->bolts;
        $this->in($this->bolt, function () use ($audio, $b) {
            app(RecordingStore::class)->put(Recording::query()->first(), $audio);
            File::ensureDirectoryExists(dirname(Sound::query()->first()->path()));
            file_put_contents(Sound::query()->first()->path(), $audio);
            RingGroup::query()->first()->members()->attach($b['user'], ['position' => 1]);
            Mailbox::query()->first()->members()->attach($b['user']);
            IvrMenu::query()->first()->update(['options' => ['1' => "user:{$b['user']}", '2' => "ring_group:{$b['group']}", '3' => 'ivr:999999']]);
            PhoneNumber::query()->first()->update(['destination' => "ivr:{$b['menu']}", 'closed_destination' => "voicemail:{$b['mailbox']}"]);
            Setting::put('sounds.hold_music', $b['sound']);
        });
        $this->artisan('tenant:export', ['slug' => 'bolt'])->assertSuccessful();
        $export = collect(File::files(storage_path('app/exports')))->last()->getPathname();
        $acmeBefore = $this->in($this->acme, fn () => [User::query()->count(), Setting::query()->count()]);

        try {
            // While the original is still here, its people and numbers would clash.
            $this->artisan('tenant:import', ['file' => $export, 'slug' => 'bolt2'])->expectsOutputToContain('already on the service')->assertFailed();
            $this->assertNull(Tenant::query()->firstWhere('slug', 'bolt2'));

            $this->artisan('tenant:suspend', ['slug' => 'bolt'])->assertSuccessful();
            $this->artisan('tenant:delete', ['slug' => 'bolt', '--skip-export' => true])->expectsQuestion('Type the slug (bolt) to confirm', 'bolt')->assertSuccessful();
            $this->artisan('tenant:import', ['file' => $export, 'slug' => 'acme'])->assertExitCode(2); // taken
            $this->artisan('tenant:import', ['file' => $export, 'slug' => 'bolt'])->assertSuccessful();
        } finally {
            File::deleteDirectory(storage_path('app/exports'));
        }

        $bolt = Tenant::query()->firstWhere('slug', 'bolt');
        $this->assertFalse($bolt->isActive(), 'A restored company waits for the operator to check it.');
        $this->in($bolt, function () use ($audio) {
            $user = User::query()->firstWhere('name', 'Bolt Person');
            $group = RingGroup::query()->first();
            $menu = IvrMenu::query()->first();
            $mailbox = Mailbox::query()->first();
            $sound = Sound::query()->first();

            $this->assertSame([$user->id], $group->members()->pluck('users.id')->all());
            $this->assertSame([$user->id], $mailbox->members()->pluck('users.id')->all());
            $this->assertSame(['1' => "user:{$user->id}", '2' => "ring_group:{$group->id}", '3' => 'hangup'], $menu->options);
            $number = PhoneNumber::query()->first();
            $this->assertSame(["ivr:{$menu->id}", "voicemail:{$mailbox->id}", SipTrunk::query()->value('id')], [$number->destination, $number->closed_destination, $number->sip_trunk_id]);
            $this->assertSame($sound->id, Setting::get('sounds.hold_music'));
            $this->assertSame($audio, file_get_contents($sound->path()));
            $this->assertSame($audio, app(RecordingStore::class)->get(Recording::query()->first()));
            $this->assertSame('Bolt secret plans', Recording::query()->first()->transcript);
            $this->assertSame(Call::query()->value('id'), Recording::query()->first()->call_id);

            // No secrets came back: new passwords, no two-factor, new phone settings, no trunk password.
            $this->assertFalse(User::query()->first()->hasTwoFactorEnabled());
            $this->assertNotSame('desk-secret-1', DeskPhone::query()->first()->sip_password);
            $this->assertStringStartsWith('u'.$user->id.'-', DeskPhone::query()->first()->sip_username);
            $this->assertNull(SipTrunk::query()->first()->auth_password);
        });
        $this->assertSame($acmeBefore, $this->in($this->acme, fn () => [User::query()->count(), Setting::query()->count()]));
    }

    public function test_a_suspended_company_cannot_sign_in_until_resumed(): void
    {
        $this->artisan('tenant:suspend', ['slug' => 'bolt'])->assertSuccessful();
        $this->get('http://bolt.quaverdesk.test/login')->assertForbidden();

        $this->artisan('tenant:suspend', ['slug' => 'bolt', '--resume' => true])->assertSuccessful();
        $this->get('http://bolt.quaverdesk.test/login')->assertOk();
    }

    public function test_queued_emails_link_to_the_companys_own_address_and_the_voice_server_to_its_own(): void
    {
        config(['queue.default' => 'database', 'voip.freeswitch.app_url' => 'https://voice-app.test']);
        $this->in($this->bolt, function () {
            RecordLinks::dispatch();
        });
        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        $this->assertSame('http://bolt.quaverdesk.test/calls', Cache::get('test:links')['email']);
        $this->assertStringStartsWith('https://voice-app.test/voice/sounds/', Cache::get('test:links')['voice']);
    }
}

class RecordLinks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public function handle(): void
    {
        Cache::forever('test:links', ['email' => route('calls'), 'voice' => Sound::query()->first()->url()]);
    }
}
