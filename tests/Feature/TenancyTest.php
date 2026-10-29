<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Sound;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\NoTenant;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Passkeys\Passkeys;
use Tests\TestCase;

/** The hosted service: many companies on one install, none able to see another. */
class TenancyTest extends TestCase
{
    use RefreshDatabase;

    private Tenancy $tenancy;
    private Tenant $acme;
    private Tenant $bolt;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true, 'tenancy.base_domain' => 'quaverdesk.test']);
        $this->tenancy = app(Tenancy::class);

        $this->acme = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $this->bolt = Tenant::query()->create(['name' => 'Bolt', 'slug' => 'bolt', 'domain' => 'phones.bolt.example']);
    }

    private function in(Tenant $tenant, \Closure $callback): mixed
    {
        return $this->tenancy->run($tenant, $callback);
    }

    private function admin(Tenant $tenant): User
    {
        return $this->in($tenant, fn () => User::factory()->owner()->withTwoFactor()->create(['name' => $tenant->name.' Owner']));
    }

    public function test_the_company_comes_from_the_web_address(): void
    {
        $this->get('http://nobody.quaverdesk.test/login')->assertNotFound();
        $this->get('http://quaverdesk.test.evil.example/login')->assertNotFound();
        $this->get('http://acme.quaverdesk.test/login')->assertOk();
        $this->get('http://phones.bolt.example/login')->assertOk();
        $this->assertSame($this->bolt->id, $this->tenancy->current()->id);

        $this->acme->forceFill(['status' => Tenant::STATUS_SUSPENDED])->save();
        $this->get('http://acme.quaverdesk.test/login')->assertForbidden();
    }

    public function test_each_company_only_sees_its_own_people(): void
    {
        $a = $this->admin($this->acme);
        $b = $this->admin($this->bolt);

        // Extensions are per company, so both owners get 201.
        $this->assertSame(['201', '201'], [$a->extension, $b->extension]);
        $this->assertSame([$a->id], $this->in($this->acme, fn () => User::query()->pluck('id')->all()));

        $this->actingAs($a)->withSession(['auth.password_confirmed_at' => time()]);
        $this->get('http://acme.quaverdesk.test/admin/users')->assertOk()->assertSee('Acme Owner')->assertDontSee('Bolt Owner');

        // Naming another company's person in a URL finds nothing.
        $this->patch("http://acme.quaverdesk.test/admin/users/{$b->id}/extension", ['extension' => '555'])->assertNotFound();
        $this->assertSame('201', $b->fresh()->extension);
    }

    public function test_a_person_cannot_sign_in_to_another_company(): void
    {
        $a = $this->admin($this->acme);

        $this->post('http://phones.bolt.example/login', ['email' => $a->email, 'password' => 'password-1234']);
        $this->assertGuest();

        // A session from one company doesn't find its person on another company's address.
        $provider = auth()->createUserProvider('users');
        $this->assertNull($this->in($this->bolt, fn () => $provider->retrieveById($a->id)));
        $this->assertNotNull($this->in($this->acme, fn () => $provider->retrieveById($a->id)));
    }

    public function test_a_suspended_company_keeps_only_its_phone_for_emergency_calls(): void
    {
        $a = $this->admin($this->acme);
        $this->acme->forceFill(['status' => Tenant::STATUS_SUSPENDED])->save();
        $this->actingAs($a);

        $this->in($this->acme, fn () => $this->acknowledgeTerms($a));
        $this->get('http://acme.quaverdesk.test/phone')->assertOk()->assertSee('Only emergency calls (999 and 112)');
        $this->get('http://acme.quaverdesk.test/dashboard')->assertForbidden();
        $this->get('http://acme.quaverdesk.test/softphone')->assertForbidden();
        $this->post('http://acme.quaverdesk.test/login', ['email' => $a->email, 'password' => 'password-1234'])->assertForbidden();
    }

    public function test_a_company_without_an_owner_cannot_be_claimed_by_signing_up(): void
    {
        config(['voip.web_setup' => true]);

        $this->get('http://acme.quaverdesk.test/register')->assertNotFound();
        $this->post('http://acme.quaverdesk.test/register', ['name' => 'Mallory', 'email' => 'm@evil.test', 'password' => 'correct-horse-42', 'password_confirmation' => 'correct-horse-42'])
            ->assertNotFound();
        $this->assertSame(0, $this->in($this->acme, fn () => User::query()->count()));
    }

    public function test_passkeys_belong_to_each_companys_own_address(): void
    {
        $b = $this->admin($this->bolt);
        DB::table('passkeys')->insert(['user_id' => $b->id, 'name' => 'Bolt key', 'credential_id' => 'bolt-credential', 'credential' => '{}',
            'created_at' => now(), 'updated_at' => now()]);

        $this->get('http://acme.quaverdesk.test/login')->assertOk();
        $this->assertSame('acme.quaverdesk.test', config('passkeys.relying_party_id'));
        $this->assertSame(['http://acme.quaverdesk.test'], config('passkeys.allowed_origins'));

        // A sign-in on Acme's address never finds Bolt's passkey.
        $this->assertNull($this->in($this->acme, fn () => Passkeys::passkeyModel()::query()->firstWhere('credential_id', 'bolt-credential')));
        $this->assertNotNull($this->in($this->bolt, fn () => Passkeys::passkeyModel()::query()->firstWhere('credential_id', 'bolt-credential')));
    }

    public function test_forms_cannot_name_another_companys_records(): void
    {
        $a = $this->admin($this->acme);
        $theirs = $this->in($this->bolt, fn () => Sound::query()->create(['name' => 'Their music', 'file' => str_repeat('b', 40), 'seconds' => 3]));

        $this->actingAs($a)->withSession(['auth.password_confirmed_at' => time()]);
        $this->put('http://acme.quaverdesk.test/admin/sounds/hold-music', ['sound_id' => $theirs->id])
            ->assertSessionHasErrors('sound_id');
        $this->assertNull($this->in($this->acme, fn () => Setting::get('sounds.hold_music')));
    }

    public function test_settings_belong_to_one_company(): void
    {
        $this->in($this->acme, fn () => Setting::put('recording.retention_days', 30));

        $this->assertSame(30, $this->in($this->acme, fn () => Setting::get('recording.retention_days')));
        $this->assertNull($this->in($this->bolt, fn () => Setting::get('recording.retention_days')));
    }

    public function test_company_data_cannot_be_read_without_a_company(): void
    {
        $this->admin($this->acme);
        $this->tenancy->set(null);

        $this->expectException(NoTenant::class);
        User::query()->count();
    }

    public function test_the_voice_server_config_sees_every_company(): void
    {
        $this->admin($this->acme);
        $this->admin($this->bolt);
        $this->tenancy->set(null);

        $this->assertSame(2, $this->tenancy->everywhere(fn () => User::query()->count()));
    }

    public function test_queued_jobs_run_as_the_company_that_queued_them(): void
    {
        config(['queue.default' => 'database']);
        $this->in($this->bolt, function () {
            RecordTenant::dispatch(); // queued as this statement ends, while Bolt is current
        });
        $this->tenancy->set(null);

        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        $this->assertSame($this->bolt->id, Cache::get('test:job-tenant'));
    }

    public function test_scheduled_tasks_run_for_each_active_company(): void
    {
        $this->bolt->forceFill(['status' => Tenant::STATUS_SUSPENDED])->save();
        $seen = [];
        $this->tenancy->each(function (Tenant $t) use (&$seen) {
            $seen[] = $t->slug;
        });

        $this->assertSame(['acme'], $seen);
    }

    public function test_operator_adds_companies_from_the_command_line(): void
    {
        $this->artisan('tenant:create', ['slug' => 'www', 'name' => 'Nope'])->assertExitCode(2);
        $this->artisan('tenant:create', ['slug' => 'acme', 'name' => 'Duplicate'])->assertExitCode(2);
        $this->artisan('tenant:create', ['slug' => 'crane', 'name' => 'Crane Ltd'])->assertSuccessful();

        $this->artisan('user:create-owner', ['--tenant' => 'crane', '--name' => 'Cat', '--email' => 'cat@crane.test'])
            ->expectsQuestion('Password (12+ characters, letters and numbers)', 'correct-horse-42')
            ->expectsQuestion('Confirm password', 'correct-horse-42')
            ->assertSuccessful();

        $crane = Tenant::query()->firstWhere('slug', 'crane');
        $this->assertSame(['cat@crane.test'], $this->in($crane, fn () => User::query()->pluck('email')->all()));
        $this->assertSame(0, $this->in($this->acme, fn () => User::query()->count()));
    }

    public function test_self_hosted_installs_cannot_add_companies(): void
    {
        config(['tenancy.enabled' => false]);

        $this->artisan('tenant:create', ['slug' => 'crane', 'name' => 'Crane Ltd'])->assertFailed();
    }
}

class RecordTenant implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public function handle(Tenancy $tenancy): void
    {
        Cache::forever('test:job-tenant', $tenancy->current()->id);
    }
}
