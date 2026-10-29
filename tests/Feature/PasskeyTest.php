<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plays the part of a browser and authenticator: a real P-256 key pair,
 * real attestation and assertion data, checked by the real verifier.
 */
class PasskeyTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://phone.example.test';

    private \OpenSSLAsymmetricKey $key;
    private string $credentialId;

    protected function setUp(): void
    {
        parent::setUp();
        // Fortify copies these into the passkeys config at boot, from APP_URL.
        config([
            'app.url' => self::ORIGIN,
            'passkeys.relying_party_id' => 'phone.example.test',
            'passkeys.allowed_origins' => [self::ORIGIN],
        ]);
        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->credentialId = random_bytes(32);
    }

    public function test_passkeys_are_a_paid_feature(): void
    {
        $this->getJson('/passkeys/login/options')->assertForbidden();
        $this->get('/login')->assertDontSee('Sign in with a passkey');

        $this->usePlan(Plan::Plus);
        $this->getJson('/passkeys/login/options')->assertOk();
        $this->get('/login')->assertSee('Sign in with a passkey');
    }

    public function test_add_a_passkey_then_sign_in_with_it(): void
    {
        $this->usePlan(Plan::Plus);
        $user = User::factory()->create();

        $this->addPasskey($user)->assertOk()->assertJson(['status' => 'passkey-registered']);
        $this->assertSame('Laptop', $user->passkeys()->first()->name);

        auth()->logout();
        $options = $this->getJson('/passkeys/login/options')->assertOk()->json('options');
        $this->assertSame('required', $options['userVerification']);
        $this->assertSame('phone.example.test', $options['rpId']);

        $this->postJson('/passkeys/login', $this->assertion($options['challenge'], 1))
            ->assertOk()->assertJson(['redirect' => url('/dashboard')]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_sign_in_from_another_site_is_refused(): void
    {
        $this->usePlan(Plan::Plus);
        $this->addPasskey(User::factory()->create());
        auth()->logout();

        $challenge = $this->getJson('/passkeys/login/options')->json('options.challenge');
        $this->postJson('/passkeys/login', $this->assertion($challenge, 1, 'https://evilphone.example.test'))
            ->assertStatus(422);
        $this->assertGuest();
    }

    public function test_a_challenge_works_only_once(): void
    {
        $this->usePlan(Plan::Plus);
        $this->addPasskey(User::factory()->create());
        auth()->logout();

        $challenge = $this->getJson('/passkeys/login/options')->json('options.challenge');
        $this->postJson('/passkeys/login', $this->assertion($challenge, 1))->assertOk();
        auth()->logout();

        $this->postJson('/passkeys/login', $this->assertion($challenge, 2))->assertStatus(422);
        $this->assertGuest();
    }

    public function test_signing_in_needs_a_paid_plan_even_with_a_passkey(): void
    {
        $this->usePlan(Plan::Plus);
        $this->addPasskey(User::factory()->create());
        auth()->logout();
        $challenge = $this->getJson('/passkeys/login/options')->json('options.challenge');

        Setting::forget('licence_key'); // licence removed: back to Free
        $this->app->forgetScopedInstances();
        $this->postJson('/passkeys/login', $this->assertion($challenge, 1))->assertForbidden();
        $this->assertGuest();
    }

    public function test_adding_a_passkey_needs_a_confirmed_password(): void
    {
        $this->usePlan(Plan::Plus);
        $this->actingAs(User::factory()->create())->getJson('/user/passkeys/options')->assertStatus(423);
    }

    public function test_people_can_only_remove_their_own_passkeys(): void
    {
        $this->usePlan(Plan::Plus);
        $owner = User::factory()->create();
        $this->addPasskey($owner);
        $id = $owner->passkeys()->first()->id;
        $other = User::factory()->create();

        $this->actingAs($other)->withSession(['auth.password_confirmed_at' => time()])
            ->delete("/user/passkeys/{$id}")->assertForbidden();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->from('/security')->delete("/user/passkeys/{$id}")->assertRedirect('/security');
        $this->assertSame(0, $owner->passkeys()->count());
    }

    // ---- the "browser" -------------------------------------------------------

    private function addPasskey(User $user): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
        $options = $this->getJson('/user/passkeys/options')->assertOk()->json('options');

        $clientData = json_encode(['type' => 'webauthn.create', 'challenge' => $options['challenge'], 'origin' => self::ORIGIN]);
        $details = openssl_pkey_get_details($this->key)['ec'];
        // OpenSSL drops leading zero bytes; COSE needs the full 32-byte coordinates.
        $x = str_pad($details['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['y'], 32, "\0", STR_PAD_LEFT);
        $cose = self::cbor([1 => 2, 3 => -7, -1 => 1, -2 => ['bytes' => $x], -3 => ['bytes' => $y]]);
        $authData = hash('sha256', 'phone.example.test', true)."\x45".pack('N', 0)
            .str_repeat("\0", 16).pack('n', strlen($this->credentialId)).$this->credentialId.$cose;
        $attestation = self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => ['bytes' => $authData]]);

        return $this->postJson('/user/passkeys', ['name' => 'Laptop', 'credential' => [
            'id' => self::b64url($this->credentialId),
            'rawId' => self::b64url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64url($clientData),
                'attestationObject' => self::b64url($attestation),
                'transports' => ['internal'],
            ],
            'clientExtensionResults' => new \stdClass(),
        ]]);
    }

    private function assertion(string $challenge, int $counter, string $origin = self::ORIGIN): array
    {
        $clientData = json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => $origin]);
        $authData = hash('sha256', 'phone.example.test', true)."\x05".pack('N', $counter);
        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return ['credential' => [
            'id' => self::b64url($this->credentialId),
            'rawId' => self::b64url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64url($clientData),
                'authenticatorData' => self::b64url($authData),
                'signature' => self::b64url($signature),
            ],
            'clientExtensionResults' => new \stdClass(),
        ]];
    }

    private static function b64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    /** Minimal CBOR: ints, text, ['bytes' => …] byte strings and maps. */
    private static function cbor(mixed $value): string
    {
        $head = function (int $major, int $n): string {
            if ($n < 24) return chr($major << 5 | $n);
            if ($n < 256) return chr($major << 5 | 24).chr($n);
            return chr($major << 5 | 25).pack('n', $n);
        };

        if (is_int($value)) {
            return $value >= 0 ? $head(0, $value) : $head(1, -1 - $value);
        }
        if (is_string($value)) {
            return $head(3, strlen($value)).$value;
        }
        if (is_array($value) && array_keys($value) === ['bytes']) {
            return $head(2, strlen($value['bytes'])).$value['bytes'];
        }
        $out = $head(5, count($value));
        foreach ($value as $k => $v) {
            $out .= self::cbor($k).self::cbor($v);
        }

        return $out;
    }
}
