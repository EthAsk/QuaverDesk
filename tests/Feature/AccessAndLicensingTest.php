<?php

namespace Tests\Feature;

use App\Domain\Licensing\LicenceCodec;
use App\Domain\Licensing\Plan;
use App\Models\User;
use App\Support\LicenceManager;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessAndLicensingTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_registrant_becomes_owner_and_registration_then_closes(): void
    {
        $this->post('/register', [
            'name' => 'Olive Owner', 'email' => 'olive@example.com',
            'password' => 'correct-horse-42', 'password_confirmation' => 'correct-horse-42',
        ])->assertRedirect('/dashboard');

        $this->assertSame(User::ROLE_OWNER, User::first()->role);

        auth()->logout();
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Eve', 'email' => 'eve@example.com',
            'password' => 'correct-horse-42', 'password_confirmation' => 'correct-horse-42',
        ]);
        $this->assertSame(1, User::count());
    }

    public function test_web_setup_can_be_switched_off_for_production(): void
    {
        config(['voip.web_setup' => false]);

        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Eve', 'email' => 'eve@example.com',
            'password' => 'correct-horse-42', 'password_confirmation' => 'correct-horse-42',
        ])->assertSessionHasErrors('email');
        $this->assertSame(0, User::count());

        $this->artisan('user:create-owner', ['--name' => 'Olive', '--email' => 'olive@example.com'])
            ->expectsQuestion('Password (12+ characters, letters and numbers)', 'correct-horse-42')
            ->expectsQuestion('Confirm password', 'correct-horse-42')
            ->assertSuccessful();
        $this->assertSame(User::ROLE_OWNER, User::first()->role);
    }

    public function test_admin_without_two_factor_is_sent_to_security(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/users')->assertRedirect('/security');
    }

    public function test_plain_user_cannot_reach_admin_pages(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->actingAs($user)->get('/admin/users')->assertForbidden();
    }

    public function test_free_plan_caps_users_at_three(): void
    {
        $owner = User::factory()->owner()->withTwoFactor()->create();
        User::factory()->count(2)->create();

        $this->actingAs($owner)
            ->post('/admin/users', ['name' => 'Fourth', 'email' => 'four@example.com', 'role' => 'user'])
            ->assertSessionHasErrors('email');

        $this->assertSame(3, User::count());
    }

    public function test_valid_licence_unlocks_plan_and_seats(): void
    {
        $keys = LicenceCodec::generateKeyPair();
        config(['voip.licence_public_key' => $keys['public']]);
        $key = LicenceCodec::issue($keys['secret'], 'Acme Ltd', Plan::Pro, 10, new DateTimeImmutable('+1 year'));

        $owner = User::factory()->owner()->withTwoFactor()->create();

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put('/admin/licence', ['key' => $key])
            ->assertSessionHasNoErrors();

        $licence = app(LicenceManager::class)->current();
        $this->assertSame(Plan::Pro, $licence->plan);
        $this->assertSame(10, $licence->maxUsers());
    }

    public function test_forged_licence_is_refused(): void
    {
        config(['voip.licence_public_key' => LicenceCodec::generateKeyPair()['public']]);
        $forged = LicenceCodec::issue(LicenceCodec::generateKeyPair()['secret'], 'Mallory', Plan::Pro, 999, null);
        $owner = User::factory()->owner()->withTwoFactor()->create();

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put('/admin/licence', ['key' => $forged])
            ->assertSessionHasErrors('key');

        $this->assertSame(Plan::Free, app(LicenceManager::class)->current()->plan);
    }

    public function test_softphone_is_blocked_until_terms_are_accepted(): void
    {
        $owner = User::factory()->owner()->withTwoFactor()->create();

        $this->actingAs($owner)->get('/softphone')->assertRedirect(route('setup.acknowledge'));

        $this->acknowledgeTerms($owner);
        $this->actingAs($owner)->get('/softphone')->assertOk();
    }
}
