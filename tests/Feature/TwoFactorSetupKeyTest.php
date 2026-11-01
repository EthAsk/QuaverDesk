<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** People who can't scan the QR code can type or paste the set-up key instead. */
class TwoFactorSetupKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_key_is_shown_while_setting_up_and_never_once_confirmed(): void
    {
        $user = User::factory()->owner()->create(['email' => 'sam@example.test']);
        $this->acknowledgeTerms($user);
        $secret = (new Google2FA())->generateSecretKey(32);
        $user->forceFill(['two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret)])->save();

        $grouped = trim(chunk_split($secret, 4, ' '));
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get('/security')->assertOk()
            ->assertSee("Can't scan it?", false)
            ->assertSee($grouped)
            ->assertSee('data-copy="'.$secret.'"', false)
            ->assertSee('otpauth://totp/', false)
            ->assertSee('sam@example.test');

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->assertNull($user->fresh()->twoFactorSetupKey());
        $this->actingAs($user->fresh())->withSession(['auth.password_confirmed_at' => time()])->get('/security')->assertOk()
            ->assertDontSee($grouped)->assertDontSee("Can't scan it?", false);
    }
}
