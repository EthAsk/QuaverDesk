<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Sso\SsoSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Single sign-on with Microsoft and Google (Pro, #30). */
class SsoTest extends TestCase
{
    use RefreshDatabase;

    private const DIR = '11111111-2222-3333-4444-555555555555';

    private function pro(): void
    {
        $this->usePlan(Plan::Pro);
    }

    private function configure(): void
    {
        $s = app(SsoSettings::class);
        $s->save('microsoft', true, 'ms-client', 'ms-secret', self::DIR);
        $s->save('google', true, 'g-client', 'g-secret', 'example.co.uk');
    }

    /** Replaces whatever the provider was set to answer (an earlier fake would otherwise win). */
    private function providerAnswers(mixed $response): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => $response]);
    }

    private function jwt(array $claims): string
    {
        $b = fn (array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');

        return $b(['alg' => 'RS256']).'.'.$b($claims).'.sig';
    }

    /** Starts a sign-in and returns [state, nonce] as the provider would hand them back. */
    private function begin(string $provider): array
    {
        $response = $this->get("/sso/{$provider}")->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $q);

        return [$q['state'], $q['nonce'], $q];
    }

    private function claims(string $provider, string $nonce, array $override = []): array
    {
        return $override + ($provider === 'microsoft'
            ? ['iss' => 'https://login.microsoftonline.com/'.self::DIR.'/v2.0', 'aud' => 'ms-client', 'tid' => self::DIR, 'sub' => 'ms-sub-1', 'email' => 'Ann@Example.co.uk']
            : ['iss' => 'https://accounts.google.com', 'aud' => 'g-client', 'hd' => 'example.co.uk', 'email_verified' => true, 'sub' => 'g-sub-1', 'email' => 'ann@example.co.uk'])
            + ['nonce' => $nonce, 'exp' => time() + 600];
    }

    /** Runs a whole sign-in with the provider returning these claims. */
    private function signIn(string $provider, array $override = [], ?string $state = null)
    {
        [$goodState, $nonce] = $this->begin($provider);
        $this->providerAnswers(Http::response(['id_token' => $this->jwt($this->claims($provider, $nonce, $override))]));

        return $this->get("/sso/{$provider}/callback?code=abc&state=".($state ?? $goodState));
    }

    public function test_it_is_offered_only_on_pro_and_only_once_set_up(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('Sign in with Microsoft');
        $this->get('/sso/microsoft')->assertNotFound();

        $this->pro();
        $this->get('/sso/microsoft')->assertNotFound(); // not set up yet

        app(SsoSettings::class)->save('microsoft', true, 'ms-client', 'ms-secret', self::DIR);
        $this->get('/login')->assertSee('Sign in with Microsoft')->assertDontSee('Sign in with Google');

        app(SsoSettings::class)->save('microsoft', false, 'ms-client', null, self::DIR);
        $this->get('/login')->assertDontSee('Sign in with Microsoft');
        $this->get('/sso/microsoft')->assertNotFound();
    }

    public function test_the_redirect_uses_pkce_state_nonce_and_the_companys_own_directory_or_domain(): void
    {
        $this->pro();
        $this->configure();

        [$state, $nonce, $q] = $this->begin('microsoft');
        $this->assertSame('ms-client', $q['client_id']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame(url('/sso/microsoft/callback'), $q['redirect_uri']);
        $this->assertGreaterThan(30, strlen($state));
        $this->assertNotSame($state, $nonce);
        $location = $this->get('/sso/microsoft')->headers->get('Location');
        $this->assertStringStartsWith('https://login.microsoftonline.com/'.self::DIR.'/oauth2/v2.0/authorize?', $location);

        [, , $g] = $this->begin('google');
        $this->assertSame('example.co.uk', $g['hd']);
    }

    public function test_a_known_person_signs_in_and_is_remembered_by_their_provider_identity(): void
    {
        $this->pro();
        $this->configure();
        $ann = User::factory()->create(['email' => 'ann@example.co.uk']);

        $this->signIn('microsoft')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($ann);
        $this->assertSame(['microsoft', 'ms-sub-1'], [$ann->refresh()->sso_provider, $ann->sso_subject]);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/oauth2/v2.0/token') && $r['client_secret'] === 'ms-secret'
            && $r['code'] === 'abc' && strlen($r['code_verifier']) === 64);

        // Next time they're found by that identity even if their email changed.
        auth()->logout();
        $ann->forceFill(['email' => 'ann.new@example.co.uk'])->save();
        $this->signIn('microsoft', ['email' => 'other@example.co.uk'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($ann);
    }

    public function test_google_signs_in_too(): void
    {
        $this->pro();
        $this->configure();
        $ann = User::factory()->create(['email' => 'ann@example.co.uk']);

        $this->signIn('google')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($ann);
    }

    public function test_two_factor_still_applies(): void
    {
        $this->pro();
        $this->configure();
        $admin = User::factory()->owner()->withTwoFactor()->create(['email' => 'ann@example.co.uk']);

        $this->signIn('microsoft')->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->assertSame($admin->id, session('login.id'));
    }

    public function test_an_admin_without_two_factor_is_sent_to_set_it_up(): void
    {
        $this->pro();
        $this->configure();
        User::factory()->owner()->create(['email' => 'ann@example.co.uk']);

        $this->signIn('microsoft')->assertRedirect('/dashboard');
        $this->get('/admin/users')->assertRedirect(route('security'));
    }

    public function test_someone_nobody_added_cannot_sign_in_and_no_account_is_made(): void
    {
        $this->pro();
        $this->configure();
        $before = User::query()->count();

        $this->signIn('microsoft')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame($before, User::query()->count());
    }

    public function test_an_email_already_linked_to_another_identity_cannot_be_taken_over(): void
    {
        $this->pro();
        $this->configure();
        User::factory()->create(['email' => 'ann@example.co.uk', 'sso_provider' => 'microsoft', 'sso_subject' => 'someone-else']);

        $this->signIn('microsoft')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badClaims')]
    public function test_claims_that_do_not_check_out_are_refused(string $provider, array $override): void
    {
        $this->pro();
        $this->configure();
        User::factory()->create(['email' => 'ann@example.co.uk']);

        $this->signIn($provider, $override)->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public static function badClaims(): array
    {
        return [
            'wrong audience' => ['microsoft', ['aud' => 'someone-elses-app']],
            'wrong issuer' => ['microsoft', ['iss' => 'https://login.microsoftonline.com/other/v2.0']],
            'another directory' => ['microsoft', ['tid' => '99999999-2222-3333-4444-555555555555']],
            'expired' => ['microsoft', ['exp' => time() - 3600]],
            'wrong nonce' => ['microsoft', ['nonce' => 'replayed']],
            'no subject' => ['microsoft', ['sub' => '']],
            'no email' => ['microsoft', ['email' => 'not-an-email']],
            'google other domain' => ['google', ['hd' => 'gmail.com']],
            'google personal account' => ['google', ['hd' => null]],
            'google unverified email' => ['google', ['email_verified' => false]],
            'google wrong issuer' => ['google', ['iss' => 'https://evil.example']],
        ];
    }

    public function test_a_wrong_state_a_cancelled_sign_in_and_a_failed_exchange_are_refused(): void
    {
        $this->pro();
        $this->configure();
        User::factory()->create(['email' => 'ann@example.co.uk']);

        $this->signIn('microsoft', [], 'forged-state')->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->begin('microsoft');
        $this->get('/sso/microsoft/callback?error=access_denied')->assertRedirect('/login')->assertSessionHasErrors('email');

        // A callback with no sign-in under way.
        $this->get('/sso/microsoft/callback?code=abc&state=x')->assertRedirect('/login')->assertSessionHasErrors('email');

        [$state] = $this->begin('microsoft');
        $this->providerAnswers(Http::response(['error' => 'invalid_client'], 401));
        $this->get("/sso/microsoft/callback?code=abc&state={$state}")->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();

        // A state is used once.
        [$state, $nonce] = $this->begin('microsoft');
        $this->providerAnswers(Http::response(['id_token' => $this->jwt($this->claims('microsoft', $nonce))]));
        $this->get("/sso/microsoft/callback?code=abc&state={$state}")->assertRedirect('/dashboard');
        auth()->logout();
        $this->get("/sso/microsoft/callback?code=abc&state={$state}")->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_admin_saves_settings_with_the_secret_encrypted_and_never_shown(): void
    {
        $this->pro();
        $owner = User::factory()->owner()->withTwoFactor()->create();
        $this->acknowledgeTerms($owner);

        $this->actingAs($owner)->put('/admin/sso/microsoft', ['enabled' => '1', 'client_id' => 'ms-client', 'client_secret' => 'ms-secret', 'restrict' => strtoupper(self::DIR)])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('ms-secret', (string) Setting::query()->where('key', 'sso')->value('value'));
        $this->assertTrue(app(SsoSettings::class)->ready('microsoft'));
        $this->get('/admin/sso')->assertOk()->assertSee('Saved')->assertDontSee('ms-secret')->assertSee(url('/sso/microsoft/callback'));

        $this->put('/admin/sso/microsoft', ['enabled' => '1', 'client_id' => 'ms-client', 'restrict' => self::DIR]);
        $this->assertSame('ms-secret', app(SsoSettings::class)->get('microsoft')['client_secret']);

        $this->put('/admin/sso/microsoft', ['enabled' => '1', 'client_id' => 'x', 'restrict' => 'contoso'])->assertSessionHasErrors('restrict');
        $this->put('/admin/sso/google', ['enabled' => '1', 'client_id' => 'x', 'client_secret' => 'y', 'restrict' => 'not a domain'])->assertSessionHasErrors('restrict');
        $this->put('/admin/sso/github', ['client_id' => 'x'])->assertNotFound();
    }

    public function test_plus_has_no_sso_settings(): void
    {
        $this->usePlan(Plan::Plus);
        $owner = User::factory()->owner()->withTwoFactor()->create();
        $this->acknowledgeTerms($owner);

        $this->actingAs($owner)->get('/admin/sso')->assertRedirect('/dashboard');
    }
}
