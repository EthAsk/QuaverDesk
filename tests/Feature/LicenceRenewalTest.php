<?php

namespace Tests\Feature;

use App\Domain\Licensing\LicenceCodec;
use App\Domain\Licensing\Plan;
use App\Licensing\Bands;
use App\Models\Setting;
use App\Models\User;
use App\Support\LicenceManager;
use App\Support\LicenceRenewal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** An install keeps its licence current: daily renewal, and moving up a band when it grows. */
class LicenceRenewalTest extends TestCase
{
    use RefreshDatabase;

    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = LicenceCodec::generateKeyPair();
        config(['voip.licence_public_key' => $this->keys['public']]);
    }

    public function test_bands_and_prices(): void
    {
        $this->assertSame(5, Bands::fitting(1));
        $this->assertSame(10, Bands::fitting(6));
        $this->assertSame(100, Bands::fitting(100));
        $this->assertNull(Bands::fitting(101));
        $this->assertSame(25, Bands::next(10));
        $this->assertSame('11–25', Bands::label(25));
        $this->assertSame('Up to 5', Bands::label(5));
        $this->assertSame(29, Bands::price(Plan::Plus, 10));
        $this->assertSame(290, Bands::price(Plan::Plus, 10, 'year'));
    }

    public function test_adding_the_next_user_offers_the_next_band_and_upgrades_when_agreed(): void
    {
        config(['licensing.server_url' => 'https://licences.example']);
        Setting::put('licence_key', LicenceCodec::issue($this->keys['secret'], 'Acme Ltd', Plan::Plus, 5, new \DateTimeImmutable('+30 days')));
        $owner = User::factory()->owner()->withTwoFactor()->create();
        $this->acknowledgeTerms($owner);
        User::factory()->count(4)->create();
        $bigger = LicenceCodec::issue($this->keys['secret'], 'Acme Ltd', Plan::Plus, 10, new \DateTimeImmutable('+30 days'));
        Http::fake(['licences.example/api/licence/upgrade' => Http::response(['key' => $bigger])]);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);

        $this->get('/admin/users')->assertSee('Move up to 6–10 users: Plus at £29 a month')->assertSee('Upgrade and add user');

        // Not without agreeing to it.
        $this->post('/admin/users', ['name' => 'Six', 'email' => 'six@acme.test', 'role' => 'user'])->assertSessionHasErrors('email');
        Http::assertNothingSent();

        $this->post('/admin/users', ['name' => 'Six', 'email' => 'six@acme.test', 'role' => 'user', 'upgrade' => 1])->assertSessionHasNoErrors();
        $this->assertSame(6, User::query()->count());
        $this->assertSame(10, app(LicenceManager::class)->current()->seats);
    }

    public function test_install_picks_up_renewed_key_and_verifies_it(): void
    {
        $old = LicenceCodec::issue($this->keys['secret'], 'Acme Ltd', Plan::Plus, 5, now()->addDay()->toDateTimeImmutable());
        $new = LicenceCodec::issue($this->keys['secret'], 'Acme Ltd', Plan::Plus, 8, now()->addMonth()->toDateTimeImmutable());
        Setting::put('licence_key', $old);
        config(['licensing.server_url' => 'https://quaverdesk.app']);
        Http::fake(['quaverdesk.app/api/licence/refresh' => Http::response(['key' => $new])]);

        $this->assertStringStartsWith('Renewed', app(LicenceRenewal::class)->check());
        $this->app->forgetScopedInstances();
        $this->assertSame(8, app(LicenceManager::class)->current()->seats);
    }

    public function test_install_refuses_a_key_it_cannot_verify(): void
    {
        $old = LicenceCodec::issue($this->keys['secret'], 'Acme Ltd', Plan::Plus, 5, null);
        Setting::put('licence_key', $old);
        $forged = LicenceCodec::issue(LicenceCodec::generateKeyPair()['secret'], 'Acme Ltd', Plan::Pro, 999, null);
        config(['licensing.server_url' => 'https://quaverdesk.app']);
        Http::fake(['quaverdesk.app/*' => Http::response(['key' => $forged])]);

        $this->assertStringContainsString('failed verification', app(LicenceRenewal::class)->check());
        $this->assertSame($old, Setting::get('licence_key'));
    }
}
