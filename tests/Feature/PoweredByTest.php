<?php

namespace Tests\Feature;

use App\Domain\Licensing\LicenceCodec;
use App\Domain\Licensing\Plan;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A partner's renamed install says "Powered by QuaverDesk" unless its licence includes own branding. */
class PoweredByTest extends TestCase
{
    use RefreshDatabase;

    private function install(bool $ownBranding): void
    {
        $keys = LicenceCodec::generateKeyPair();
        config(['voip.licence_public_key' => $keys['public']]);
        Setting::put('licence_key', LicenceCodec::issue($keys['secret'], 'Acme Ltd', Plan::Plus, 10, null, ownBranding: $ownBranding));
        $this->app->forgetScopedInstances();
    }

    public function test_a_renamed_install_says_powered_by_quaverdesk(): void
    {
        config(['app.name' => 'Northern Phones']);
        $this->install(false);

        $this->get('/login')->assertSee('Powered by QuaverDesk');
        $this->actingAs(User::factory()->owner()->withTwoFactor()->create())->get('/dashboard')->assertOk()->assertSee('Powered by QuaverDesk');
    }

    public function test_own_branding_or_the_quaverdesk_name_hides_it(): void
    {
        config(['app.name' => 'Northern Phones']);
        $this->install(true);
        $this->get('/login')->assertDontSee('Powered by QuaverDesk');

        config(['app.name' => 'QuaverDesk']);
        $this->install(false);
        $this->get('/login')->assertDontSee('Powered by QuaverDesk');
    }
}
